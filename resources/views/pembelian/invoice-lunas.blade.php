@extends('layouts.app')

@section('title', 'Data Pembelian')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Data Pembelian</h2>
            <p class="mt-1 text-body-md text-secondary">Data barang Pembelian — Cash langsung lunas + Hutang yang sudah dilunasi (PRD 5.2b)</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pembelian.invoice-hutang') }}" class="btn btn-amber btn-sm">
                <span class="material-symbols-outlined text-[16px]">receipt_long</span> Data Hutang Belum Lunas
            </a>
            <a href="{{ route('pembelian.create') }}" class="btn btn-primary btn-sm">
                <span class="material-symbols-outlined text-[16px]">add</span> Transaksi Pembelian
            </a>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-outline-variant p-3">
            <form method="GET" class="flex items-center gap-2">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-secondary">search</span>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari invoice / supplier…" class="input w-64 pl-9">
                </div>
                <button class="btn btn-secondary btn-sm">Cari</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Invoice</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Bayar</th>
                        <th class="text-right">Kembali</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $i => $inv)
                        @php($kembali = $inv->jenis === 'cash' ? max(0, $inv->bayar_atau_dp - $inv->total_setelah_diskon) : 0)
                        <tr>
                            <td class="text-secondary">{{ $invoices->firstItem() + $i }}</td>
                            <td class="mono font-semibold text-primary">{{ $inv->no_invoice }}
                                @if ($inv->sumber === 'import_excel')<x-badge color="slate" class="ml-1">import</x-badge>@endif
                            </td>
                            <td>{{ $inv->tanggal_transaksi->format('d/m/Y') }}</td>
                            <td class="font-medium">{{ $inv->supplier?->nama }}</td>
                            <td class="mono text-right">{{ rupiah($inv->total_setelah_diskon) }}</td>
                            <td class="mono text-right">{{ rupiah($inv->bayar_atau_dp) }}</td>
                            <td class="mono text-right text-lunas-green">{{ $kembali > 0 ? rupiah($kembali) : '-' }}</td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('pembelian.show', $inv) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Detail">
                                        <span class="material-symbols-outlined text-[18px]">visibility</span>
                                    </a>
                                    <a href="{{ route('pembelian.edit', $inv) }}" class="btn-icon text-hutang-amber hover:bg-amber-50" title="Edit">
                                        <span class="material-symbols-outlined text-[18px]">edit</span>
                                    </a>
                                    <a href="{{ route('pembelian.print', $inv) }}" target="_blank" class="btn-icon text-lunas-green hover:bg-green-50" title="Print">
                                        <span class="material-symbols-outlined text-[18px]">print</span>
                                    </a>
                                    <form method="POST" action="{{ route('pembelian.destroy', $inv) }}" data-confirm="Hapus invoice {{ $inv->no_invoice }}? Efek stok & histori akan dibatalkan.">
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
                        <tr><td colspan="8" class="py-10 text-center text-secondary">Belum ada pembelian lunas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $invoices->withQueryString()->links() }}
        </div>
    </div>

    @include('partials.resolve-stok-warning')
@endsection
