@extends('layouts.print')

@section('title', 'Barcode ' . $barang->kode_barang)

@php
    use Picqer\Barcode\BarcodeGeneratorPNG;
    $gen = new BarcodeGeneratorPNG();
    $image = base64_encode($gen->getBarcode((string) $barang->kode_barang, $gen::TYPE_CODE_128, 2, 48));
@endphp

@section('content')
    <div class="flex items-center justify-between">
        <h1>Label Barcode</h1>
        <p class="muted">{{ now()->format('d/m/Y') }}</p>
    </div>

    <p class="muted">{{ $barang->nama }} — cetak sebanyak yang dibutuhkan (sisip label ganda per unit stok).</p>

    <div style="display:flex; flex-wrap:wrap; gap:16px; margin-top:16px;">
        @for ($i = 0; $i < 8; $i++)
            <div class="barcode-label">
                <div style="font-size:11px; margin-bottom:4px;">{{ $barang->nama }}</div>
                <div><img src="data:image/png;base64,{{ $image }}" alt="barcode"></div>
                <div class="mono" style="font-size:13px; margin-top:4px;">{{ $barang->kode_barang }}</div>
                <div style="font-size:11px; color:#6b7280;">{{ rupiah($barang->harga_jual) }}</div>
            </div>
        @endfor
    </div>

    <div class="no-print" style="margin-top:16px;">
        <button onclick="window.print()" style="padding:8px 16px;">Cetak Label</button>
        <a href="{{ route('barang.index') }}" style="margin-left:8px;">Kembali</a>
    </div>
@endsection
