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
    </form>

    @forelse ($groups as $g)
        <div class="card animate-rise overflow-hidden min-w-0" style="--rise-delay:.06s">
            <div class="flex flex-wrap items-center gap-2 border-b border-outline-variant bg-slate-50 px-4 py-3">
                <span class="material-symbols-outlined text-[20px] text-secondary" aria-hidden="true">factory</span>
                <h3 class="text-headline-sm text-on-surface">{{ $g['merk'] }}</h3>
                <span class="badge badge-slate">{{ $g['total_sku'] }} SKU</span>
                <span class="badge {{ $g['total_stok'] <= 0 ? 'badge-red' : 'badge-green' }}">Stok {{ $g['total_stok'] }}</span>
            </div>
            <div class="flex flex-col gap-4 p-3 md:p-4">
                @foreach ($g['varians'] as $v)
                    <div class="min-w-0">
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            <h4 class="text-body-md font-semibold text-on-surface">{{ $v['varian'] }}</h4>
                            <span class="text-body-sm text-secondary">Stok {{ $v['total_stok'] }}</span>
                        </div>
                        <div class="overflow-x-auto min-w-0 max-w-full rounded-lg border border-outline-variant">
                            <table class="tbl tbl-cards">
                                <thead>
                                    <tr>
                                        <th>Ukuran</th>
                                        <th>Kode Barang</th>
                                        <th>Nama</th>
                                        <th class="text-center">Stok</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($v['items'] as $b)
                                        <tr @class(['row-overdue' => $b->stock <= 0])>
                                            <td data-label="Ukuran" class="mono font-semibold">{{ $b->ukuran_ban ?? '-' }}</td>
                                            <td data-label="Kode Barang" class="mono text-secondary">{{ $b->kode_barang }}</td>
                                            <td data-label="Nama" class="font-medium">{{ $b->nama }}</td>
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
@endsection
