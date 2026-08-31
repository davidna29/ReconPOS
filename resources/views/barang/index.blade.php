@extends('layouts.app')

@section('title', 'Data Barang')

@section('content')
    <div class="flex items-end justify-between">
        <div>
            <h2 class="text-headline-lg text-on-surface">Data Barang</h2>
            <p class="mt-1 text-body-md text-secondary">Master item — satu-satunya sumber KODE_BARANG (PRD 4.3)</p>
        </div>
    </div>

    @include('partials.table-toolbar', ['actions' => '
        <a href="' . route('barang.create') . '" class="btn btn-primary">
            <span class="material-symbols-outlined text-[18px]">add</span> Tambah Data
        </a>
        <a href="' . route('barang.template.download') . '" class="btn btn-success btn-sm">
            <span class="material-symbols-outlined text-[16px]">download</span> Template Excel
        </a>
        <a href="' . route('barang.templateHarga.download') . '" class="btn btn-secondary btn-sm">
            <span class="material-symbols-outlined text-[16px]">price_change</span> Template Update Harga
        </a>
    '])

    <div class="card flex items-center gap-4 p-4">
        <form method="POST" action="{{ route('barang.template.import') }}" enctype="multipart/form-data" class="flex flex-1 flex-wrap items-center gap-2">
            @csrf
            <span class="material-symbols-outlined text-secondary">upload_file</span>
            <input type="file" name="file" accept=".xlsx" class="input w-auto" required>
            <button class="btn btn-amber btn-sm">
                <span class="material-symbols-outlined text-[16px]">upload</span> Upload Template Excel
            </button>
        </form>
        <form method="POST" action="{{ route('barang.templateHarga.import') }}" enctype="multipart/form-data" class="flex flex-1 flex-wrap items-center gap-2">
            @csrf
            <span class="material-symbols-outlined text-secondary">sell</span>
            <input type="file" name="file" accept=".xlsx" class="input w-auto" required>
            <button class="btn btn-secondary btn-sm">
                <span class="material-symbols-outlined text-[16px]">upload</span> Update Harga Excel
            </button>
            <span class="text-[11px] text-secondary">hanya berlaku utk SKU mode Kunci Manual</span>
        </form>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
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
                <tbody>
                    @forelse ($barangs as $i => $b)
                        <tr @class(['bg-red-50/60' => $b->stock <= 0])>
                            <td class="text-secondary">{{ $barangs->firstItem() + $i }}</td>
                            <td class="mono text-secondary">{{ $b->kode_barang }}</td>
                            <td class="font-medium">
                                {{ $b->nama }}
                                @if ($b->isSn())
                                    <x-badge color="teal" class="ml-1">SN</x-badge>
                                @endif
                            </td>
                            <td>{{ $b->kategori?->nama ?? '-' }}</td>
                            <td class="mono text-right">{{ rupiah($b->harga_jual) }}</td>
                            <td class="text-center">
                                <span class="mono {{ $b->stock <= 0 ? 'text-danger-red' : ($b->stock <= ($ambang ?? 3) ? 'text-hutang-amber' : 'text-slate-700') }}">
                                    {{ $b->stock }}
                                </span>
                            </td>
                            <td><span class="badge badge-slate">{{ $b->status }}</span></td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('barang.show', $b) }}" class="btn-icon text-lunas-green hover:bg-green-50" title="Lihat Detail">
                                        <span class="material-symbols-outlined text-[18px]">visibility</span>
                                    </a>
                                    <a href="{{ route('barang.edit', $b) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Edit">
                                        <span class="material-symbols-outlined text-[18px]">edit</span>
                                    </a>
                                    <a href="{{ route('barang.kartuStok', $b) }}" class="btn-icon text-hutang-amber hover:bg-amber-50" title="Riwayat / Kartu Stok">
                                        <span class="material-symbols-outlined text-[18px]">history</span>
                                    </a>
                                    <a href="{{ route('barang.barcode', $b) }}" class="btn-icon text-special-teal hover:bg-teal-50" title="Cetak Barcode">
                                        <span class="material-symbols-outlined text-[18px]">qr_code_2</span>
                                    </a>
                                    <form method="POST" action="{{ route('barang.destroy', $b) }}" data-confirm="Hapus barang {{ $b->kode_barang }}? (hanya jika belum dipakai transaksi)">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                            <span class="material-symbols-outlined text-[18px]">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-10 text-center text-secondary">Belum ada barang. Klik "+ Tambah Data" atau upload template Excel.</td>
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
