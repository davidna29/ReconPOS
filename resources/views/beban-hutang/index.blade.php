@extends('layouts.app')

@section('title', 'Beban Hutang per Cabang')

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Beban Hutang per Cabang</h2>
            <p class="page-head-subtitle">Pemilahan faktur hutang ke TERMINAL BAN 1 / 2 / 3 — {{ $grand['lembar'] }} lembar · hutang {{ rupiah($grand['hutang']) }} · sisa {{ rupiah($grand['sisa']) }} · dibayar {{ rupiah($grand['dibayar']) }} · {{ $grand['lunas'] }} lunas</p>
        </div>
        @if (! $publik)
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('pembelian.bayar-bulk.index') }}" class="btn btn-success btn-sm">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">payments</span> Bayar Bulk
            </a>
        </div>
        @endif
    </div>

    <form method="GET" action="{{ $r('beban-hutang.index') }}" class="grid grid-cols-2 gap-2 md:flex md:flex-wrap md:items-end md:gap-2">
        <div>
            <label class="label mb-0.5 text-[11px] md:text-body-sm">Bulan</label>
            <input type="month" name="bulan" value="{{ request('bulan') }}" class="input w-full md:w-40">
        </div>
        <div>
            <label class="label mb-0.5 text-[11px] md:text-body-sm">Tahun</label>
            <input type="number" name="tahun" min="2000" max="2099" value="{{ request('tahun') }}" placeholder="{{ now()->year }}" class="input w-full md:w-24">
        </div>
        <div>
            <label class="label mb-0.5 text-[11px] md:text-body-sm">Dari</label>
            <input type="date" name="from" value="{{ request('from') }}" class="input w-full">
        </div>
        <div>
            <label class="label mb-0.5 text-[11px] md:text-body-sm">Sampai</label>
            <input type="date" name="to" value="{{ request('to') }}" class="input w-full">
        </div>
        <div>
            <label class="label mb-0.5 text-[11px] md:text-body-sm">Cabang</label>
            <select name="cabang" class="input w-full md:w-48">
                <option value="">Semua cabang</option>
                @foreach ($daftarCabang as $cb)
                    <option value="{{ $cb }}" @selected((string) request('cabang') === (string) $cb)>{{ $cb }}</option>
                @endforeach
            </select>
        </div>
        @if (! $publik)
        <div>
            <label class="label mb-0.5 text-[11px] md:text-body-sm">Supplier</label>
            <select name="supplier" class="input w-full md:w-48">
                <option value="">Semua supplier</option>
                @foreach (($suppliers ?? collect()) as $s)
                    <option value="{{ $s->id }}" @selected((string) request('supplier') === (string) $s->id)>{{ $s->nama }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div class="col-span-2 flex gap-2 md:col-span-1">
            <button class="btn btn-primary btn-sm flex-1 md:flex-none">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">filter_alt</span> Terapkan
            </button>
            <a href="{{ $r('beban-hutang.index') }}" class="btn btn-secondary btn-sm flex-1 text-center md:flex-none">Reset</a>
        </div>
    </form>

    @forelse ($cards as $c)
        @php($pct = $c['hutang'] > 0 ? (int) round($c['sisa'] / $c['hutang'] * 100) : 0)
        @php($barColor = $pct < 50 ? 'bg-lunas-green' : ($pct <= 80 ? 'bg-hutang-amber' : 'bg-danger-red'))
        <div class="card animate-rise overflow-hidden min-w-0" style="--rise-delay:.06s">
            <div class="flex flex-wrap items-center gap-2 border-b border-outline-variant bg-slate-50 px-4 py-3">
                <span class="material-symbols-outlined text-[20px] text-secondary" aria-hidden="true">account_balance</span>
                <h3 class="text-headline-sm text-on-surface">{{ $c['cabang'] }}</h3>
                <span class="badge badge-slate">{{ $c['lembar'] }} lembar</span>
                <span class="badge {{ $c['belum_lunas'] > 0 ? 'badge-amber' : 'badge-green' }}">{{ $c['lunas'] }} lunas · {{ $c['belum_lunas'] }} belum</span>
                @if (! $publik && $c['cabang'] !== 'Belum Dibebani')
                <a href="{{ route('pembelian.bayar-bulk.index', ['cabang' => $c['cabang']]) }}" class="btn btn-success btn-sm ml-auto">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">payments</span> Bayar cabang ini
                </a>
                @endif
            </div>
            <div class="grid grid-cols-2 gap-2 p-3 md:grid-cols-5 md:gap-4 md:p-4">
                <div class="kpi-card p-3 md:p-4 min-h-[92px] md:min-h-0">
                    <h4 class="kpi-card-caption text-[10px] md:text-label-caps">Hutang</h4>
                    <div class="kpi-card-value text-[17px] md:text-headline-lg">{{ rupiah($c['hutang']) }}</div>
                </div>
                <div class="kpi-card p-3 md:p-4 min-h-[92px] md:min-h-0">
                    <h4 class="kpi-card-caption text-[10px] md:text-label-caps">Sisa Hutang</h4>
                    <div class="kpi-card-value text-[17px] md:text-headline-lg {{ $c['sisa'] > 0 ? 'text-hutang-amber' : 'text-lunas-green' }}">{{ rupiah($c['sisa']) }}</div>
                </div>
                <div class="kpi-card p-3 md:p-4 min-h-[92px] md:min-h-0">
                    <h4 class="kpi-card-caption text-[10px] md:text-label-caps">Dibayar</h4>
                    <div class="kpi-card-value text-[17px] md:text-headline-lg text-lunas-green">{{ rupiah($c['dibayar']) }}</div>
                </div>
                <div class="col-span-2 md:col-span-2">
                    <span class="mb-1 block text-label-caps font-bold text-secondary">Porsi sisa ({{ $pct }}%)</span>
                    <div class="h-1.5 w-full rounded-full bg-slate-200">
                        <div class="h-1.5 rounded-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                    </div>
                    <p class="mt-1 text-[11px] text-secondary md:text-right">{{ $c['lembar'] }} lembar faktur dalam filter ini</p>
                </div>
            </div>
            <div class="overflow-x-auto min-w-0 max-w-full border-t border-outline-variant">
                <table class="tbl tbl-cards">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Tanggal</th>
                            <th>Supplier</th>
                            <th class="text-right">Hutang</th>
                            <th class="text-right">Sisa</th>
                            <th class="text-center">Status</th>
                            @if (! $publik)<th class="text-right">Cabang</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @php($tampil = ($rinciSemua ?? false) ? $c['items'] : $c['items']->take(10))
                        @foreach ($tampil as $inv)
                            <tr @class(['row-overdue' => $inv->sisa_hutang > 0])>
                                <td data-label="Invoice" class="mono font-semibold text-primary">{{ $inv->no_invoice }}</td>
                                <td data-label="Tanggal">{{ $inv->tanggal_transaksi->format('d/m/Y') }}</td>
                                <td data-label="Supplier" class="font-medium">{{ $inv->supplier?->nama ?? '-' }}</td>
                                <td data-label="Hutang" class="mono text-right">{{ rupiah($inv->total_setelah_diskon) }}</td>
                                <td data-label="Sisa" class="mono text-right font-bold {{ $inv->sisa_hutang > 0 ? 'text-hutang-amber' : 'text-lunas-green' }}">{{ rupiah($inv->sisa_hutang) }}</td>
                                <td data-label="Status" class="text-center">
                                    @if ($inv->status === 'lunas')
                                        <x-badge color="green">Lunas</x-badge>
                                    @else
                                        <x-badge color="amber">Belum</x-badge>
                                    @endif
                                </td>
                                @if (! $publik)
                                <td data-label="Cabang" class="text-right">
                                    <form method="POST" action="{{ route('beban-hutang.tetapkan') }}" class="flex justify-end">
                                        @csrf
                                        <input type="hidden" name="ids[]" value="{{ $inv->id }}">
                                        <select name="cabang" aria-label="Pindah cabang {{ $inv->no_invoice }}" class="input w-40 py-1 text-[12px]" onchange="this.form.submit()">
                                            <option value="">— Belum Dibebani —</option>
                                            @foreach (\App\Models\InvoicePembelian::CABANG_SARAN as $cb)
                                                <option value="{{ $cb }}" @selected($inv->cabang === $cb)>{{ str_replace('TERMINAL BAN ', 'TB ', $cb) }}</option>
                                            @endforeach
                                            @if ($inv->cabang && ! in_array($inv->cabang, \App\Models\InvoicePembelian::CABANG_SARAN, true))
                                                <option value="{{ $inv->cabang }}" selected>{{ $inv->cabang }}</option>
                                            @endif
                                        </select>
                                    </form>
                                </td>
                                @endif
                            </tr>
                        @endforeach
                        @if (! ($rinciSemua ?? false) && $c['items']->count() > $tampil->count())
                            <tr>
                                <td colspan="{{ $publik ? 6 : 7 }}" class="py-3 text-center text-body-sm text-secondary">
                                    +{{ $c['items']->count() - $tampil->count() }} lainnya —
                                    <a href="{{ request()->fullUrlWithQuery(['rinci_semua' => 1]) }}" class="font-semibold text-primary hover:underline">Tampilkan semua</a>
                                </td>
                            </tr>
                        @elseif (($rinciSemua ?? false) && $c['items']->count() > 10)
                            <tr>
                                <td colspan="{{ $publik ? 6 : 7 }}" class="py-3 text-center text-body-sm text-secondary">
                                    <a href="{{ request()->fullUrlWithQuery(['rinci_semua' => null]) }}" class="font-semibold text-primary hover:underline">Ringkas kembali (10 baris)</a>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="empty-state">
                <div class="empty-state-icon"><span class="material-symbols-outlined" aria-hidden="true">account_balance</span></div>
                <p class="empty-state-title">Tidak ada faktur hutang pada filter ini.</p>
                <p class="empty-state-hint">Ubah filter atau catat pembelian hutang dulu.</p>
            </div>
        </div>
    @endforelse

    @if (! $publik)
    <x-collapse id="collapse-tetapkan-beban" label="Tetapkan Beban ({{ $belumDibebaniTotal }} faktur belum berbeban)" icon="checklist">
    <div class="card animate-rise overflow-hidden min-w-0">
        <div class="flex items-center gap-2 border-b border-outline-variant bg-slate-50 px-4 py-3">
            <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">checklist</span>
            <h3 class="text-headline-sm text-on-surface">Tetapkan Beban ({{ $belumDibebaniTotal }} faktur belum berbeban)</h3>
        </div>
        <form method="POST" action="{{ route('beban-hutang.tetapkan') }}">
            @csrf
            <div class="flex flex-wrap items-center gap-2 border-b border-outline-variant p-3">
                <select name="cabang" aria-label="Cabang beban" class="input w-56" required>
                    <option value="">— Pilih cabang —</option>
                    @foreach (\App\Models\InvoicePembelian::CABANG_SARAN as $cb)
                        <option value="{{ $cb }}">{{ $cb }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary btn-sm">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">done_all</span> Tetapkan ke yg dipilih
                </button>
                <span class="hint mb-0">Centang faktur di bawah (10/halaman dari {{ $belumDibebaniTotal }}). Untuk massal gunakan template Excel.</span>
            </div>
            <div class="overflow-x-auto min-w-0 max-w-full">
                <table class="tbl tbl-cards">
                    <thead>
                        <tr>
                            <th class="w-8"><span class="sr-only">Pilih</span></th>
                            <th>Invoice</th>
                            <th>Tanggal</th>
                            <th>Supplier</th>
                            <th class="text-right">Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($belumDibebani as $inv)
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="{{ $inv->id }}" class="shrink-0 rounded border-outline-variant text-primary focus:ring-primary" aria-label="Pilih {{ $inv->no_invoice }}"></td>
                                <td data-label="Invoice" class="mono font-semibold text-primary">{{ $inv->no_invoice }}</td>
                                <td data-label="Tanggal">{{ $inv->tanggal_transaksi->format('d/m/Y') }}</td>
                                <td data-label="Supplier" class="font-medium">{{ $inv->supplier?->nama ?? '-' }}</td>
                                <td data-label="Sisa" class="mono text-right">{{ rupiah($inv->sisa_hutang) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-secondary">Semua faktur hutang sudah berbeban. 🎉</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($belumDibebani->hasPages())
            <div class="border-t border-outline-variant p-3">
                {{ $belumDibebani->withQueryString()->links() }}
            </div>
            @endif
        </form>
    </div>
    </x-collapse>

    <div class="card animate-rise p-3 md:p-4 min-w-0">
        <h3 class="mb-2 md:mb-3 flex items-center gap-2 text-[13px] font-semibold text-on-surface md:text-headline-sm">
            <span class="material-symbols-outlined text-[16px] text-secondary md:text-[18px]" aria-hidden="true">upload_file</span>
            Template Beban Hutang (massal via Excel)
        </h3>
        <div class="flex flex-col gap-2 md:flex-row md:items-center">
            <a href="{{ route('beban-hutang.template.download') }}" class="btn btn-success btn-sm w-full md:w-auto">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">download</span> Download Template
            </a>
            <form method="POST" action="{{ route('beban-hutang.template.import') }}" enctype="multipart/form-data" class="flex flex-1 flex-col gap-2 md:flex-row md:items-center min-w-0">
                @csrf
                <div class="flex flex-1 items-center gap-2 min-w-0 max-w-full">
                    <label class="btn btn-secondary btn-sm shrink-0 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">folder_open</span> Pilih File
                        <input type="file" name="file" accept=".xlsx" class="sr-only" required>
                    </label>
                    <span data-filename class="min-w-0 flex-1 truncate text-body-sm text-secondary">Belum ada file dipilih</span>
                </div>
                <button class="btn btn-amber btn-sm w-full md:w-auto">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">upload</span> Upload Template Beban
                </button>
            </form>
        </div>
    </div>
    @endif
@endsection
