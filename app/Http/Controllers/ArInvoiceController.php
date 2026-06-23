<?php

namespace App\Http\Controllers;

use App\Models\ArInvoice;
use App\Models\ArInvoiceLine;
use App\Models\Customer;
use App\Models\GlJournal;
use App\Models\GlSetting;
use App\Models\InvStockBalance;
use App\Models\InvStockMovement;
use App\Models\Item;
use App\Models\SalesOrder;
use App\Models\Shipto;
use App\Models\Warehouse;
use App\Services\AutoNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ArInvoiceController extends Controller
{
    private const PPN_RATE = 0.11;

    public function index(Request $request): View
    {
        $invoices = ArInvoice::with('customer')
            ->when($request->search, fn ($q, $search) => $q->where('invoice_no', 'like', "%{$search}%"))
            ->orderByDesc('invoice_date')
            ->paginate(20)
            ->withQueryString();

        return view('ar-invoices.index', compact('invoices'));
    }

    public function createPicker(): View
    {
        $eligibleSalesOrders = SalesOrder::with('customer')
            ->where('status', 'selesai')
            ->whereDoesntHave('arInvoice')
            ->orderByDesc('order_date')
            ->get();

        return view('ar-invoices.create-picker', compact('eligibleSalesOrders'));
    }

    public function createFromSalesOrder(SalesOrder $salesOrder): RedirectResponse
    {
        if ($salesOrder->status !== 'selesai') {
            return back()->with('error', 'Sales Order belum selesai (fully shipped).');
        }

        if (ArInvoice::where('sls_sales_order_id', $salesOrder->id)->exists()) {
            return back()->with('error', 'Sales Order ini sudah pernah ditagih.');
        }

        $invoice = DB::transaction(function () use ($salesOrder) {
            $invoice = ArInvoice::create([
                'invoice_no' => app(AutoNumberService::class)->generate('ar_invoice'),
                'customer_id' => $salesOrder->customer_id,
                'shipto_id' => $salesOrder->shipto_id,
                'warehouse_id' => $salesOrder->warehouse_id,
                'po_number' => $salesOrder->po_number,
                'sls_sales_order_id' => $salesOrder->id,
                'invoice_date' => now()->toDateString(),
                'term_days' => $salesOrder->customer->term_days ?? 0,
                'due_date' => now()->addDays($salesOrder->customer->term_days ?? 0)->toDateString(),
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            $invoice->lines()->createMany(
                $salesOrder->lines->map(fn ($line) => [
                    'item_id' => $line->item_id,
                    'qty' => $line->qty,
                    'unit_price' => $line->unit_price,
                    'item_override_description' => $line->item_override_description,
                ])->all()
            );

            $this->recalculateTax($invoice);

            return $invoice;
        });

        return redirect()->route('ar-invoices.edit', $invoice)->with('success', 'Invoice dibuat dari Sales Order '.$salesOrder->so_no.'. Silakan tinjau sebelum diajukan.');
    }

    public function create(): View
    {
        return view('ar-invoices.form', [
            'invoice' => new ArInvoice(),
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(),
            'shiptos' => Shipto::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'items' => Item::where('is_active', true)->where('is_sold', true)->orderBy('description')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $invoice = DB::transaction(function () use ($data) {
            $invoice = ArInvoice::create([
                'invoice_no' => app(AutoNumberService::class)->generate('ar_invoice'),
                'customer_id' => $data['customer_id'],
                'shipto_id' => $data['shipto_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'po_number' => $data['po_number'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'term_days' => (int) ($data['term_days'] ?? 0),
                'due_date' => $data['due_date'] ?? \Carbon\Carbon::parse($data['invoice_date'])->addDays((int) ($data['term_days'] ?? 0))->toDateString(),
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            $invoice->lines()->createMany($data['lines']);
            $this->recalculateTax($invoice);

            return $invoice;
        });

        return redirect()->route('ar-invoices.show', $invoice)->with('success', 'Invoice berhasil dibuat.');
    }

    public function show(ArInvoice $invoice): View
    {
        $invoice->load(['customer', 'shipto', 'warehouse', 'salesOrder', 'lines.item', 'allocations.payment', 'creditNotes', 'createdBy', 'approvedBy']);

        return view('ar-invoices.show', compact('invoice'));
    }

    public function edit(ArInvoice $invoice): View
    {
        abort_if($invoice->status !== 'draft', 403, 'Hanya invoice draft yang bisa diedit.');

        return view('ar-invoices.form', [
            'invoice' => $invoice->load('lines'),
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(),
            'shiptos' => Shipto::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'items' => Item::where('is_active', true)->where('is_sold', true)->orderBy('description')->get(),
        ]);
    }

    public function update(Request $request, ArInvoice $invoice): RedirectResponse
    {
        abort_if($invoice->status !== 'draft', 403, 'Hanya invoice draft yang bisa diedit.');

        $data = $this->validateData($request);

        DB::transaction(function () use ($invoice, $data) {
            $invoice->update([
                'customer_id' => $data['customer_id'],
                'shipto_id' => $data['shipto_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'po_number' => $data['po_number'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'term_days' => (int) ($data['term_days'] ?? 0),
                'due_date' => $data['due_date'] ?? \Carbon\Carbon::parse($data['invoice_date'])->addDays((int) ($data['term_days'] ?? 0))->toDateString(),
                'notes' => $data['notes'] ?? null,
                'updated_by' => Auth::id(),
            ]);

            $invoice->lines()->delete();
            $invoice->lines()->createMany($data['lines']);
            $this->recalculateTax($invoice);
        });

        return redirect()->route('ar-invoices.show', $invoice)->with('success', 'Invoice berhasil diperbarui.');
    }

    public function destroy(ArInvoice $invoice): RedirectResponse
    {
        abort_if($invoice->status !== 'draft', 403, 'Hanya invoice draft yang bisa dihapus.');

        $invoice->delete();

        return redirect()->route('ar-invoices.index')->with('success', 'Invoice berhasil dihapus.');
    }

    public function submit(ArInvoice $invoice): RedirectResponse
    {
        abort_if($invoice->status !== 'draft', 403);

        $invoice->update(['status' => 'diajukan', 'updated_by' => Auth::id()]);

        return back()->with('success', 'Invoice diajukan untuk approval.');
    }

    public function approve(ArInvoice $invoice): RedirectResponse
    {
        abort_if($invoice->status !== 'diajukan', 403);

        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'disetujui', 'approved_by' => Auth::id(), 'approved_at' => now()]);

            if ($invoice->warehouse_id) {
                $invoice->load('lines.item.itemType');
                foreach ($invoice->lines as $line) {
                    $this->moveStockOut($invoice, $line);
                }
            }

            $this->postInvoiceJournal($invoice);
        });

        return back()->with('success', 'Invoice disetujui dan stok telah diperbarui.');
    }

    /**
     * Dr AR Control (total) = Cr Sales per line + Cr PPN Keluaran (flat rate, lihat
     * recalculateTax()). Untuk line item inventory, tambahan Dr COGS / Cr Persediaan
     * diambil dari movement yang baru dibuat moveStockOut() (unit_cost weighted-average
     * SAAT itu, bukan dihitung ulang) supaya HPP yang diposting persis sama dengan stok
     * yang benar-benar dikurangi.
     */
    private function postInvoiceJournal(ArInvoice $invoice): void
    {
        $invoice->load('lines.item.itemType');

        $arControlId = GlSetting::where('key', 'ar_control')->first()?->gl_account_id;
        if (! $arControlId) {
            throw new \RuntimeException('GL Account untuk AR Control belum diatur di GL Settings.');
        }

        $lines = [];

        foreach ($invoice->lines as $line) {
            $salesAccountId = $line->item->sales_gl_account_id;
            if (! $salesAccountId) {
                throw new \RuntimeException("Item {$line->item->description} belum punya GL Account Penjualan, lengkapi dulu di master item.");
            }

            $lines[] = [
                'gl_account_id' => $salesAccountId,
                'debit' => 0,
                'credit' => round($line->amount, 2),
                'description' => $line->display_description,
            ];

            if ($invoice->warehouse_id && $line->item->itemType?->is_inventory) {
                $movement = InvStockMovement::where('source_type', 'ar_invoice')
                    ->where('source_id', $invoice->id)
                    ->where('item_id', $line->item_id)
                    ->latest('id')
                    ->first();

                if ($movement) {
                    $cogsAccountId = $line->item->cogs_gl_account_id;
                    $inventoryAccountId = $line->item->inventory_gl_account_id;
                    if (! $cogsAccountId || ! $inventoryAccountId) {
                        throw new \RuntimeException("Item {$line->item->description} belum punya GL Account COGS/Persediaan, lengkapi dulu di master item.");
                    }

                    $cogsAmount = round(abs($movement->qty) * $movement->unit_cost, 2);

                    $lines[] = ['gl_account_id' => $cogsAccountId, 'debit' => $cogsAmount, 'credit' => 0, 'description' => 'HPP - '.$line->display_description];
                    $lines[] = ['gl_account_id' => $inventoryAccountId, 'debit' => 0, 'credit' => $cogsAmount, 'description' => 'Persediaan - '.$line->display_description];
                }
            }
        }

        if ($invoice->ppn_amount > 0) {
            $ppnAccountId = GlSetting::where('key', 'ppn_keluaran')->first()?->gl_account_id;
            if (! $ppnAccountId) {
                throw new \RuntimeException('GL Account untuk PPN Keluaran belum diatur di GL Settings.');
            }

            $lines[] = ['gl_account_id' => $ppnAccountId, 'debit' => 0, 'credit' => round($invoice->ppn_amount, 2), 'description' => 'PPN Keluaran'];
        }

        $lines[] = ['gl_account_id' => $arControlId, 'debit' => round($invoice->total, 2), 'credit' => 0, 'description' => 'AR - '.$invoice->invoice_no];

        GlJournal::postBalanced([
            'journal_date' => $invoice->invoice_date,
            'description' => 'AR Invoice '.$invoice->invoice_no,
            'source_type' => 'ar_invoice',
            'source_id' => $invoice->id,
            'created_by' => Auth::id(),
        ], $lines);
    }

    /**
     * Catat pergerakan stok keluar ke tabel milik app `inv` (inv_stock_movements/inv_stock_balances).
     * Hanya untuk item is_inventory; invoice tanpa warehouse (billing jasa) diabaikan sama sekali
     * (sudah dicek di approve() sebelum method ini dipanggil). Cost weighted-average TIDAK diubah
     * di sini — itu cuma berubah saat barang MASUK (lihat prc\ReceiptController::moveStockIn()).
     */
    private function moveStockOut(ArInvoice $invoice, ArInvoiceLine $line): void
    {
        if (! $line->item?->itemType?->is_inventory) {
            return;
        }

        $balance = InvStockBalance::firstOrCreate(
            ['item_id' => $line->item_id, 'warehouse_id' => $invoice->warehouse_id],
            ['qty_on_hand' => 0, 'unit_cost' => 0]
        );

        InvStockMovement::create([
            'item_id' => $line->item_id,
            'warehouse_id' => $invoice->warehouse_id,
            'qty' => -$line->qty,
            'type' => 'sale',
            'unit_cost' => $balance->unit_cost ?: $line->item->unit_cost,
            'source_type' => 'ar_invoice',
            'source_id' => $invoice->id,
            'moved_at' => now(),
        ]);

        $balance->decrement('qty_on_hand', $line->qty);
    }

    public function reject(ArInvoice $invoice): RedirectResponse
    {
        abort_if($invoice->status !== 'diajukan', 403);

        $invoice->update(['status' => 'ditolak', 'approved_by' => Auth::id(), 'approved_at' => now()]);

        return back()->with('success', 'Invoice ditolak.');
    }

    public function markPrinted(ArInvoice $invoice): RedirectResponse
    {
        $invoice->update(['printed' => true, 'updated_by' => Auth::id()]);

        return back()->with('success', 'Invoice ditandai sudah dicetak.');
    }

    private function recalculateTax(ArInvoice $invoice): void
    {
        $invoice->load('lines');
        $subtotal = $invoice->subtotal;

        $invoice->update([
            'dpp_amount' => $subtotal,
            'ppn_amount' => round($subtotal * self::PPN_RATE, 2),
        ]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'shipto_id' => 'nullable|exists:shiptos,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'po_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'term_days' => 'nullable|integer|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'required|exists:items,id',
            'lines.*.qty' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.item_override_description' => 'nullable|string|max:255',
        ]);
    }
}
