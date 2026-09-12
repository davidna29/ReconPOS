@extends('layouts.app')

@section('title', 'Riwayat Pembelian — ' . $supplier->nama)

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Riwayat Pembelian: {{ $supplier->nama }}</h2>
            <p class="page-head-subtitle">{{ $supplier->kontak }} · Total hutang berjalan: <span class="font-bold text-hutang-amber">{{ rupiah($supplier->totalHutangBerjalan()) }}</span></p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pembelian.create') }}?supplier_id={{ $supplier->id }}" class="btn btn-primary btn-sm shadow-sm">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">add</span> Pembelian Baru
            </a>
            <a href="{{ route('supplier.index') }}" class="btn btn-secondary btn-sm">Kembali</a>
        </div>
    </div>

    <div class="card animate-rise overflow-hidden" style="--rise-delay:.08s">
        <div class="flex items-center justify-between border-b border-outline-variant p-3">
            <form method="GET" class="flex items-center gap-2">
                <select name="status" class="input w-auto" onchange="this.form.submit()">
                    <option value="">Semua status</option>
                    <option value="lunas" @selected(request('status') === 'lunas')>Lunas</option>
                    <option value="belum_lunas" @selected(request('status') === 'belum_lunas')>Belum Lunas</option>
                </select>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl tbl-cards">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Invoice</th>
                        <th>Tanggal Transaksi</th>
                        <th class="text-right">Total</th>
                        <th>Jatuh Tempo</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="rise-rows">
                    @forelse ($invoices as $i => $inv)
                        <tr @class(['row-overdue' => $inv->status === 'belum_lunas' && $inv->jatuh_tempo && $inv->jatuh_tempo->isPast()])>
                            <td data-label="No" class="text-secondary">{{ $invoices->firstItem() + $i }}</td>
                            <td data-label="Invoice" class="mono text-secondary">{{ $inv->no_invoice }}</td>
                            <td data-label="Tanggal">{{ $inv->tanggal_transaksi->format('d/m/Y') }}</td>
                            <td data-label="Total" class="mono text-right">{{ rupiah($inv->total_setelah_diskon) }}</td>
                            <td data-label="Jatuh Tempo">{{ $inv->jatuh_tempo?->format('d/m/Y') ?? '-' }}</td>
                            <td data-label="Status">
                                @if ($inv->status === 'lunas')
                                    <x-badge color="green">Lunas</x-badge>
                                @else
                                    <x-badge color="amber">Hutang</x-badge>
                                @endif
                            </td>
                            <td data-label="Aksi" class="text-right">
                                <a href="{{ route('pembelian.show', $inv) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Detail">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">visibility</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-secondary">Belum ada pembelian dari supplier ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $invoices->withQueryString()->links() }}
        </div>
    </div>
@endsection
