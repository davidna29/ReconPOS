<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tracking Stok — {{ $toko->nama_toko }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #191b23; }
        .kop { text-align: center; margin-bottom: 6px; }
        .kop h1 { font-size: 17px; color: #6d28d9; margin: 0; }
        .kop p { margin: 1px 0; font-size: 9px; color: #3f4756; }
        h2 { font-size: 13px; text-align: center; margin: 8px 0 2px; }
        .sub { text-align: center; font-size: 9px; color: #3f4756; margin-bottom: 8px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data th { background: #6d28d9; color: #ffffff; padding: 4px 5px; border: 1px solid #6d28d9; font-size: 9px; }
        table.data td { border: 1px solid #c3c6d7; padding: 3px 5px; font-size: 9px; }
        table.data tr:nth-child(even) td { background: #f3f3fe; }
        table.data td.num { text-align: right; }
        table.data tr.total td { font-weight: bold; background: #e6f4ea; }
        .bulan { page-break-before: always; }
        .bulan:first-of-type { page-break-before: auto; }
        .footer { margin-top: 8px; text-align: right; font-size: 8px; color: #3f4756; }
    </style>
</head>
<body>
    <div class="kop">
        <h1>{{ $toko->nama_toko ?: 'ReconPOS' }}</h1>
        @if ($toko->alamat)<p>{{ $toko->alamat }}</p>@endif
        @if ($toko->telepon)<p>Telp: {{ $toko->telepon }}</p>@endif
    </div>
    <h2>TRACKING STOK (FIFO)</h2>
    <p class="sub">Snapshot {{ now()->format('d M Y H:i') }} · {{ $ringkasan['Cakupan'] }} · {{ $ringkasan['Batch tersisa'] }} · sisa {{ number_format($ringkasan['Total sisa'], 0, ',', '.') }}</p>

    <table class="data">
        <thead>
            <tr>
                <th>Cakupan</th>
                <th>Batch</th>
                <th>Total Sisa</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($perBulan as $bulan => $rows)
                <tr>
                    <td>{{ $bulan }}</td>
                    <td class="num">{{ $rows->count() }}</td>
                    <td class="num">{{ number_format((int) $rows->sum('qty_sisa'), 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>GRAND TOTAL</td>
                <td class="num">{{ $ringkasan['Batch tersisa'] }}</td>
                <td class="num">{{ number_format($ringkasan['Total sisa'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    @foreach ($perBulan as $bulan => $rows)
        <div class="bulan">
            <h2>BULAN {{ $bulan }} — {{ $rows->count() }} batch, sisa {{ number_format((int) $rows->sum('qty_sisa'), 0, ',', '.') }}</h2>
            <table class="data">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Kode</th>
                        <th>Sumber</th>
                        <th>Tanggal Masuk</th>
                        <th>Qty Awal</th>
                        <th>Sisa</th>
                        <th>Umur (hari)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td>{{ $r['barang']->nama }}</td>
                            <td>{{ $r['barang']->kode_barang }}</td>
                            <td>{{ $r['jenis'] === 'masuk_pembelian' && $r['invoice_id'] ? 'Pembelian' : ($r['jenis'] === 'stok_awal' ? 'Stok Awal' : 'Penyesuaian') }}</td>
                            <td>{{ $r['tanggal']->format('Y-m-d H:i') }}</td>
                            <td class="num">{{ number_format((int) $r['qty_awal'], 0, ',', '.') }}</td>
                            <td class="num">{{ number_format((int) $r['qty_sisa'], 0, ',', '.') }}</td>
                            <td class="num">{{ $r['umur_hari'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <p class="footer">Dicetak {{ now()->format('d M Y H:i') }} · ReconPOS</p>
</body>
</html>
