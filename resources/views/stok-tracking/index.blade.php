@extends('layouts.app')

@section('title', 'Tracking Stok')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Tracking Stok</h2>
            <p class="mt-1 text-body-md text-secondary">Stok yang masih tersisa dari batch pembelian, diurutkan dari yang paling tua (FIFO)</p>
        </div>
    </div>

    <!-- Ringkasan Total Stok (luar filter) -->
    <div class="relative mb-4 flex h-24 items-center justify-between overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest p-4">
        <div class="absolute -bottom-3 -right-3 opacity-5">
            <span class="material-symbols-outlined text-7xl">inventory</span>
        </div>
        <div>
            <h3 class="text-label-caps font-bold uppercase tracking-wider text-secondary">Total Stok Keseluruhan</h3>
            <div class="mt-1 text-headline-md font-bold text-primary">{{ number_format($totalStok) }} item dari {{ number_format($totalSku) }} SKU</div>
        </div>
        <span class="text-body-sm text-secondary">Semua barang dengan stok > 0</span>
    </div>

    <!-- Filter Bulan -->
    <div class="card">
        <form method="GET" action="{{ route('stok-tracking.index') }}" class="flex flex-wrap items-center gap-3 p-3">
            <span class="text-body-sm font-medium text-secondary">Filter Bulan Pembelian:</span>
            @foreach ($availableMonths as $m)
                <label class="flex items-center gap-1.5 rounded-lg border border-outline-variant px-3 py-1.5 text-body-sm">
                    <input type="checkbox" name="months[]" value="{{ $m }}"
                        @checked(in_array($m, $selectedMonths))
                        class="rounded border-outline-variant text-primary focus:ring-primary">
                    {{ \Carbon\Carbon::createFromFormat('Y-m', $m)->translatedFormat('F Y') }}
                </label>
            @endforeach
            <button class="btn btn-primary btn-sm">
                <span class="material-symbols-outlined text-[16px]">filter_alt</span> Terapkan
            </button>
            <a href="{{ route('stok-tracking.index') }}" class="btn btn-secondary btn-sm">Reset</a>
        </form>
    </div>

    <!-- Tabel per Bulan -->
    @forelse ($grouped as $month => $rows)
        @php($monthLabel = \Carbon\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y'))
        @php($monthTotal = $rows->sum('qty_sisa'))
        @php($monthSku = $rows->pluck('barang.id')->unique()->count())

        <div class="card mb-4 overflow-hidden">
            <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 px-4 py-2.5">
                <h3 class="text-headline-sm">
                    {{ $monthLabel }}
                    <span class="ml-2 text-body-sm text-secondary">{{ number_format($monthTotal) }} item tersisa dari {{ $monthSku }} SKU</span>
                </h3>
                <span class="text-body-sm font-semibold text-primary">{{ rupiah(0) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Nama Barang</th>
                            <th>Kode</th>
                            <th>Sumber</th>
                            <th>Tanggal Masuk</th>
                            <th class="text-right">Qty Awal</th>
                            <th class="text-right">Sisa</th>
                            <th class="text-right">Umur</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td class="font-medium">{{ $r['barang']->nama }}</td>
                                <td class="mono text-secondary">{{ $r['barang']->kode_barang }}</td>
                                <td>
                                    @if ($r['jenis'] === 'masuk_pembelian' && $r['invoice_id'])
                                        <span class="badge badge-blue">Pembelian</span>
                                    @elseif ($r['jenis'] === 'stok_awal')
                                        <span class="badge badge-slate">Stok Awal</span>
                                    @else
                                        <span class="badge badge-amber">Penyesuaian</span>
                                    @endif
                                </td>
                                <td>{{ $r['tanggal']->format('d/m/Y H:i') }}</td>
                                <td class="mono text-right">{{ number_format($r['qty_awal']) }}</td>
                                <td class="mono text-right font-bold {{ $r['qty_sisa'] > 0 ? 'text-hutang-amber' : 'text-secondary' }}">
                                    {{ number_format($r['qty_sisa']) }}
                                </td>
                                <td class="mono text-right text-secondary">{{ $r['umur_hari'] }} hari</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card p-10 text-center text-secondary">
            Tidak ada stok tersisa dari batch pembelian terfilter.
        </div>
    @endforelse
@endsection
