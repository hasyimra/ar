<?php

namespace App\Http\Controllers;

use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\GlJournal;
use App\Models\GlSetting;
use App\Services\AutoNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ArPaymentController extends Controller
{
    public function index(): View
    {
        $payments = ArPayment::with('customer')->orderByDesc('payment_date')->paginate(20);

        return view('ar-payments.index', compact('payments'));
    }

    public function create(Request $request): View
    {
        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $selectedCustomerId = $request->integer('customer_id') ?: null;

        $outstandingInvoices = collect();
        if ($selectedCustomerId) {
            $outstandingInvoices = ArInvoice::where('customer_id', $selectedCustomerId)
                ->whereIn('status', ['disetujui', 'selesai'])
                ->with('lines')
                ->get()
                ->filter(fn (ArInvoice $invoice) => $invoice->owing > 0.009)
                ->values();
        }

        return view('ar-payments.create', [
            'customers' => $customers,
            'banks' => Bank::where('is_active', true)->orderBy('name')->get(),
            'glAccounts' => GlAccount::where('is_active', true)->orderBy('code')->get(),
            'selectedCustomerId' => $selectedCustomerId,
            'outstandingInvoices' => $outstandingInvoices,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'bank_id' => 'nullable|exists:banks,id',
            'reference_no' => 'nullable|string|max:50',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'allocations' => 'required|array|min:1',
            'allocations.*.ar_invoice_id' => 'required|exists:ar_invoices,id',
            'allocations.*.amount' => 'nullable|numeric|min:0',
            'allocations.*.disc_taken_amount' => 'nullable|numeric|min:0',
            'allocations.*.write_off_amount' => 'nullable|numeric|min:0',
            'allocations.*.write_off_gl_account_id' => 'nullable|exists:gl_accounts,id',
        ]);

        $allocations = collect($data['allocations'])
            ->map(fn ($a) => [
                'ar_invoice_id' => $a['ar_invoice_id'],
                'amount' => $a['amount'] ?? 0,
                'disc_taken_amount' => $a['disc_taken_amount'] ?? 0,
                'write_off_amount' => $a['write_off_amount'] ?? 0,
                'write_off_gl_account_id' => ($a['write_off_amount'] ?? 0) > 0 ? ($a['write_off_gl_account_id'] ?? null) : null,
            ])
            ->filter(fn ($a) => ($a['amount'] + $a['disc_taken_amount'] + $a['write_off_amount']) > 0)
            ->values();

        if ($allocations->isEmpty()) {
            return back()->withInput()->with('error', 'Minimal satu invoice harus dialokasikan.');
        }

        // Hanya porsi "amount" (tunai/transfer) yang harus dicocokkan ke jumlah pembayaran —
        // disc_taken/write_off mengurangi owing di sisi invoice, bukan bagian dari uang yang
        // benar-benar diterima di kas/bank, jadi tidak ikut dibandingkan ke $data['amount'].
        $totalCashAllocated = $allocations->sum('amount');
        if ($totalCashAllocated > $data['amount'] + 0.01) {
            return back()->withInput()->with('error', 'Total alokasi tunai tidak boleh melebihi jumlah pembayaran.');
        }

        $payment = DB::transaction(function () use ($data, $allocations) {
            $payment = ArPayment::create([
                'payment_no' => app(AutoNumberService::class)->generate('ar_payment'),
                'customer_id' => $data['customer_id'],
                'bank_id' => $data['bank_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            $payment->allocations()->createMany($allocations->all());

            return $payment;
        });

        return redirect()->route('ar-payments.show', $payment)->with('success', 'Pembayaran berhasil dicatat.');
    }

    public function show(ArPayment $payment): View
    {
        $payment->load(['customer', 'bank', 'allocations.invoice', 'createdBy', 'approvedBy']);

        return view('ar-payments.show', compact('payment'));
    }

    public function submit(ArPayment $payment): RedirectResponse
    {
        abort_if($payment->status !== 'draft', 403);

        $payment->update(['status' => 'diajukan', 'updated_by' => Auth::id()]);

        return back()->with('success', 'Pembayaran diajukan untuk approval.');
    }

    public function approve(ArPayment $payment): RedirectResponse
    {
        abort_if($payment->status !== 'diajukan', 403);

        DB::transaction(function () use ($payment) {
            $payment->update(['status' => 'disetujui', 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->postPaymentJournal($payment);
        });

        return back()->with('success', 'Pembayaran disetujui.');
    }

    /**
     * Dr Bank (porsi tunai) + Dr Diskon AR (disc_taken) + Dr akun write-off pilihan user
     * per alokasi = Cr AR Control (jumlah ketiganya — balance by construction, sama persis
     * cara owing invoice berkurang).
     */
    private function postPaymentJournal(ArPayment $payment): void
    {
        $payment->load('allocations', 'bank');

        $arControlId = GlSetting::where('key', 'ar_control')->first()?->gl_account_id;
        if (! $arControlId) {
            throw new \RuntimeException('GL Account untuk AR Control belum diatur di GL Settings.');
        }

        $lines = [];

        $cashAmount = round((float) $payment->allocations->sum('amount'), 2);
        if ($cashAmount > 0) {
            $bankAccountId = $payment->bank?->gl_account_id;
            if (! $bankAccountId) {
                throw new \RuntimeException('Bank '.($payment->bank->name ?? '(tidak dipilih)').' belum punya GL Account, lengkapi dulu di master bank.');
            }

            $lines[] = ['gl_account_id' => $bankAccountId, 'debit' => $cashAmount, 'credit' => 0, 'description' => 'Pembayaran '.$payment->payment_no];
        }

        $discTotal = round((float) $payment->allocations->sum('disc_taken_amount'), 2);
        if ($discTotal > 0) {
            $discAccountId = GlSetting::where('key', 'ar_discount')->first()?->gl_account_id;
            if (! $discAccountId) {
                throw new \RuntimeException('GL Account untuk Diskon AR belum diatur di GL Settings.');
            }

            $lines[] = ['gl_account_id' => $discAccountId, 'debit' => $discTotal, 'credit' => 0, 'description' => 'Diskon pelunasan - '.$payment->payment_no];
        }

        foreach ($payment->allocations as $allocation) {
            if ($allocation->write_off_amount <= 0) {
                continue;
            }

            if (! $allocation->write_off_gl_account_id) {
                throw new \RuntimeException('Alokasi write-off pada pembayaran '.$payment->payment_no.' belum punya GL Account.');
            }

            $lines[] = [
                'gl_account_id' => $allocation->write_off_gl_account_id,
                'debit' => round($allocation->write_off_amount, 2),
                'credit' => 0,
                'description' => 'Write-off invoice #'.$allocation->ar_invoice_id,
            ];
        }

        $totalCredit = round(
            $cashAmount + $discTotal + (float) $payment->allocations->sum('write_off_amount'),
            2
        );

        $lines[] = ['gl_account_id' => $arControlId, 'debit' => 0, 'credit' => $totalCredit, 'description' => 'AR - '.$payment->payment_no];

        GlJournal::postBalanced([
            'journal_date' => $payment->payment_date,
            'description' => 'AR Payment '.$payment->payment_no,
            'source_type' => 'ar_payment',
            'source_id' => $payment->id,
            'created_by' => Auth::id(),
        ], $lines);
    }

    public function reject(ArPayment $payment): RedirectResponse
    {
        abort_if($payment->status !== 'diajukan', 403);

        $payment->update(['status' => 'ditolak', 'approved_by' => Auth::id(), 'approved_at' => now()]);

        return back()->with('success', 'Pembayaran ditolak.');
    }

    public function markReconciled(ArPayment $payment): RedirectResponse
    {
        $payment->update(['reconciled' => true, 'updated_by' => Auth::id()]);

        return back()->with('success', 'Pembayaran ditandai sudah direkonsiliasi dengan rekening bank.');
    }

    public function destroy(ArPayment $payment): RedirectResponse
    {
        abort_if($payment->status !== 'draft', 403, 'Hanya pembayaran draft yang bisa dihapus.');

        $payment->delete();

        return redirect()->route('ar-payments.index')->with('success', 'Pembayaran berhasil dihapus.');
    }
}
