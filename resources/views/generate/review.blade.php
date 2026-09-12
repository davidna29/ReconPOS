@extends('layouts.app')

@section('title', 'Review Draft Generate')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Review Draft Generate #{{ $batch->id }}</h2>
            <p class="page-head-subtitle">
                Periode {{ $batch->periode_mulai->format('d/m/Y') }} – {{ $batch->periode_selesai->format('d/m/Y') }} ·
                Target <span class="font-bold">{{ rupiah($batch->target_omset) }}</span> ·
                Total Draft <span class="font-bold text-primary">{{ rupiah($batch->total_generate) }}</span> ·
                Selisih <span class="font-bold {{ ($batch->selisih ?? 0) > 0 ? 'text-danger-red' : 'text-lunas-green' }}">{{ ($batch->selisih ?? 0) >= 0 ? '+' : '' }}{{ uang($batch->selisih ?? 0) }}</span>
            </p>
            @php
                $param = $batch->parameter_json ?? [];
                $kapPeak = $param['kapasitasPeak'] ?? $param['kapasitas'] ?? null;
                $kapFinal = $param['kapasitasFinal'] ?? null;
                $sisaTarget = $param['sisa_target'] ?? $batch->target_omset;
                $hariBuka = \Carbon\Carbon::parse($batch->periode_mulai)->daysUntil($batch->periode_selesai)->count() + 1;
            @endphp
            <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
                @if ($kapPeak !== null)
                    <span class="rounded bg-slate-100 px-2 py-1">Kapasitas Peak: <span class="font-bold">{{ rupiah($kapPeak) }}</span></span>
                @endif
                @if ($kapFinal !== null && $kapFinal !== $kapPeak)
                    <span class="rounded bg-slate-100 px-2 py-1">Kapasitas Akhir: <span class="font-bold">{{ rupiah($kapFinal) }}</span></span>
                @endif
                @php($masukTerakhir = $param['masuk_terakhir'] ?? null)
                @if ($masukTerakhir > 0)
                    <span class="rounded bg-slate-100 px-2 py-1" title="Stok masuk hari terakhir periode — aturan H+1 membuatnya tak bisa dijual periode ini">Masuk hari terakhir: <span class="font-bold">{{ rupiah($masukTerakhir) }}</span></span>
                @endif
                <span class="rounded bg-amber-50 px-2 py-1">Sisa Target: {{ rupiah($sisaTarget) }}</span>
                <span class="rounded bg-green-50 px-2 py-1">Hari terisi: {{ $perHari->count() }} hari · {{ $batch->transaksiPenjualan->count() }} nota</span>
            </div>
            @if ($batch->keterangan)
                <p class="mt-1 text-body-sm text-danger-red">{{ $batch->keterangan }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('generate.simpan', $batch) }}" data-confirm="Simpan draft sebagai transaksi resmi? Stok akan terpotong permanen dan sumber ditandai 'rekonstruksi'.">
                @csrf
                <button class="btn btn-success shadow-sm">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">save</span> Simpan (Jadikan Resmi)
                </button>
            </form>
            <a href="{{ route('generate.index') }}" class="btn btn-secondary">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">refresh</span> Generate Ulang (form baru)
            </a>
            <form method="POST" action="{{ route('generate.batal', $batch) }}" data-confirm="Batalkan draft? Tidak ada efek ke stok.">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete_sweep</span> Batalkan Draft
                </button>
            </form>
        </div>
    </div>

    <p class="rounded-lg border border-outline-variant bg-surface-muted px-3 py-2 text-body-sm text-secondary">
        <span class="material-symbols-outlined text-[16px] align-middle text-primary" aria-hidden="true">info</span>
        Draft bersifat reversibel (belum menyentuh stok). Edit baris → klik simpan per baris; hapus nota per hari bila perlu.
    </p>

    @foreach ($perHari as $tanggal => $notas)
        <div class="card animate-rise overflow-hidden" style="--rise-delay:.08s">
            <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 px-4 py-2.5">
                <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">calendar_month</span>
                    {{ \Carbon\Carbon::parse($tanggal)->translatedFormat('l, d F Y') }}
                </h3>
                <span class="text-body-sm text-secondary">{{ $notas->count() }} nota · {{ rupiah($notas->sum('total')) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Nota</th>
                            <th>Jam</th>
                            <th>Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($notas as $trx)
                            @foreach ($trx->items as $idx => $item)
                                <tr>
                                    @if ($idx === 0)
                                        <td rowspan="{{ $trx->items->count() }}" class="mono font-semibold text-primary align-top">{{ $trx->ref_transaksi }}</td>
                                    @endif
                                    @if ($idx === 0)
                                        <td rowspan="{{ $trx->items->count() }}" class="align-top">{{ $trx->tanggal_waktu->format('H:i') }}</td>
                                    @endif
                                    <td>
                                        {{ $item->barang->nama }}
                                        <div class="mono text-[11px] text-secondary">{{ $item->barang->kode_barang }}@if ($item->no_sn) · SN {{ $item->no_sn }}@endif</div>
                                    </td>
                                    <td class="text-center">
                                        <form method="POST" action="{{ route('generate.update-line', $item) }}" class="flex items-center justify-center gap-1">
                                            @csrf
                                            @method('PATCH')
                                            <input type="number" name="qty" value="{{ $item->qty }}" min="1" class="input mono w-16 text-center" {{ $item->no_sn ? 'readonly' : '' }}>
                                            <input type="number" name="harga_jual" value="{{ $item->harga_jual }}" min="1" class="input mono w-24 text-right">
                                            <button class="btn-icon text-cash-blue hover:bg-blue-50" title="Simpan baris"><span class="material-symbols-outlined text-[18px]">check</span></button>
                                        </form>
                                    </td>
                                    <td class="mono text-right">{{ uang($item->harga_jual) }}</td>
                                    <td class="mono text-right font-bold">{{ uang($item->subtotal) }}</td>
                                    <td class="text-right">
                                        @if ($idx === 0)
                                            <form method="POST" action="{{ route('generate.hapus-nota', $trx) }}" data-confirm="Hapus nota {{ $trx->ref_transaksi }}?">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn-icon text-danger-red hover:bg-red-50" title="Hapus nota"><span class="material-symbols-outlined text-[18px]">delete</span></button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <div class="flex justify-end">
        <form method="POST" action="{{ route('generate.simpan', $batch) }}" data-confirm="Simpan draft sebagai transaksi resmi? Stok akan terpotong permanen.">
            @csrf
            <button class="btn btn-success py-2.5">
                <span class="material-symbols-outlined text-[18px]">save</span> Simpan Semua Draft (Jadikan Resmi)
            </button>
        </form>
    </div>
@endsection
