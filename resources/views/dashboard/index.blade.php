@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    @php($hutangAlert = $totalHutang > 0)
    @php($stokAlert = $stokKritis->count() > 0)
    @php($kritikJson = ($kritikTop ?? collect())->map(fn ($b) => ['kode' => $b->kode_barang, 'nama' => $b->nama, 'stok' => $b->stock, 'satuan' => $b->satuan])->values()->all())
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Overview</h2>
            <p class="page-head-subtitle">Periode aktif: <span class="font-semibold text-primary">{{ $labelPeriode }}</span></p>
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
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">filter_alt</span> Terapkan
            </button>
            <a href="{{ $r('dashboard') }}" class="btn btn-secondary btn-sm">Reset</a>
        </form>
    </div>

    <!-- Bento Grid - Summary Cards (kartu warning/danger naik ke depan) -->
    <div class="grid animate-rise grid-cols-1 gap-4 md:grid-cols-3" style="--rise-delay:.06s">
        @if ($hutangAlert)
            @include('dashboard.partials.kpi-hutang')
        @endif
        @if ($stokAlert)
            @include('dashboard.partials.kpi-stok')
        @endif
        @include('dashboard.partials.kpi-omset')
        @if (! $hutangAlert)
            @include('dashboard.partials.kpi-hutang')
        @endif
        @if (! $stokAlert)
            @include('dashboard.partials.kpi-stok')
        @endif
    </div>

    <!-- Kartu Pembelian ringkas (filter preset ?pb — independen dari filter omset) -->
    <div class="mt-4">
        @include('pembelian.partials.kartu-pembelian')
    </div>

    <!-- Grafik: Trend Omset + Aging Hutang -->
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card animate-rise overflow-hidden lg:col-span-2" style="--rise-delay:.1s">
            <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 p-4">
                <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-cash-blue" aria-hidden="true">show_chart</span>
                    Trend Omset — {{ $labelPeriode }}
                </h3>
            </div>
            <div class="p-4">
                <div class="relative h-64"><canvas id="chart-trend"></canvas></div>
                @if (($trendStep ?? 1) > 1)
                    <p class="hint mt-2">Rentang lebih dari 1 tahun — diagregasi per minggu (total tetap utuh).</p>
                @endif
            </div>
        </div>
        <div class="card animate-rise overflow-hidden" style="--rise-delay:.14s">
            <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 p-4">
                <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-hutang-amber" aria-hidden="true">hourglass_bottom</span>
                    Aging Hutang
                </h3>
            </div>
            <div class="p-4">
                <div class="relative h-64"><canvas id="chart-aging"></canvas></div>
                @if (($tanpaTempo ?? 0) > 0)
                    <p class="hint mt-2">{{ $tanpaTempo }} invoice tanpa jatuh tempo (tidak masuk bucket).</p>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-2 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            @if (! $publik)
            <!-- Quick Actions -->
            <div class="card p-6">
                <h3 class="mb-4 text-headline-sm text-on-surface">Aksi Cepat</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <a href="{{ route('pembelian.create') }}" class="group flex flex-col items-center justify-center rounded-lg border border-outline-variant bg-surface-muted p-4 transition-all hover:bg-surface-container hover:shadow-sm active:shadow-inner">
                        <div class="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-primary-fixed text-on-primary-fixed transition-colors group-hover:bg-primary group-hover:text-white">
                            <span class="material-symbols-outlined" aria-hidden="true">add_shopping_cart</span>
                        </div>
                        <span class="text-center text-body-sm font-medium text-on-surface">Transaksi Pembelian</span>
                    </a>
                    <a href="{{ route('barang.create') }}" class="group flex flex-col items-center justify-center rounded-lg border border-outline-variant bg-surface-muted p-4 transition-all hover:bg-surface-container hover:shadow-sm active:shadow-inner">
                        <div class="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-secondary-fixed text-on-secondary-fixed transition-colors group-hover:bg-secondary group-hover:text-white">
                            <span class="material-symbols-outlined" aria-hidden="true">inventory</span>
                        </div>
                        <span class="text-center text-body-sm font-medium text-on-surface">Data Barang Baru</span>
                    </a>
                    <a href="{{ route('generate.index') }}" class="group flex flex-col items-center justify-center rounded-lg border border-outline-variant bg-surface-muted p-4 transition-all hover:bg-surface-container hover:shadow-sm active:shadow-inner">
                        <div class="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-tertiary-fixed text-on-tertiary-fixed transition-colors group-hover:bg-tertiary group-hover:text-white">
                            <span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span>
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
                        <span class="material-symbols-outlined text-cash-blue" aria-hidden="true">point_of_sale</span>
                        Penjualan Terakhir
                    </h3>
                    <a href="{{ $r('penjualan.index') }}" class="text-body-sm text-primary hover:underline">Lihat Semua</a>
                </div>
                @if ($penjualanTerakhir->count())
                <div class="max-h-[400px] overflow-auto">
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
                            @foreach ($penjualanTerakhir as $t)
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
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="empty-state">
                    <div class="empty-state-icon"><span class="material-symbols-outlined" aria-hidden="true">point_of_sale</span></div>
                    <p class="empty-state-title">Belum ada penjualan.</p>
                    @if (! $publik)
                    <p class="empty-state-hint">Catat transaksi manual atau generate otomatis dari stok.</p>
                    <div class="mt-2 flex flex-wrap justify-center gap-2">
                        <a href="{{ route('penjualan.create') }}" class="btn btn-primary btn-sm">Buat Transaksi</a>
                        <a href="{{ route('generate.index') }}" class="btn btn-secondary btn-sm">Generate Otomatis</a>
                    </div>
                    @else
                    <p class="empty-state-hint">Belum ada penjualan pada periode ini.</p>
                    @endif
                </div>
                @endif
            </div>

            <!-- Pembelian Terakhir -->
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 p-4">
                    <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                        <span class="material-symbols-outlined text-hutang-amber" aria-hidden="true">shopping_cart</span>
                        Pembelian Terakhir
                    </h3>
                    <a href="{{ $r('pembelian.invoice-hutang') }}" class="text-body-sm text-primary hover:underline">Lihat Semua</a>
                </div>
                <div class="max-h-[400px] overflow-auto">
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
            <!-- System Status -->
            <div class="card relative overflow-hidden p-5">
                <h3 class="mb-4 flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-lunas-green" aria-hidden="true">monitor_heart</span>
                    System Status
                </h3>
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

            <!-- Attention Panel -->
            <div class="card p-5">
                <div class="mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-danger-red" aria-hidden="true">notification_important</span>
                    <h3 class="text-headline-sm text-on-surface">Perhatian Segera</h3>
                </div>
                <div class="alert-panel max-h-[320px] overflow-auto">
                    @forelse ($jatuhTempo7Hari->take(3) as $inv)
                        <div class="alert-item alert-item-red">
                            <span class="material-symbols-outlined mt-0.5 text-[20px] text-danger-red" aria-hidden="true">warning</span>
                            <div>
                                <h4 class="alert-item-title text-danger-red">Tagihan Jatuh Tempo {{ $inv->jatuh_tempo->isPast() ? 'Terlewat' : 'Segera' }}</h4>
                                <p class="alert-item-detail">{{ $inv->supplier?->nama }} — sisa {{ rupiah($inv->sisa_hutang) }} ({{ $inv->no_invoice }})</p>
                                @if (! $publik)
                                <a href="{{ $r('pembelian.show', $inv) }}" class="mt-1 inline-block text-label-caps font-bold text-primary hover:underline">Bayar Sekarang →</a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-body-sm text-secondary">Tidak ada tagihan yang mendesak. 🎉</p>
                    @endforelse

                    @forelse ($stokKritis->take(3) as $b)
                        <div class="alert-item alert-item-amber">
                            <span class="material-symbols-outlined mt-0.5 text-[20px] text-hutang-amber" aria-hidden="true">inventory_2</span>
                            <div>
                                <h4 class="alert-item-title text-hutang-amber">Stok {{ $b->stock <= 0 ? 'Habis' : 'Menipis' }}: {{ $b->nama }}</h4>
                                <p class="alert-item-detail">Sisa stok: {{ $b->stock }} {{ $b->satuan }}.</p>
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

            <!-- Top 10 SKU Stok Kritis -->
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 p-4">
                    <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                        <span class="material-symbols-outlined text-danger-red" aria-hidden="true">low_priority</span>
                        Stok Kritis: Top 10
                    </h3>
                    <a href="{{ $r('stok-tracking.index') }}" class="text-body-sm text-primary hover:underline">Lihat Semua</a>
                </div>
                <div class="p-4">
                    <div class="relative h-64"><canvas id="chart-kritik"></canvas></div>
                    <p class="hint mt-2">Ambang ≤ {{ $ambang }} · stok tersedikit di atas.</p>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    window.DASHBOARD_CHARTS = {
        trend: { labels: @json($trendLabels ?? []), values: @json($trendValues ?? []), step: {{ $trendStep ?? 1 }} },
        aging: { labels: @json($agingLabels ?? []), values: @json($agingValues ?? []) },
        kritik: @json($kritikJson ?? []),
    };
</script>
@endpush
