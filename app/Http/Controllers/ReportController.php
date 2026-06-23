<?php

namespace App\Http\Controllers;

use App\Models\ArInvoice;
use App\Models\ArPayment;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function openReceivables(): View
    {
        $invoices = ArInvoice::with('customer')
            ->whereIn('status', ['disetujui', 'selesai'])
            ->get()
            ->filter(fn (ArInvoice $invoice) => $invoice->owing > 0.009);

        $byCustomer = $invoices->groupBy('customer_id')->map(fn ($group) => [
            'customer' => $group->first()->customer,
            'invoices' => $group->sortBy('due_date')->values(),
            'total' => $group->sum('owing'),
        ])->sortBy(fn ($row) => $row['customer']->name)->values();

        $grandTotal = $byCustomer->sum('total');

        return view('reports.open-receivables', compact('byCustomer', 'grandTotal'));
    }

    public function agedReceivables(): View
    {
        $byCustomer = $this->buildAgedReceivables();
        $grandTotal = $this->bucketGrandTotal($byCustomer);

        return view('reports.aged-receivables', compact('byCustomer', 'grandTotal'));
    }

    public function agedReceivablesSummary(): View
    {
        $byCustomer = $this->buildAgedReceivables();
        $grandTotal = $this->bucketGrandTotal($byCustomer);

        return view('reports.aged-receivables-summary', compact('byCustomer', 'grandTotal'));
    }

    /**
     * Dipakai bersama oleh agedReceivables() (detail per invoice) dan
     * agedReceivablesSummary() (rollup per customer saja) — query dan alokasi
     * bucket-nya identik, cuma view yang beda level detailnya.
     */
    private function buildAgedReceivables()
    {
        $invoices = ArInvoice::with('customer', 'lines')
            ->whereIn('status', ['disetujui', 'selesai'])
            ->get()
            ->filter(fn (ArInvoice $invoice) => $invoice->owing > 0.009);

        return $invoices->groupBy('customer_id')->map(function ($group) {
            $buckets = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90plus' => 0];

            foreach ($group as $invoice) {
                $owing = $invoice->owing;
                $age = $invoice->age;

                match (true) {
                    $age === 0 => $buckets['current'] += $owing,
                    $age <= 30 => $buckets['d30'] += $owing,
                    $age <= 60 => $buckets['d60'] += $owing,
                    $age <= 90 => $buckets['d90'] += $owing,
                    default => $buckets['d90plus'] += $owing,
                };
            }

            return [
                'customer' => $group->first()->customer,
                'invoices' => $group->sortBy('due_date')->values(),
                'buckets' => $buckets,
                'total' => array_sum($buckets),
            ];
        })->sortByDesc('total')->values();
    }

    private function bucketGrandTotal($byCustomer): array
    {
        return [
            'current' => $byCustomer->sum('buckets.current'),
            'd30' => $byCustomer->sum('buckets.d30'),
            'd60' => $byCustomer->sum('buckets.d60'),
            'd90' => $byCustomer->sum('buckets.d90'),
            'd90plus' => $byCustomer->sum('buckets.d90plus'),
        ];
    }

    public function arHistory(Request $request): View
    {
        $dateFrom = $request->date('date_from') ?? now()->startOfMonth();
        $dateTo = $request->date('date_to') ?? now()->endOfMonth();

        $invoiceRows = ArInvoice::with('customer')
            ->whereIn('status', ['disetujui', 'selesai'])
            ->whereBetween('invoice_date', [$dateFrom, $dateTo])
            ->get()
            ->map(fn (ArInvoice $i) => [
                'customer_id' => $i->customer_id,
                'customer' => $i->customer,
                'type' => 'invoice',
                'no' => $i->invoice_no,
                'date' => $i->invoice_date,
                'amount' => $i->total,
            ]);

        $paymentRows = ArPayment::with('customer')
            ->whereIn('status', ['disetujui', 'selesai'])
            ->whereBetween('payment_date', [$dateFrom, $dateTo])
            ->get()
            ->map(fn (ArPayment $p) => [
                'customer_id' => $p->customer_id,
                'customer' => $p->customer,
                'type' => 'payment',
                'no' => $p->payment_no,
                'date' => $p->payment_date,
                'amount' => $p->amount,
            ]);

        $rows = $invoiceRows->concat($paymentRows);

        $byCustomer = $rows->groupBy('customer_id')->map(fn ($group) => [
            'customer' => $group->first()['customer'],
            'rows' => $group->sortBy('date')->values(),
        ])->sortBy(fn ($row) => $row['customer']->name)->values();

        $summary = [
            'invoice_count' => $invoiceRows->count(),
            'invoice_total' => $invoiceRows->sum('amount'),
            'payment_count' => $paymentRows->count(),
            'payment_total' => $paymentRows->sum('amount'),
        ];

        return view('reports.ar-history', compact('byCustomer', 'summary', 'dateFrom', 'dateTo'));
    }
}
