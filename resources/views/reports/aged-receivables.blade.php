@extends('layouts.admin')

@section('title', 'Aged Receivables')
@section('breadcrumb', 'Aged Receivables')

@section('content')
    <div class="card">
        <div class="card-body">
            <h5 class="mb-3">Aged Receivables</h5>
            @forelse($byCustomer as $row)
                <div class="mb-4">
                    <h6 class="mb-2">{{ $row['customer']->name }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>No. Invoice</th>
                                    <th>Tanggal</th>
                                    <th>Umur (Hari)</th>
                                    <th class="text-end">Current</th>
                                    <th class="text-end">1-30 Hari</th>
                                    <th class="text-end">31-60 Hari</th>
                                    <th class="text-end">61-90 Hari</th>
                                    <th class="text-end">&gt;90 Hari</th>
                                    <th class="text-end">Owing</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($row['invoices'] as $invoice)
                                    @php
                                        $age = $invoice->age;
                                        $bucket = match (true) {
                                            $age === 0 => 'current',
                                            $age <= 30 => 'd30',
                                            $age <= 60 => 'd60',
                                            $age <= 90 => 'd90',
                                            default => 'd90plus',
                                        };
                                    @endphp
                                    <tr>
                                        <td>{{ $invoice->invoice_no }}</td>
                                        <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                        <td>{{ $age }}</td>
                                        <td class="text-end">{{ $bucket === 'current' ? number_format($invoice->owing, 2) : '-' }}</td>
                                        <td class="text-end">{{ $bucket === 'd30' ? number_format($invoice->owing, 2) : '-' }}</td>
                                        <td class="text-end">{{ $bucket === 'd60' ? number_format($invoice->owing, 2) : '-' }}</td>
                                        <td class="text-end">{{ $bucket === 'd90' ? number_format($invoice->owing, 2) : '-' }}</td>
                                        <td class="text-end {{ $bucket === 'd90plus' ? 'text-danger fw-bold' : '' }}">{{ $bucket === 'd90plus' ? number_format($invoice->owing, 2) : '-' }}</td>
                                        <td class="text-end fw-bold">{{ number_format($invoice->owing, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="3">Subtotal Customer</td>
                                    <td class="text-end">{{ number_format($row['buckets']['current'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['buckets']['d30'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['buckets']['d60'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['buckets']['d90'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['buckets']['d90plus'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['total'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @empty
                <p class="text-center text-muted">Tidak ada piutang outstanding.</p>
            @endforelse

            @if($byCustomer->isNotEmpty())
                <div class="table-responsive">
                    <table class="table">
                        <tfoot>
                            <tr class="fw-bold border-top border-2">
                                <td colspan="3">Grand Total</td>
                                <td class="text-end">{{ number_format($grandTotal['current'], 2) }}</td>
                                <td class="text-end">{{ number_format($grandTotal['d30'], 2) }}</td>
                                <td class="text-end">{{ number_format($grandTotal['d60'], 2) }}</td>
                                <td class="text-end">{{ number_format($grandTotal['d90'], 2) }}</td>
                                <td class="text-end">{{ number_format($grandTotal['d90plus'], 2) }}</td>
                                <td class="text-end">{{ number_format(array_sum($grandTotal), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
