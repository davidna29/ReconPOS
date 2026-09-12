@extends('layouts.app')

@section('title', 'Kartu Stok — ' . $barang->nama)

@section('content')
    @php($publik = $publik ?? false)
    <div class="page-head">
        <div>
            <h2 class="page-head-title">Kartu Stok: {{ $barang->nama }}</h2>
            <p class="page-head-subtitle">
                <span class="mono">{{ $barang->kode_barang }}</span> · Stok sekarang:
                <span class="font-bold {{ $barang->stock <= 0 ? 'text-danger-red' : 'text-lunas-green' }}">{{ $barang->stock }} {{ $barang->satuan }}</span>
            </p>
        </div>
        @if (! $publik)
        <div class="flex gap-2">
            @if ($barang->stock === 0 && ! $barang->sudahDipakaiTransaksi())
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('stok-awal-form').classList.toggle('hidden')">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">playlist_add</span> Stok Awal
                </button>
            @endif
            <button type="button" class="btn btn-amber" onclick="document.getElementById('opname-form').classList.toggle('hidden')">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">fact_check</span> Stok Opname
            </button>
        </div>
        @endif
    </div>

    @if (! $publik)
    <div id="stok-awal-form" class="card hidden p-4">
        <h3 class="mb-3 flex items-center gap-2 text-headline-sm text-on-surface">
            <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">playlist_add</span>
            Catat Stok Awal
        </h3>
        <form method="POST" action="{{ route('barang.stokAwal', $barang) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="label">Qty Stok Awal</label>
                <input type="number" name="qty" min="1" required class="input w-40">
            </div>
            <button class="btn btn-primary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">save</span> Catat Stok Awal</button>
            <span class="text-[11px] text-secondary">Pencatatan awal stok fisik (hanya saat stok 0, barang baru).</span>
        </form>
    </div>

    <div id="opname-form" class="card hidden p-4">
        <h3 class="mb-3 flex items-center gap-2 text-headline-sm text-on-surface">
            <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">fact_check</span>
            Stok Opname
        </h3>
        <form method="POST" action="{{ route('barang.opname', $barang) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="label">Stok Fisik Hasil Hitung</label>
                <input type="number" name="stok_fisik" min="0" required class="input w-40">
            </div>
            <div class="flex-1">
                <label class="label">Alasan (wajib) <span class="text-danger-red">*</span></label>
                <input type="text" name="alasan" required placeholder="mis. opname bulanan, barang rusak/hilang" class="input">
            </div>
            <button class="btn btn-amber"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">fact_check</span> Simpan Opname</button>
            <span class="text-[11px] text-secondary">Selisih otomatis tercatat sebagai 1 baris "opname" di Kartu Stok (PRD 4.3b).</span>
        </form>
    </div>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Jenis</th>
                        <th>Keterangan</th>
                        <th>Referensi</th>
                        <th class="text-right">Masuk</th>
                        <th class="text-right">Keluar</th>
                        <th class="text-right">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @php($saldo = 0)
                    @forelse ($rows as $r)
                        @php($saldo += $r['qty'])
                        <tr @class(['row-overdue' => $r['jenis'] === 'opname' && $r['qty'] < 0, 'row-warning' => $r['jenis'] === 'opname' && $r['qty'] > 0])>
                            <td>{{ $r['tanggal']->format('d/m/Y H:i') }}</td>
                            <td><span class="badge badge-slate">{{ ucwords(str_replace('_', ' ', $r['jenis'])) }}</span></td>
                            <td>{{ $r['keterangan'] ?? '-' }}</td>
                            <td class="mono text-secondary">{{ $r['referensi'] ?? '-' }}</td>
                            <td class="mono text-right text-lunas-green">{{ $r['qty'] > 0 ? uang($r['qty']) : '' }}</td>
                            <td class="mono text-right text-danger-red">{{ $r['qty'] < 0 ? uang(abs($r['qty'])) : '' }}</td>
                            <td class="mono text-right font-bold">{{ $saldo }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-secondary">Belum ada mutasi untuk barang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
