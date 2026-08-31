@extends('layouts.app')

@section('title', 'Penjualan Manual')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Penjualan Manual (Kasir)</h2>
            <p class="mt-1 text-body-md text-secondary">Transaksi input manual (sumber: manual) — dihitung dalam target generate & dipakai di export (PRD 1.2b, 8)</p>
        </div>
        <a href="{{ route('penjualan.create') }}" class="btn btn-primary">
            <span class="material-symbols-outlined text-[18px]">add</span> Transaksi Penjualan Baru
        </a>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-outline-variant p-3">
            <form method="GET" class="flex items-center gap-2">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-secondary">search</span>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari ref transaksi…" class="input w-64 pl-9">
                </div>
                <button class="btn btn-secondary btn-sm">Cari</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Ref Transaksi</th>
                        <th>Tanggal</th>
                        <th class="text-right">Total</th>
                        <th>Sumber</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transaksis as $i => $t)
                        <tr>
                            <td class="text-secondary">{{ $transaksis->firstItem() + $i }}</td>
                            <td class="mono font-semibold text-primary">{{ $t->ref_transaksi }}</td>
                            <td>{{ $t->tanggal_waktu->format('d/m/Y H:i') }}</td>
                            <td class="mono text-right">{{ rupiah($t->total) }}</td>
                            <td><x-badge color="slate">Manual</x-badge></td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="btn-icon hover:bg-slate-100" title="Detail"
                                        onclick="document.getElementById('det-{{ $t->id }}').classList.toggle('hidden')">
                                        <span class="material-symbols-outlined text-[18px]">visibility</span>
                                    </button>
                                    <form method="POST" action="{{ route('penjualan.destroy', $t) }}" data-confirm="Hapus penjualan {{ $t->ref_transaksi }}? Stok akan dikembalikan.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                            <span class="material-symbols-outlined text-[18px]">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr id="det-{{ $t->id }}" class="hidden bg-slate-50">
                            <td colspan="6">
                                <table class="tbl">
                                    <thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>
                                    <tbody>
                                        @foreach ($t->items as $it)
                                            <tr>
                                                <td>{{ $it->barang->nama }}@if ($it->no_sn) <span class="mono text-[11px] text-secondary">SN {{ $it->no_sn }}</span>@endif</td>
                                                <td class="mono text-center">{{ $it->qty }}</td>
                                                <td class="mono text-right">{{ uang($it->harga_jual) }}</td>
                                                <td class="mono text-right">{{ uang($it->subtotal) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-secondary">Belum ada penjualan manual.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $transaksis->withQueryString()->links() }}
        </div>
    </div>
@endsection
