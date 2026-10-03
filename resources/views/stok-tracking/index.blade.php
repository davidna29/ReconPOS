@extends('layouts.app')

@section('title', 'Tracking Stok')

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    <div class="page-head animate-rise">
        <div class="min-w-0">
            <h2 class="page-head-title">Tracking Stok</h2>
            <p class="page-head-subtitle">Stok yang masih tersisa dari batch pembelian, diurutkan dari yang paling tua (FIFO)</p>
        </div>
        @if (! $publik)
        <div class="page-head-actions shrink-0 sm:flex-nowrap">
            <a href="{{ route('stok-tracking.export.xlsx', ['months' => request('months')]) }}" class="btn btn-secondary btn-sm" title="Unduh Excel sesuai filter (tanpa filter = semua bulan)">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">table_view</span> Export Excel
            </a>
            <a href="{{ route('stok-tracking.export.pdf', ['months' => request('months')]) }}" class="btn btn-secondary btn-sm" title="Unduh PDF sesuai filter (tanpa filter = semua bulan)">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">picture_as_pdf</span> Export PDF
            </a>
        </div>
        @endif
    </div>

    <!-- Ringkasan Total Stok (luar filter) -->
    <div class="relative mb-4 flex h-24 animate-rise items-center justify-between overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest p-4 kpi-accent-blue" style="--rise-delay:.05s">
        <div class="absolute -bottom-3 -right-3 opacity-5" aria-hidden="true">
            <span class="material-symbols-outlined text-7xl">inventory</span>
        </div>
        <div>
            <h3 class="kpi-card-caption">Total Stok Keseluruhan</h3>
            <div class="mt-1 text-headline-md font-bold text-primary">{{ number_format($totalStok) }} item dari {{ number_format($totalSku) }} SKU</div>
        </div>
        <span class="text-body-sm text-secondary">Semua barang dengan stok > 0</span>
    </div>

    @if (! empty($dibatasi))
        <div role="alert" class="mb-4 flex items-start gap-3 rounded-xl border border-hutang-amber/40 bg-amber-50 p-4 shadow-sm">
            <span class="material-symbols-outlined text-hutang-amber" aria-hidden="true">warning</span>
            <div class="text-body-sm font-medium text-amber-800">Filter dibatasi maksimal 2 bulan — dipakai 2 bulan terbaru dari pilihan Anda. Hapus centang bila ingin mengganti.</div>
        </div>
    @endif

    <!-- Filter Bulan (maks 2 — dijaga popup instan + cap server) -->
    <div class="card animate-rise" style="--rise-delay:.1s">
        <form method="GET" action="{{ $r('stok-tracking.index') }}" data-month-guard class="flex flex-wrap items-center gap-3 p-3">
            <span class="text-body-sm font-medium text-secondary">Filter Bulan Pembelian (maks 2):</span>
            @foreach ($availableMonths as $m)
                <label class="flex items-center gap-1.5 rounded-lg border border-outline-variant px-3 py-1.5 text-body-sm">
                    <input type="checkbox" name="months[]" value="{{ $m }}"
                        @checked(in_array($m, $selectedMonths))
                        class="rounded border-outline-variant text-primary focus:ring-primary">
                    {{ \Carbon\Carbon::createFromFormat('Y-m', $m)->translatedFormat('F Y') }}
                </label>
            @endforeach
            <select name="entries" aria-label="Jumlah baris per halaman" class="input w-auto py-1.5 text-[13px] shadow-sm" onchange="this.form.submit()">
                @foreach ([10, 25, 50, 100] as $n)
                    <option value="{{ $n }}" @selected((int) request('entries', 10) === $n)>{{ $n }} baris</option>
                @endforeach
            </select>
            <button class="btn btn-primary btn-sm shadow-sm">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">filter_alt</span> Terapkan
            </button>
            <a href="{{ $r('stok-tracking.index') }}" class="btn btn-secondary btn-sm">Reset</a>
        </form>
    </div>

    <!-- Tabel per Bulan -->
    @forelse ($grouped as $month => $rows)
        @php($monthLabel = \Carbon\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y'))
        @php($ringkas = ($ringkasBulan[$month] ?? null))

        <div class="card animate-rise mb-4 overflow-hidden" style="--rise-delay:.12s">
            <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 px-4 py-2.5">
                <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">calendar_month</span>
                    {{ $monthLabel }}
                    <span class="ml-2 text-body-sm font-normal text-secondary">{{ number_format($ringkas['sisa'] ?? $rows->sum('qty_sisa')) }} item tersisa dari {{ $ringkas['sku'] ?? $rows->pluck('barang.id')->unique()->count() }} SKU</span>
                </h3>
                <span class="text-body-sm font-semibold text-primary">{{ rupiah(0) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl tbl-cards tbl-stok-kompak">
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
                    <tbody class="rise-rows">
                        @foreach ($rows as $r)
                            <tr>
                                <td data-label="Nama Barang" class="font-medium min-w-0">
                                    <span class="hidden md:inline">{{ $r['barang']->nama }}</span>
                                    {{-- Ringkas mobile: maks 2 baris (nama 1 baris + meta 1 baris, font kecil) --}}
                                    <span class="md:hidden block min-w-0">
                                        <span class="block truncate text-[12.5px] font-semibold leading-tight text-on-surface">{{ $r['barang']->nama }}</span>
                                        <span class="mt-0.5 flex items-center gap-1.5 text-[11px] leading-tight text-secondary">
                                            <span class="mono truncate">{{ $r['barang']->kode_barang }}</span>
                                            <span aria-hidden="true">·</span>
                                            <span class="whitespace-nowrap">Sisa {{ number_format($r['qty_sisa']) }}</span>
                                            <span aria-hidden="true">·</span>
                                            <span class="whitespace-nowrap">{{ $r['umur_hari'] }} hari</span>
                                        </span>
                                    </span>
                                </td>
                                <td data-label="Kode" class="mono text-secondary">{{ $r['barang']->kode_barang }}</td>
                                <td data-label="Sumber">
                                    @if ($r['jenis'] === 'masuk_pembelian' && $r['invoice_id'])
                                        <span class="badge badge-blue">Pembelian</span>
                                    @elseif ($r['jenis'] === 'stok_awal')
                                        <span class="badge badge-slate">Stok Awal</span>
                                    @else
                                        <span class="badge badge-amber">Penyesuaian</span>
                                    @endif
                                </td>
                                <td data-label="Tanggal Masuk">{{ $r['tanggal']->format('d/m/Y H:i') }}</td>
                                <td data-label="Qty Awal" class="mono text-right">{{ number_format($r['qty_awal']) }}</td>
                                <td data-label="Sisa" class="mono text-right font-bold {{ $r['qty_sisa'] > 0 ? 'text-hutang-amber' : 'text-secondary' }}">
                                    {{ number_format($r['qty_sisa']) }}
                                </td>
                                <td data-label="Umur" class="mono text-right text-secondary">{{ $r['umur_hari'] }} hari</td>
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

    @if (isset($paginator) && $paginator->hasPages())
        <div class="card animate-rise p-3">
            <p class="mb-2 text-center text-body-sm text-secondary">
                Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }} batch
            </p>
            {{ $paginator->withQueryString()->links() }}
        </div>
    @endif

    @push('scripts')
    <script>
        // Guard filter bulan maks 2: centang ke-3 dibatalkan + popup larangan instan.
        // (Cap server tetap menegakkan bila JS mati / URL dirakit manual.)
        document.addEventListener('change', function (e) {
            var box = e.target.closest ? e.target.closest('[data-month-guard] input[name="months[]"]') : null;
            if (!box || !box.checked) return;
            var form = box.closest('form');
            if (form.querySelectorAll('input[name="months[]"]:checked').length > 2) {
                box.checked = false;
                window.alert('Bulan pembelian dibatasi maksimal 2 — centang ketiga dibatalkan.');
            }
        });
    </script>
    @endpush
@endsection
