@extends('layouts.app')

@section('title', 'Rekap Stok per Merk')

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    <div class="page-head animate-rise">
        <div class="min-w-0">
            <h2 class="page-head-title">Rekap Stok per Merk</h2>
            <p class="page-head-subtitle">Susunan Merk → Varian → Ukuran + stok ({{ $totalSku }} SKU · total {{ $totalStok }} pcs)</p>
        </div>
        @if (! $publik)
        <div class="page-head-actions shrink-0 sm:flex-nowrap">
            <a href="{{ route('rekap-stok.export.xlsx', request()->only(['merk', 'q', 'sembunyikan_kosong'])) }}" class="btn btn-secondary btn-sm" title="Unduh Excel rekap + rincian sesuai filter">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">table_view</span> Export Excel
            </a>
            <a href="{{ route('rekap-stok.export.pdf', request()->only(['merk', 'q', 'sembunyikan_kosong'])) }}" class="btn btn-secondary btn-sm" title="Unduh PDF rekap + rincian sesuai filter">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">picture_as_pdf</span> Export PDF
            </a>
            <a href="{{ route('rekap-stok.export.docx', request()->only(['merk', 'q', 'sembunyikan_kosong'])) }}" class="btn btn-secondary btn-sm" title="Unduh Word rekap + rincian sesuai filter">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">description</span> Export Word
            </a>
        </div>
        @endif
    </div>

    <form method="GET" action="{{ $r('rekap-stok.index') }}" class="flex flex-wrap items-end gap-2">
        <div>
            <label class="label mb-0.5">Merk</label>
            <select name="merk" class="input w-56">
                <option value="">Semua merk</option>
                @foreach ($merks as $m)
                    <option value="{{ $m }}" @selected((string) request('merk') === (string) $m)>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label mb-0.5">Cari</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Kode / nama / ukuran…" aria-label="Cari kode, nama, atau ukuran" class="input w-56">
        </div>
        <label class="flex items-center gap-1.5 rounded-lg border border-outline-variant px-3 py-2 text-body-sm">
            <input type="checkbox" name="sembunyikan_kosong" value="1" @checked(request()->boolean('sembunyikan_kosong')) class="rounded border-outline-variant text-primary focus:ring-primary">
            Sembunyikan stok 0
        </label>
        <button class="btn btn-primary btn-sm">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">filter_alt</span> Terapkan
        </button>
        <a href="{{ $r('rekap-stok.index') }}" class="btn btn-secondary btn-sm">Reset</a>
        <select name="entries" aria-label="Jumlah SKU per halaman" class="input w-auto py-1.5 text-[13px] shadow-sm" onchange="this.form.submit()">
            @foreach ([10, 25, 50, 100] as $n)
                <option value="{{ $n }}" @selected((int) request('entries', 10) === $n)>{{ $n }} SKU</option>
            @endforeach
        </select>
    </form>

    @forelse ($groups as $g)
        @php($rm = ($ringkasMerk[$g['merk']] ?? null))
        <div class="card animate-rise overflow-hidden min-w-0" style="--rise-delay:.06s">
            <div class="flex flex-wrap items-center gap-2 border-b border-outline-variant bg-slate-50 px-4 py-3">
                <span class="material-symbols-outlined text-[20px] text-secondary" aria-hidden="true">factory</span>
                <h3 class="text-headline-sm text-on-surface">{{ $g['merk'] }}</h3>
                <span class="badge badge-slate">{{ $rm['sku'] ?? $g['total_sku'] }} SKU</span>
                <span class="badge {{ ($rm['stok'] ?? $g['total_stok']) <= 0 ? 'badge-red' : 'badge-green' }}">Stok {{ $rm['stok'] ?? $g['total_stok'] }}</span>
            </div>
            <div class="flex flex-col gap-4 p-3 md:p-4">
                @foreach ($g['varians'] as $v)
                    @php($stokVarian = $ringkasVarian[$g['merk'] . "\0" . $v['varian']] ?? $v['total_stok'])
                    <div class="min-w-0">
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            <h4 class="text-body-md font-semibold text-on-surface">{{ $v['varian'] }}</h4>
                            <span class="text-body-sm text-secondary">Stok {{ $stokVarian }}</span>
                        </div>
                        <div class="overflow-x-auto min-w-0 max-w-full rounded-lg border border-outline-variant">
                            <table class="tbl tbl-cards tbl-rekap-kompak">
                                <thead>
                                    <tr>
                                        <th>Ukuran</th>
                                        <th>Kode Barang</th>
                                        <th>Nama</th>
                                        <th class="text-right">Harga Jual</th>
                                        <th class="text-center">Stok</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($v['items'] as $b)
                                        <tr @class(['row-overdue' => $b->stock <= 0])>
                                            <td data-label="Ukuran" class="mono font-semibold">{{ $b->ukuran_ban ?? '-' }}</td>
                                            <td data-label="Kode Barang" class="mono text-secondary">{{ $b->kode_barang }}</td>
                                            <td data-label="Nama" class="font-medium min-w-0">
                                                <span class="hidden md:inline">{{ $b->nama }}</span>
                                                {{-- Ringkas mobile: maks 2 baris (nama 1 baris + meta 1 baris, font kecil) --}}
                                                <span class="md:hidden block min-w-0">
                                                    <span class="block truncate text-[12.5px] font-semibold leading-tight text-on-surface">{{ $b->nama }}</span>
                                                    <span class="mt-0.5 flex items-center gap-1.5 text-[11px] leading-tight text-secondary">
                                                        <span class="mono truncate">{{ $b->ukuran_ban ?? '-' }} · {{ $b->kode_barang }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span class="mono whitespace-nowrap">{{ rupiah($b->harga_jual) }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span class="badge text-[10px] {{ $b->stock <= 0 ? 'badge-red' : 'badge-green' }}">Stok {{ $b->stock }}</span>
                                                    </span>
                                                </span>
                                            </td>
                                            <td data-label="Harga Jual" class="mono text-right">{{ rupiah($b->harga_jual) }}</td>
                                            <td data-label="Stok" class="text-center">
                                                <span class="mono {{ $b->stock <= 0 ? 'font-bold text-danger-red' : '' }}">{{ $b->stock }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="card">
            <div class="empty-state">
                <div class="empty-state-icon"><span class="material-symbols-outlined" aria-hidden="true">summarize</span></div>
                <p class="empty-state-title">Tidak ada barang yang cocok.</p>
                <p class="empty-state-hint">Ubah filter atau isi merk/varian/ukuran lewat Data Barang / import template.</p>
            </div>
        </div>
    @endforelse

    @if (isset($paginator) && $paginator->hasPages())
        <div class="card p-3">
            <p class="mb-2 text-center text-body-sm text-secondary">
                Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }} SKU
            </p>
            {{ $paginator->withQueryString()->links() }}
        </div>
    @endif
@endsection
