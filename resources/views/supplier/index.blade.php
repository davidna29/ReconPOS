@extends('layouts.app')

@section('title', 'Data Supplier')

@section('content')
    <div class="flex items-end justify-between">
        <div>
            <h2 class="text-headline-lg text-on-surface">Data Supplier</h2>
            <p class="mt-1 text-body-md text-secondary">Master supplier pembelian (PRD 4.4)</p>
        </div>
        <a href="{{ route('supplier.create') }}" class="btn btn-primary">
            <span class="material-symbols-outlined text-[18px]">add</span> Tambah Data
        </a>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Supplier</th>
                        <th>Kontak</th>
                        <th>Alamat</th>
                        <th class="text-right">Total Hutang Berjalan</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppliers as $i => $s)
                        <tr>
                            <td class="text-secondary">{{ $suppliers->firstItem() + $i }}</td>
                            <td class="font-medium">{{ $s->nama }}</td>
                            <td>{{ $s->kontak }}</td>
                            <td class="max-w-[200px] truncate">{{ $s->alamat ?? '-' }}</td>
                            <td class="mono text-right @if ($s->totalHutangBerjalan() > 0) font-bold text-hutang-amber @endif">
                                {{ $s->totalHutangBerjalan() > 0 ? rupiah($s->totalHutangBerjalan()) : '-' }}
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('supplier.riwayat', $s) }}" class="btn-icon text-special-teal hover:bg-teal-50" title="Riwayat Pembelian">
                                        <span class="material-symbols-outlined text-[18px]">receipt_long</span>
                                    </a>
                                    <a href="{{ route('supplier.edit', $s) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Edit">
                                        <span class="material-symbols-outlined text-[18px]">edit</span>
                                    </a>
                                    <form method="POST" action="{{ route('supplier.destroy', $s) }}" data-confirm="Hapus supplier {{ $s->nama }}? (ditolak bila sudah ada pembelian)">
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
                        <tr><td colspan="6" class="py-10 text-center text-secondary">Belum ada supplier.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $suppliers->withQueryString()->links() }}
        </div>
    </div>
@endsection
