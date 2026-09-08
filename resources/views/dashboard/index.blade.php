@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Overview</h2>
            <p class="mt-1 text-body-md text-secondary">Periode aktif: <span class="font-semibold text-primary">{{ $labelPeriode }}</span></p>
        </div>

        <!-- Pemilih periode kartu omset (bulan / tahun / rentang bebas) -->
        <form method="GET" action="{{ $r('dashboard') }}" class="flex flex-wrap items-end gap-2">
            <div>
                <label class="label mb-0.5">Bulan</label>
                <input type="month" name="bulan" value="{{ request('bulan') }}" class="input w-40">
            </div>
            <div>
                <label class="label mb-0.5">Tahun</label>
                <input type="number" name="tahun" min="2000" max="2099" value="{{ request('tahun') }}" placeholder="{{ now()->year }}" class="input w-24">
            </div>
            <div>
                <label class="label mb-0.5">Dari</label>
                <input type="date" name="from" value="{{ request('from') }}" class="input">
            </div>
            <div>
                <label class="label mb-0.5">Sampai</label>
                <input type="date" name="to" value="{{ request('to') }}" class="input">
            </div>
            <button class="btn btn-primary btn-sm">
                <span class="material-symbols-outlined text-[16px]">filter_alt</span> Terapkan
            </button>
            <a href="{{ $r('dashboard') }}" class="btn btn-secondary btn-sm">Reset</a>
        </form>
    </div>

    <!-- Bento Grid - Summary Cards -->
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="relative flex h-32 flex-col justify-between overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest p-4">
            <div class="absolute -bottom-4 -right-4 opacity-5">
                <span class="material-symbols-outlined text-8xl">payments</span>
            </div>
            <div>
                <h3 class="text-label-caps font-bold uppercase tracking-wider text-secondary">Total Omset — {{ $labelPeriode }}</h3>
                <div class="mt-2 text-headline-lg font-bold text-primary">{{ rupiah($omsetPeriode) }}</div>
            </div>
            <div class="flex items-center gap-1 text-body-sm text-lunas-green">
                <span class="material-symbols-outlined text-[16px]">trending_up</span>
                <span>{{ $awal->format('d/m/Y') }} – {{ $akhir->format('d/m/Y') }} · manual + rekonstruksi (tersimpan)</span>
            </div>
        </div>

        <div class="flex h-32 flex-col justify-between rounded-xl border border-outline-variant border-l-4 border-l-danger-red bg-surface-container-lowest p-4">
            <div class="flex items-start justify-between">
                <h3 class="flex items-center gap-1 text-label-caps font-bold uppercase tracking-wider text-danger-red">
                    <span class="material-symbols-outlined text-[14px]">warning</span>
                    Hutang Belum Lunas
                </h3>
            </div>
            <div class="text-headline-lg font-bold text-on-surface">{{ rupiah($totalHutang) }}</div>
            <div class="flex w-fit items-center gap-1 rounded bg-error-container px-2 py-0.5 text-body-sm font-medium text-danger-red">
                <span class="material-symbols-outlined text-[14px]">event_busy</span>
                {{ $jatuhTempo7Hari->count() }} tagihan jatuh tempo ≤ 7 hari / lewat
            </div>
        </div>

        <div class="flex h-32 flex-col justify-between rounded-xl border border-outline-variant border-l-4 border-l-hutang-amber bg-surface-container-lowest p-4">
            <h3 class="text-label-caps font-bold uppercase tracking-wider text-hutang-amber">SKU Stok Menipis / Habis</h3>
            <div class="flex items-baseline gap-2">
                <span class="text-headline-lg font-bold text-on-surface">{{ $stokKritis->count() }}</span>
                <span class="text-body-md text-secondary">Item (ambang ≤ {{ $ambang }})</span>
            </div>
            <div class="flex w-fit items-center gap-1 rounded bg-amber-50 px-2 py-0.5 text-body-sm font-medium text-hutang-amber">
                <span class="material-symbols-outlined text-[14px]">inventory_2</span>
                {{ $stokKritis->take(2)->pluck('nama')->join(', ') ?: 'Semua aman' }}{{ $stokKritis->count() > 2 ? '…' : '' }}
            </div>
        </div>
    </div>

    <!-- Kartu Pembelian ringkas (filter preset ?pb — independen dari filter omset) -->
    <div class="mt-4">
        @include('pembelian.partials.kartu-pembelian')
    </div>

    <div class="mt-2 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            @if (! $publik)
            <!-- Quick Actions -->
            <div class="card p-6">
                <h3 class="mb-4 text-headline-sm text-on-surface">Aksi Cepat</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <a href="{{ route('pembelian.create') }}" class="group flex flex-col items-center justify-center rounded-lg border border-outline-variant bg-surface-muted p-4 transition-all hover:bg-surface-container active:shadow-inner">
                        <div class="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-primary-fixed text-primary transition-colors group-hover:bg-primary group-hover:text-white">
                            <span class="material-symbols-outlined">add_shopping_cart</span>
                        </div>
                        <span class="text-center text-body-sm font-medium text-on-surface">Transaksi Pembelian</span>
                    </a>
                    <a href="{{ route('barang.create') }}" class="group flex flex-col items-center justify-center rounded-lg border border-outline-variant bg-surface-muted p-4 transition-all hover:bg-surface-container active:shadow-inner">
                        <div class="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-secondary-container text-primary transition-colors group-hover:bg-secondary group-hover:text-white">
                            <span class="material-symbols-outlined">inventory</span>
                        </div>
                        <span class="text-center text-body-sm font-medium text-on-surface">Data Barang Baru</span>
                    </a>
                    <a href="{{ route('generate.index') }}" class="group flex flex-col items-center justify-center rounded-lg border border-outline-variant bg-surface-muted p-4 transition-all hover:bg-surface-container active:shadow-inner">
                        <div class="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-tertiary-fixed text-tertiary transition-colors group-hover:bg-tertiary group-hover:text-white">
                            <span class="material-symbols-outlined">auto_awesome</span>
                        </div>
                        <span class="text-center text-body-sm font-medium text-on-surface">Generate Transaksi</span>
                    </a>
                </div>
            </div>
            @endif

            <!-- Penjualan Terakhir -->
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 p-4">
                    <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                        <span class="material-symbols-outlined text-cash-blue">point_of_sale</span>
                        Penjualan Terakhir
                    </h3>
                    <a href="{{ $r('penjualan.index') }}" class="text-body-sm text-primary hover:underline">Lihat Semua</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th class="px-3 py-2">No. Nota</th>
                                <th class="px-3 py-2">Tanggal</th>
                                <th class="px-3 py-2">Pelanggan</th>
                                <th class="px-3 py-2 text-right">Total</th>
                                @if (! $publik)
                                <th class="px-3 py-2 text-center">Sumber</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($penjualanTerakhir as $t)
                                <tr>
                                    <td class="mono text-secondary">{{ $t['ref'] }}</td>
                                    <td>{{ $t['tanggal']->format('d/m/Y H:i') }}</td>
                                    <td class="font-medium">{{ $t['pihak'] }}</td>
                                    <td class="mono text-right">{{ rupiah($t['total']) }}</td>
                                    @if (! $publik)
                                    <td class="text-center">
                                        @if ($t['sumber'] === 'rekonstruksi')
                                            <x-badge color="blue">Rekonstruksi</x-badge>
                                        @else
                                            <x-badge color="green">Manual</x-badge>
                                        @endif
                                    </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $publik ? 4 : 5 }}" class="py-8 text-center text-secondary">Belum ada penjualan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pembelian Terakhir -->
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 p-4">
                    <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                        <span class="material-symbols-outlined text-hutang-amber">shopping_cart</span>
                        Pembelian Terakhir
                    </h3>
                    <a href="{{ $r('pembelian.invoice-hutang') }}" class="text-body-sm text-primary hover:underline">Lihat Semua</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th class="px-3 py-2">Invoice</th>
                                <th class="px-3 py-2">Tanggal</th>
                                <th class="px-3 py-2">Supplier</th>
                                <th class="px-3 py-2 text-right">Total</th>
                                <th class="px-3 py-2 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pembelianTerakhir as $i)
                                <tr>
                                    <td class="mono text-secondary">{{ $i['ref'] }}</td>
                                    <td>{{ $i['tanggal']->format('d/m/Y') }}</td>
                                    <td class="font-medium">{{ $i['pihak'] }}</td>
                                    <td class="mono text-right">{{ rupiah($i['total']) }}</td>
                                    <td class="text-center">
                                        @if ($i['status'] === 'lunas')
                                            <x-badge color="green">Lunas</x-badge>
                                        @else
                                            <x-badge color="amber">Hutang</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-secondary">Belum ada pembelian.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-6">
            <!-- Attention Panel -->
            <div class="card p-5">
                <div class="mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-danger-red">notification_important</span>
                    <h3 class="text-headline-sm text-on-surface">Perhatian Segera</h3>
                </div>
                <div class="flex flex-col gap-3">
                    @forelse ($jatuhTempo7Hari->take(3) as $inv)
                        <div class="flex gap-3 rounded-lg border border-red-100 bg-red-50 p-3">
                            <span class="material-symbols-outlined mt-0.5 text-[20px] text-danger-red">warning</span>
                            <div>
                                <h4 class="text-body-sm font-semibold text-danger-red">Tagihan Jatuh Tempo {{ $inv->jatuh_tempo->isPast() ? 'Terlewat' : 'Segera' }}</h4>
                                <p class="mt-1 text-table-data text-slate-700">{{ $inv->supplier?->nama }} — sisa {{ rupiah($inv->sisa_hutang) }} ({{ $inv->no_invoice }})</p>
                                @if (! $publik)
                                <a href="{{ $r('pembelian.show', $inv) }}" class="mt-1 inline-block text-label-caps font-bold text-primary hover:underline">Bayar Sekarang →</a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-body-sm text-secondary">Tidak ada tagihan yang mendesak. 🎉</p>
                    @endforelse

                    @forelse ($stokKritis->take(3) as $b)
                        <div class="flex gap-3 rounded-lg border border-amber-100 bg-amber-50 p-3">
                            <span class="material-symbols-outlined mt-0.5 text-[20px] text-hutang-amber">inventory_2</span>
                            <div>
                                <h4 class="text-body-sm font-semibold text-hutang-amber">Stok {{ $b->stock <= 0 ? 'Habis' : 'Menipis' }}: {{ $b->nama }}</h4>
                                <p class="mt-1 text-table-data text-slate-700">Sisa stok: {{ $b->stock }} {{ $b->satuan }}.</p>
                                @if (! $publik)
                                <a href="{{ route('pembelian.create') }}" class="mt-1 inline-block text-label-caps font-bold text-primary hover:underline">Order Sekarang →</a>
                                @endif
                            </div>
                        </div>
                    @empty
                        @if ($stokKritis->count() === 0)
                            <p class="text-body-sm text-secondary">Semua stok di atas ambang. 👍</p>
                        @endif
                    @endforelse
                </div>
            </div>

            <!-- Barang Terbaru (10 SKU & Stok) -->
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 p-4">
                    <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                        <span class="material-symbols-outlined text-special-teal">inventory_2</span>
                        Barang Terbaru (SKU & Stok)
                    </h3>
                    <a href="{{ $r('barang.index') }}" class="text-body-sm text-primary hover:underline">Lihat Semua</a>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse ($barangTerbaru as $b)
                        <a href="{{ $r('barang.show', $b) }}" class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-slate-50">
                            <div class="min-w-0 flex-1">
                                <div class="mono text-mono-data text-secondary">{{ $b->kode_barang }}</div>
                                <div class="truncate text-body-sm text-on-surface">{{ $b->nama }}</div>
                            </div>
                            <span class="badge {{ $b->stock <= 0 ? 'badge-red' : ($b->stock <= $ambang ? 'badge-amber' : 'badge-green') }}">
                                {{ $b->stock }} {{ $b->satuan }}
                            </span>
                        </a>
                    @empty
                        <p class="px-4 py-6 text-center text-body-sm text-secondary">Belum ada barang.</p>
                    @endforelse
                </div>
            </div>

            <!-- System Status -->
            <div class="card relative flex-1 overflow-hidden p-5">
                <h3 class="mb-4 text-headline-sm text-on-surface">System Status</h3>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-body-sm text-secondary">Mode Aplikasi</span>
                        <span class="flex items-center gap-1 rounded bg-green-50 px-2 py-1 text-label-caps font-bold text-lunas-green">
                            <span class="h-1.5 w-1.5 rounded-full bg-lunas-green"></span> Super Kasir
                        </span>
                    </div>
                    @if (! $publik)
                    <div class="flex items-center justify-between">
                        <span class="text-body-sm text-secondary">Sumber Transaksi</span>
                        <span class="mono text-mono-data text-on-surface">manual + rekonstruksi</span>
                    </div>
                    @endif
                    <div class="h-px w-full bg-outline-variant"></div>
                    <div>
                        <span class="mb-1 block text-label-caps font-bold text-secondary">Kesiapan Stok (kartu stok sinkron)</span>
                        <div class="h-1.5 w-full rounded-full bg-slate-200">
                            <div class="h-1.5 rounded-full bg-primary" style="width: 100%"></div>
                        </div>
                        <p class="mt-1 text-right text-[10px] text-secondary">Up to date</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
