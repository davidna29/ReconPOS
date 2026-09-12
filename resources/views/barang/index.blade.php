@extends('layouts.app')

@section('title', 'Data Barang')

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Data Barang</h2>
            <p class="page-head-subtitle">Master item — satu-satunya sumber KODE_BARANG (PRD 4.3)</p>
        </div>
    </div>

    @if (($barangTerbaruStrip ?? collect())->count())
    <div class="card animate-rise overflow-hidden" style="--rise-delay:.04s">
        <div class="flex items-center gap-2 border-b border-outline-variant bg-slate-50 px-4 py-2.5">
            <span class="material-symbols-outlined text-[18px] text-special-teal" aria-hidden="true">fiber_new</span>
            <h3 class="text-headline-sm text-on-surface">Baru Ditambahkan</h3>
        </div>
        <div class="flex gap-2 overflow-x-auto p-3">
            @foreach ($barangTerbaruStrip as $nb)
                <a href="{{ $r('barang.show', $nb) }}" class="flex min-w-[180px] items-center gap-2 rounded-lg border border-outline-variant bg-surface-muted px-3 py-2 transition-colors hover:bg-surface-container">
                    <div class="min-w-0 flex-1">
                        <div class="mono text-mono-data text-secondary">{{ $nb->kode_barang }}</div>
                        <div class="truncate text-body-sm text-on-surface">{{ $nb->nama }}</div>
                    </div>
                    <span class="badge {{ $nb->stock <= 0 ? 'badge-red' : 'badge-green' }}">{{ $nb->stock }}</span>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    @if (! $publik)
    @include('partials.table-toolbar', ['actions' => '
        <a href="' . route('barang.create') . '" class="btn btn-primary">
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add</span> Tambah Data
        </a>
        <a href="' . route('barang.template.download') . '" class="btn btn-success btn-sm">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">download</span> Template Excel
        </a>
        <a href="' . route('barang.templateHarga.download') . '" class="btn btn-secondary btn-sm">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">price_change</span> Template Update Harga
        </a>
    '])
    @else
    @include('partials.table-toolbar')
    @endif

    @if (! $publik)
    <div class="card animate-rise p-4" style="--rise-delay:.06s">
        <h3 class="mb-3 flex items-center gap-2 text-headline-sm text-on-surface">
            <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">upload_file</span>
            Import / Update via Excel
        </h3>
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
        <form method="POST" action="{{ route('barang.template.import') }}" enctype="multipart/form-data" class="flex flex-1 flex-wrap items-center gap-2">
            @csrf
            <span class="material-symbols-outlined text-secondary" aria-hidden="true">upload_file</span>
            <input type="file" name="file" accept=".xlsx" class="input w-auto max-w-full" required>
            <button class="btn btn-amber btn-sm">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">upload</span> Upload Template Excel
            </button>
        </form>
        <form method="POST" action="{{ route('barang.templateHarga.import') }}" enctype="multipart/form-data" class="flex flex-1 flex-wrap items-center gap-2">
            @csrf
            <span class="material-symbols-outlined text-secondary" aria-hidden="true">sell</span>
            <input type="file" name="file" accept=".xlsx" class="input w-auto max-w-full" required>
            <button class="btn btn-secondary btn-sm">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">upload</span> Update Harga Excel
            </button>
            <span class="text-[11px] text-secondary">hanya berlaku utk SKU mode Kunci Manual</span>
        </form>
        </div>
    </div>
    @endif

    <div class="card animate-rise overflow-hidden" style="--rise-delay:.12s">
        <div class="overflow-x-auto">
            <table class="tbl tbl-cards">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Barang</th>
                        <th>Nama</th>
                        <th>Kategori</th>
                        <th class="text-right">Harga Jual</th>
                        <th class="text-center">Stock</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="rise-rows">
                    @forelse ($barangs as $i => $b)
                        <tr @class(['row-overdue' => $b->stock <= 0])>
                            <td data-label="No" class="text-secondary">{{ $barangs->firstItem() + $i }}</td>
                            <td data-label="Kode Barang" class="mono text-secondary">{{ $b->kode_barang }}</td>
                            <td data-label="Nama" class="font-medium">
                                {{ $b->nama }}
                                @if ($b->isSn())
                                    <x-badge color="teal" class="ml-1">SN</x-badge>
                                @endif
                            </td>
                            <td data-label="Kategori">{{ $b->kategori?->nama ?? '-' }}</td>
                            <td data-label="Harga Jual" class="mono text-right">{{ rupiah($b->harga_jual) }}</td>
                            <td data-label="Stok" class="text-center">
                                <span class="mono {{ $b->stock <= 0 ? 'text-danger-red' : ($b->stock <= ($ambang ?? 3) ? 'text-hutang-amber' : 'text-slate-700') }}">
                                    {{ $b->stock }}
                                </span>
                            </td>
                            <td data-label="Status"><span class="badge badge-slate">{{ $b->status }}</span></td>
                            <td data-label="Aksi" class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ $r('barang.show', $b) }}" class="btn-icon text-lunas-green hover:bg-green-50" title="Lihat Detail">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">visibility</span>
                                    </a>
                                    @if (! $publik)
                                    <a href="{{ route('barang.edit', $b) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Edit">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                                    </a>
                                    @endif
                                    <a href="{{ $r('barang.kartuStok', $b) }}" class="btn-icon text-hutang-amber hover:bg-amber-50" title="Riwayat / Kartu Stok">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">history</span>
                                    </a>
                                    <a href="{{ $r('barang.barcode', $b) }}" class="btn-icon text-special-teal hover:bg-teal-50" title="Cetak Barcode">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">qr_code_2</span>
                                    </a>
                                    @if (! $publik)
                                    <form method="POST" action="{{ route('barang.destroy', $b) }}" data-confirm="Hapus barang {{ $b->kode_barang }}? (hanya jika belum dipakai transaksi)">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-10 text-center text-secondary">{{ $publik ? 'Belum ada barang.' : 'Belum ada barang. Klik "+ Tambah Data" atau upload template Excel.' }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $barangs->withQueryString()->links() }}
        </div>
    </div>
@endsection
