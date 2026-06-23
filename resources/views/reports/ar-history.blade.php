@extends('layouts.admin')

@section('title', 'AR History')
@section('breadcrumb', 'AR History')

@section('content')
    <div class="card">
        <div class="card-body">
            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-3">
                    <input type="date" name="date_from" class="form-control" value="{{ $dateFrom->format('Y-m-d') }}">
                </div>
                <div class="col-md-3">
                    <input type="date" name="date_to" class="form-control" value="{{ $dateTo->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-secondary">Filter</button>
                </div>
            </form>

            @forelse($byCustomer as $row)
                <div class="mb-4">
                    <h6 class="mb-2">{{ $row['customer']->name }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Tipe</th>
                                    <th>No.</th>
                                    <th>Tanggal</th>
                                    <th class="text-end">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($row['rows'] as $r)
                                    <tr>
                                        <td>
                                            <span class="badge {{ $r['type'] === 'invoice' ? 'bg-info' : 'bg-success' }}">
                                                {{ $r['type'] === 'invoice' ? 'Invoice' : 'Pembayaran' }}
                                            </span>
                                        </td>
                                        <td>{{ $r['no'] }}</td>
                                        <td>{{ $r['date']->format('d/m/Y') }}</td>
                                        <td class="text-end">{{ number_format($r['amount'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <p class="text-center text-muted">Tidak ada transaksi di rentang tanggal ini.</p>
            @endforelse

            @if($byCustomer->isNotEmpty())
                <div class="border-top border-2 pt-2 text-end">
                    <div>{{ $summary['invoice_count'] }} invoice — {{ number_format($summary['invoice_total'], 2) }}</div>
                    <div>{{ $summary['payment_count'] }} pembayaran — {{ number_format($summary['payment_total'], 2) }}</div>
                </div>
            @endif
        </div>
    </div>
@endsection
