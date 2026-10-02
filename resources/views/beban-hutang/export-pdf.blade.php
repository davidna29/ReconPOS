<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Beban Hutang — {{ $toko->nama_toko }}</title>
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
        .footer { margin-top: 8px; text-align: right; font-size: 8px; color: #3f4756; }
    </style>
</head>
<body>
    <div class="kop">
        <h1>{{ $toko->nama_toko ?: 'ReconPOS' }}</h1>
        @if ($toko->alamat)<p>{{ $toko->alamat }}</p>@endif
        @if ($toko->telepon)<p>Telp: {{ $toko->telepon }}</p>@endif
    </div>
    <h2>REKAP BEBAN HUTANG PER CABANG</h2>
    <p class="sub">Periode {{ $periode }} · Hutang = total termasuk PPN · Dibayar = DP + seluruh cicilan kini</p>

    <table class="data">
        <thead>
            <tr>
                <th>Cabang</th>
                <th>Lembar</th>
                <th>Hutang (incl PPN)</th>
                <th>Dibayar</th>
                <th>Sisa</th>
                <th>Lunas</th>
                <th>Belum Lunas</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cards as $c)
                <tr>
                    <td>{{ $c['cabang'] }}</td>
                    <td class="num">{{ $c['lembar'] }}</td>
                    <td class="num">{{ number_format($c['hutang'], 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($c['dibayar'], 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($c['sisa'], 0, ',', '.') }}</td>
                    <td class="num">{{ $c['lunas'] }}</td>
                    <td class="num">{{ $c['belum_lunas'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>GRAND TOTAL</td>
                <td class="num">{{ $grand['lembar'] }}</td>
                <td class="num">{{ number_format($grand['hutang'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($grand['dibayar'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($grand['sisa'], 0, ',', '.') }}</td>
                <td class="num">{{ $grand['lunas'] }}</td>
                <td class="num"></td>
            </tr>
        </tbody>
    </table>

    <h2>RINCIAN FAKTUR</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>No Invoice</th>
                <th>Supplier</th>
                <th>Cabang</th>
                <th>Hutang (incl PPN)</th>
                <th>DP</th>
                <th>Cicilan</th>
                <th>Sisa</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($baris as $b)
                <tr>
                    <td>{{ $b['tanggal'] }}</td>
                    <td>{{ $b['no_invoice'] }}</td>
                    <td>{{ $b['supplier'] }}</td>
                    <td>{{ $b['cabang'] }}</td>
                    <td class="num">{{ number_format($b['hutang'], 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($b['dp'], 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($b['cicilan'], 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($b['sisa'], 0, ',', '.') }}</td>
                    <td>{{ $b['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footer">Dicetak {{ now()->format('d M Y H:i') }} · ReconPOS</p>
</body>
</html>
