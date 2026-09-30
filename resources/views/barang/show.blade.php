@extends('layouts.app')

@section('title', $barang->nama)

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    <div class="page-head">
        <div>
            <h2 class="page-head-title">{{ $barang->nama }}</h2>
            <p class="page-head-subtitle font-mono text-mono-data">{{ $barang->kode_barang }} · {{ $barang->kategori?->nama ?? 'Tanpa kategori' }} · {{ $barang->satuan }} ·
                @if ($barang->isSn())<span class="badge badge-teal">SN</span>@else<span class="badge badge-slate">NON_SN</span>@endif
                <span class="badge badge-slate">{{ $barang->status }}</span>
            </p>
            @if ($barang->merk || $barang->varian || $barang->ukuran_ban)
            <p class="page-head-subtitle mt-1">{{ $barang->merk ?? '-' }} · {{ $barang->varian ?? '-' }} · {{ $barang->ukuran_ban ?? '-' }}</p>
            @endif
        </div>
        @if (! $publik)
        <a href="{{ route('barang.edit', $barang) }}" class="btn btn-primary">
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span> Edit
        </a>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card overflow-hidden lg:col-span-2">
            <div class="flex items-center gap-2 border-b border-outline-variant bg-slate-50 px-4 py-3">
                <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">inventory_2</span>
                <h3 class="text-headline-sm text-on-surface">Detail Barang</h3>
            </div>
            <div class="grid grid-cols-1 gap-x-6 gap-y-3 p-5 sm:grid-cols-2">
                <div><span class="text-label-caps font-bold text-secondary">Kode Barang</span><div class="mono text-body-md">{{ $barang->kode_barang }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Satuan</span><div class="text-body-md">{{ $barang->satuan }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Merk</span><div class="text-body-md">{{ $barang->merk ?? '-' }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Varian</span><div class="text-body-md">{{ $barang->varian ?? '-' }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Ukuran Ban</span><div class="mono text-body-md">{{ $barang->ukuran_ban ?? '-' }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Harga Beli Terakhir</span><div class="text-body-md">{{ rupiah($barang->harga_beli_terakhir) }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Harga Jual</span><div class="text-body-md font-semibold text-primary">{{ rupiah($barang->harga_jual) }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Margin</span><div class="text-body-md">{{ $barang->margin_override !== null ? $barang->margin_override . '% (override)' : 'Global' }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Mode Harga</span><div class="text-body-md">{{ $barang->mode_harga_jual === 'otomatis' ? 'Hitung Ulang Otomatis' : 'Kunci Manual' }}</div></div>
                <div><span class="text-label-caps font-bold text-secondary">Stok Saat Ini</span>
                    <div class="text-body-md {{ $barang->stock <= 0 ? 'font-bold text-danger-red' : '' }}">{{ $barang->stock }} {{ $barang->satuan }}</div>
                </div>
                <div><span class="text-label-caps font-bold text-secondary">Lokasi Rak</span><div class="text-body-md">{{ $barang->lokasi_rak ?? '-' }}</div></div>
                <div class="sm:col-span-2"><span class="text-label-caps font-bold text-secondary">Deskripsi</span><div class="text-body-md whitespace-pre-line">{{ $barang->deskripsi ?? '-' }}</div></div>
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <a href="{{ $r('barang.kartuStok', $barang) }}" class="btn btn-amber">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">history</span> Buka Kartu Stok (Riwayat Mutasi)
            </a>
            <a href="{{ $r('barang.barcode', $barang) }}" class="btn btn-secondary">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">qr_code_2</span> Cetak Label Barcode
            </a>
        </div>

        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-outline-variant bg-slate-50 px-4 py-3">
                <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">tag</span>
                <h3 class="text-headline-sm text-on-surface">Referensi SN ({{ $barang->referensiSn->count() }})</h3>
            </div>
            <div class="max-h-64 overflow-y-auto p-3">
                @forelse ($barang->referensiSn as $sn)
                    <div class="flex items-center justify-between border-b border-slate-100 py-1.5">
                        <span class="mono text-mono-data">{{ $sn->no_sn }}</span>
                        @if ($sn->status === 'tersedia')
                            <x-badge color="green">Tersedia</x-badge>
                        @else
                            <x-badge color="slate">Terjual</x-badge>
                        @endif
                    </div>
                @empty
                    <p class="text-body-sm text-secondary">Belum ada nomor SN (barang ber-SN dibuat saat pembelian).</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="card-pad">
            <h3 class="mb-3 flex items-center gap-2 text-headline-sm text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">price_change</span>
                Riwayat Harga (histori_harga)
            </h3>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Tanggal</th><th class="text-right">Harga Beli</th><th class="text-right">Margin</th><th class="text-right">Harga Jual Efektif</th></tr></thead>
                    <tbody>
                        @forelse ($histori as $h)
                            <tr>
                                <td>{{ $h->tanggal_berlaku->format('d/m/Y') }}</td>
                                <td class="mono text-right">{{ uang($h->harga_beli) }}</td>
                                <td class="text-right">{{ $h->margin_dipakai }}%</td>
                                <td class="mono text-right">{{ uang($h->harga_jual_efektif) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-secondary">Belum ada histori (isi lewat Transaksi Pembelian).</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-pad">
            <h3 class="mb-3 flex items-center gap-2 text-headline-sm text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">history</span>
                Kartu Stok (ringkas)
            </h3>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Tanggal</th><th>Jenis</th><th class="text-right">Qty</th><th class="text-right">Saldo</th></tr></thead>
                    <tbody>
                        @php($saldo = 0)
                        @forelse ($kartu as $k)
                            @php($saldo += $k->qty)
                            <tr>
                                <td>{{ $k->tanggal->format('d/m/Y') }}</td>
                                <td><span class="badge badge-slate">{{ str_replace('_', ' ', $k->jenis) }}</span></td>
                                <td class="mono text-right {{ $k->qty < 0 ? 'text-danger-red' : 'text-lunas-green' }}">{{ $k->qty > 0 ? '+' . $k->qty : $k->qty }}</td>
                                <td class="mono text-right">{{ $saldo }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-secondary">Belum ada mutasi stok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
