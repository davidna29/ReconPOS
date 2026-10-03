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
    <div class="card animate-rise overflow-hidden min-w-0" style="--rise-delay:.04s">
        <div class="flex items-center gap-2 border-b border-outline-variant bg-slate-50 px-3 py-2 md:px-4 md:py-2.5">
            <span class="material-symbols-outlined text-[16px] text-special-teal md:text-[18px]" aria-hidden="true">fiber_new</span>
            <h3 class="text-[13px] font-semibold text-on-surface md:text-headline-sm">Baru Ditambahkan</h3>
        </div>
        <div class="flex gap-2 overflow-x-auto p-2 md:p-3 snap-x snap-mandatory min-w-0 max-w-full">
            @foreach ($barangTerbaruStrip as $nb)
                <a href="{{ $r('barang.show', $nb) }}" class="flex min-w-[148px] md:min-w-[180px] snap-start items-center gap-2 rounded-lg border border-outline-variant bg-surface-muted px-2.5 py-2 md:px-3 transition-colors hover:bg-surface-container">
                    <div class="min-w-0 flex-1">
                        <div class="mono text-[11px] text-secondary md:text-mono-data">{{ $nb->kode_barang }}</div>
                        <div class="truncate text-[12px] text-on-surface md:text-body-sm">{{ $nb->nama }}</div>
                    </div>
                    <span class="badge text-[10px] md:text-label-caps {{ $nb->stock <= 0 ? 'badge-red' : 'badge-green' }}">{{ $nb->stock }}</span>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    @if (! $publik)
    @include('partials.table-toolbar', ['actionsId' => 'toolbar-barang-aksi', 'actionsLabel' => 'Tambah / Template / Harga', 'actionsIcon' => 'add', 'actions' => '
        <a href="' . route('barang.create') . '" class="btn btn-primary btn-sm text-[12px] md:text-body-sm">
            <span class="material-symbols-outlined text-[16px] md:text-[18px]" aria-hidden="true">add</span> Tambah Data
        </a>
        <a href="' . route('barang.template.download') . '" class="btn btn-success btn-sm text-[11px] md:text-body-sm">
            <span class="material-symbols-outlined text-[14px] md:text-[16px]" aria-hidden="true">download</span> Template Excel
        </a>
        <a href="' . route('barang.templateHarga.download') . '" class="btn btn-secondary btn-sm text-[11px] md:text-body-sm">
            <span class="material-symbols-outlined text-[14px] md:text-[16px]" aria-hidden="true">price_change</span> Update Harga
        </a>
    ', 'filters' => '
        <select name="merk" aria-label="Filter merk" class="input w-auto py-1.5 text-[13px] shadow-sm md:py-2 md:text-body-md" onchange="this.form.submit()">
            <option value="">Semua merk</option>
            ' . collect($merks ?? [])->map(fn ($m) => '<option value="' . e($m) . '"' . ((string) request('merk') === (string) $m ? ' selected' : '') . '>' . e($m) . '</option>')->implode('') . '
        </select>
    '])
    @else
    @include('partials.table-toolbar', ['filters' => '
        <select name="merk" aria-label="Filter merk" class="input w-auto py-1.5 text-[13px] shadow-sm md:py-2 md:text-body-md" onchange="this.form.submit()">
            <option value="">Semua merk</option>
            ' . collect($merks ?? [])->map(fn ($m) => '<option value="' . e($m) . '"' . ((string) request('merk') === (string) $m ? ' selected' : '') . '>' . e($m) . '</option>')->implode('') . '
        </select>
    '])
    @endif

    @if (! $publik)
    <div class="card animate-rise p-3 md:p-4 min-w-0" style="--rise-delay:.06s">
        <h3 class="hidden md:flex mb-3 items-center gap-2 text-headline-sm text-on-surface">
            <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">upload_file</span>
            Import / Update via Excel
        </h3>
        <x-collapse id="collapse-import-barang" label="Import / Update via Excel" icon="upload_file">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 min-w-0">
                <form method="POST" action="{{ route('barang.template.import') }}" enctype="multipart/form-data" class="rounded-lg border border-outline-variant p-3 min-w-0">
                    @csrf
                    <p class="mb-2 flex items-center gap-1.5 text-body-sm font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[16px] text-secondary" aria-hidden="true">upload_file</span>
                        Upload Template Barang
                    </p>
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <label class="btn btn-secondary btn-sm shrink-0 cursor-pointer">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">folder_open</span> Pilih File
                                <input type="file" name="file" accept=".xlsx" class="sr-only" required>
                            </label>
                            <span data-filename class="min-w-0 flex-1 truncate text-body-sm text-secondary">Belum ada file dipilih</span>
                        </div>
                        <button class="btn btn-amber btn-sm w-full">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">upload</span> Upload Template Excel
                        </button>
                    </div>
                </form>
                <form method="POST" action="{{ route('barang.templateHarga.import') }}" enctype="multipart/form-data" class="rounded-lg border border-outline-variant p-3 min-w-0">
                    @csrf
                    <p class="mb-2 flex items-center gap-1.5 text-body-sm font-semibold text-on-surface">
                        <span class="material-symbols-outlined text-[16px] text-secondary" aria-hidden="true">sell</span>
                        Update Harga Massal
                    </p>
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <label class="btn btn-secondary btn-sm shrink-0 cursor-pointer">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">folder_open</span> Pilih File
                                <input type="file" name="file" accept=".xlsx" class="sr-only" required>
                            </label>
                            <span data-filename class="min-w-0 flex-1 truncate text-body-sm text-secondary">Belum ada file dipilih</span>
                        </div>
                        <button class="btn btn-secondary btn-sm w-full">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">upload</span> Update Harga Excel
                        </button>
                        <span class="text-[11px] text-secondary">hanya berlaku utk SKU mode Kunci Manual</span>
                    </div>
                </form>
            </div>
        </x-collapse>
    </div>
    @endif

    <div class="card animate-rise overflow-hidden min-w-0" style="--rise-delay:.12s">
        <div class="overflow-x-auto min-w-0 max-w-full">
            <table class="tbl tbl-cards tbl-barang-kompak">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Barang</th>
                        <th>Nama</th>
                        <th>Merk</th>
                        <th>Varian</th>
                        <th>Ukuran</th>
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
                            <td data-label="Nama" class="font-medium min-w-0">
                                <span class="hidden md:inline">
                                    {{ $b->nama }}
                                    @if ($b->isSn())
                                        <x-badge color="teal" class="ml-1">SN</x-badge>
                                    @endif
                                </span>
                                {{-- Ringkas mobile: maks 2 baris (nama 1 baris + meta 1 baris, font kecil) --}}
                                <span class="md:hidden block min-w-0">
                                    <span class="block truncate text-[12.5px] font-semibold leading-tight text-on-surface">{{ $b->nama }}</span>
                                    <span class="mt-0.5 flex items-center gap-1.5 text-[11px] leading-tight text-secondary">
                                        <span class="mono truncate">{{ $b->kode_barang }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span class="mono whitespace-nowrap">{{ rupiah($b->harga_jual) }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span class="badge text-[10px] {{ $b->stock <= 0 ? 'badge-red' : 'badge-green' }}">Stok {{ $b->stock }}</span>
                                    </span>
                                </span>
                            </td>
                            <td data-label="Merk">{{ $b->merk ?? '-' }}</td>
                            <td data-label="Varian">{{ $b->varian ?? '-' }}</td>
                            <td data-label="Ukuran" class="mono">{{ $b->ukuran_ban ?? '-' }}</td>
                            <td data-label="Kategori">{{ $b->kategori?->nama ?? '-' }}</td>
                            <td data-label="Harga Jual" class="mono text-right">{{ rupiah($b->harga_jual) }}</td>
                            <td data-label="Stok" class="text-center">
                                <span class="mono {{ $b->stock <= 0 ? 'text-danger-red' : ($b->stock <= ($ambang ?? 3) ? 'text-hutang-amber' : 'text-slate-700') }}">
                                    {{ $b->stock }}
                                </span>
                            </td>
                            <td data-label="Status"><span class="badge badge-slate">{{ $b->status }}</span></td>
                            <td data-label="Aksi" class="text-right">
                                {{-- Desktop: 5 ikon sebaris (tak berubah) --}}
                                <div class="hidden md:flex justify-end gap-1">
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
                                {{-- Mobile: menu titik-3 tanpa JS & tanpa id (satu <details> per baris, tak pakai getElementById) --}}
                                <details class="md:hidden barang-menu">
                                    <summary class="btn-icon text-secondary" title="Aksi" aria-label="Aksi barang {{ $b->kode_barang }}">
                                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">more_vert</span>
                                    </summary>
                                    <div class="barang-menu-list">
                                        <a href="{{ $r('barang.show', $b) }}" class="barang-menu-item">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">visibility</span> Lihat
                                        </a>
                                        @if (! $publik)
                                        <a href="{{ route('barang.edit', $b) }}" class="barang-menu-item">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">edit</span> Edit
                                        </a>
                                        @endif
                                        <a href="{{ $r('barang.kartuStok', $b) }}" class="barang-menu-item">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">history</span> Riwayat
                                        </a>
                                        <a href="{{ $r('barang.barcode', $b) }}" class="barang-menu-item">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">qr_code_2</span> Barcode
                                        </a>
                                        @if (! $publik)
                                        <form method="POST" action="{{ route('barang.destroy', $b) }}" data-confirm="Hapus barang {{ $b->kode_barang }}? (hanya jika belum dipakai transaksi)">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="barang-menu-item barang-menu-danger">
                                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">delete</span> Hapus
                                            </button>
                                        </form>
                                        @endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="py-10 text-center text-secondary">{{ $publik ? 'Belum ada barang.' : 'Belum ada barang. Klik "+ Tambah Data" atau upload template Excel.' }}</td>
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
