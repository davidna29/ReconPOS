@extends('layouts.app')

@section('title', 'Review Draft Generate')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Review Draft Generate #{{ $batch->id }}</h2>
            <p class="mt-1 text-body-md text-secondary">
                Periode {{ $batch->periode_mulai->format('d/m/Y') }} – {{ $batch->periode_selesai->format('d/m/Y') }} ·
                Target <span class="font-bold">{{ rupiah($batch->target_omset) }}</span> ·
                Total Draft <span class="font-bold text-primary">{{ rupiah($batch->total_generate) }}</span> ·
                Selisih <span class="font-bold {{ ($batch->selisih ?? 0) > 0 ? 'text-danger-red' : 'text-lunas-green' }}">{{ ($batch->selisih ?? 0) >= 0 ? '+' : '' }}{{ uang($batch->selisih ?? 0) }}</span>
            </p>
            @if ($batch->keterangan)
                <p class="mt-1 text-body-sm text-danger-red">{{ $batch->keterangan }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('generate.simpan', $batch) }}" data-confirm="Simpan draft sebagai transaksi resmi? Stok akan terpotong permanen dan sumber ditandai 'rekonstruksi'.">
                @csrf
                <button class="btn btn-success">
                    <span class="material-symbols-outlined text-[18px]">save</span> Simpan (Jadikan Resmi)
                </button>
            </form>
            <a href="{{ route('generate.index') }}" class="btn btn-secondary">
                <span class="material-symbols-outlined text-[18px]">refresh</span> Generate Ulang (form baru)
            </a>
            <form method="POST" action="{{ route('generate.batal', $batch) }}" data-confirm="Batalkan draft? Tidak ada efek ke stok.">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger">
                    <span class="material-symbols-outlined text-[18px]">delete_sweep</span> Batalkan Draft
                </button>
            </form>
        </div>
    </div>

    <p class="text-body-sm text-secondary">
        Draft bersifat reversibel (belum menyentuh stok). Edit baris → klik simpan per baris; hapus nota per hari bila perlu.
    </p>

    @foreach ($perHari as $tanggal => $notas)
        <div class="card">
            <div class="flex items-center justify-between border-b border-outline-variant bg-slate-50 px-4 py-2.5">
                <h3 class="text-headline-sm">{{ \Carbon\Carbon::parse($tanggal)->translatedFormat('l, d F Y') }}</h3>
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
