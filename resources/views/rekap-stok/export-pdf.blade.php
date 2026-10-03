<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap Stok — {{ $toko->nama_toko }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #191b23; }
        .kop { text-align: center; margin-bottom: 4px; }
        .kop h1 { font-size: 20px; color: #6d28d9; margin: 0; }
        .kop p { margin: 1px 0; font-size: 9px; color: #3f4756; }
        .kop-garis { border: 0; border-top: 3px double #6d28d9; margin: 4px 0 8px; }
        h2 { font-size: 13px; text-align: center; margin: 8px 0 2px; color: #6d28d9; }
        .sub { text-align: center; font-size: 9px; color: #3f4756; background: #f5f3ff; border: 1px solid #c3c6d7; padding: 4px 8px; margin-bottom: 8px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data th { background: #6d28d9; color: #ffffff; padding: 5px 6px; border: 1px solid #6d28d9; font-size: 9px; }
        table.data td { border: 1px solid #c3c6d7; padding: 3px 5px; font-size: 9px; }
        table.data tr:nth-child(even) td { background: #f5f3ff; }
        table.data td.num { text-align: right; }
        table.data tr.total td { font-weight: bold; background: #e6f4ea; border-top: 2px solid #6d28d9; }
        .footer { margin-top: 8px; text-align: right; font-size: 8px; color: #3f4756; }
        .pagenum:before { content: counter(page); }
    </style>
</head>
<body>
    <div class="kop">
        <h1>{{ $toko->nama_toko ?: 'ReconPOS' }}</h1>
        @if ($toko->alamat)<p>{{ $toko->alamat }}</p>@endif
        @if ($toko->telepon)<p>Telp: {{ $toko->telepon }}</p>@endif
    </div>
    <hr class="kop-garis">
    <h2>REKAP STOK PER MERK</h2>
    <p class="sub">Snapshot {{ now()->format('d M Y H:i') }} · {{ $filter }} · {{ $totalSku }} SKU · total {{ number_format($totalStok, 0, ',', '.') }} pcs</p>

    <table class="data">
        <thead>
            <tr>
                <th>Merk</th>
                <th>SKU</th>
                <th>Total Stok</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($groups as $g)
                <tr>
                    <td>{{ $g['merk'] }}</td>
                    <td class="num">{{ $g['total_sku'] }}</td>
                    <td class="num">{{ number_format($g['total_stok'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>GRAND TOTAL</td>
                <td class="num">{{ $totalSku }}</td>
                <td class="num">{{ number_format($totalStok, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <h2>RINCIAN SKU</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Merk</th>
                <th>Varian</th>
                <th>Kode</th>
                <th>Nama</th>
                <th>Ukuran</th>
                <th>Harga Jual</th>
                <th>Stok</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($groups as $g)
                @foreach ($g['varians'] as $v)
                    @foreach ($v['items'] as $b)
                        <tr>
                            <td>{{ $g['merk'] }}</td>
                            <td>{{ $v['varian'] }}</td>
                            <td>{{ $b->kode_barang }}</td>
                            <td>{{ $b->nama }}</td>
                            <td>{{ $b->ukuran_ban ?? '-' }}</td>
                            <td class="num">{{ number_format((int) $b->harga_jual, 0, ',', '.') }}</td>
                            <td class="num">{{ $b->stock }}</td>
                        </tr>
                    @endforeach
                @endforeach
            @endforeach
        </tbody>
    </table>

    <p class="footer">Dicetak {{ now()->format('d M Y H:i') }} · ReconPOS · Halaman <span class="pagenum"></span></p>
</body>
</html>
