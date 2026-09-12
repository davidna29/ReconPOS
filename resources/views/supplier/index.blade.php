@extends('layouts.app')

@section('title', 'Data Supplier')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Data Supplier</h2>
            <p class="page-head-subtitle">Master supplier pembelian (PRD 4.4)</p>
        </div>
        <a href="{{ route('supplier.create') }}" class="btn btn-primary shadow-sm">
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add</span> Tambah Data
        </a>
    </div>

    <div class="card animate-rise overflow-hidden" style="--rise-delay:.08s">
        <div class="overflow-x-auto">
            <table class="tbl tbl-cards">
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
                <tbody class="rise-rows">
                    @forelse ($suppliers as $i => $s)
                        <tr>
                            <td data-label="No" class="text-secondary">{{ $suppliers->firstItem() + $i }}</td>
                            <td data-label="Nama Supplier" class="font-medium">{{ $s->nama }}</td>
                            <td data-label="Kontak">{{ $s->kontak }}</td>
                            <td data-label="Alamat" class="max-w-[200px] truncate">{{ $s->alamat ?? '-' }}</td>
                            <td data-label="Total Hutang" class="mono text-right @if ($s->totalHutangBerjalan() > 0) font-bold text-hutang-amber @endif">
                                {{ $s->totalHutangBerjalan() > 0 ? rupiah($s->totalHutangBerjalan()) : '-' }}
                            </td>
                            <td data-label="Aksi" class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('supplier.riwayat', $s) }}" class="btn-icon text-special-teal hover:bg-teal-50" title="Riwayat Pembelian">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">receipt_long</span>
                                    </a>
                                    <a href="{{ route('supplier.edit', $s) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Edit">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                                    </a>
                                    <form method="POST" action="{{ route('supplier.destroy', $s) }}" data-confirm="Hapus supplier {{ $s->nama }}? (ditolak bila sudah ada pembelian)">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
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
