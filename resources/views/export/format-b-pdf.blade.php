<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Penjualan — {{ $toko->nama_toko }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #191b23; }
        .kop { text-align: center; margin-bottom: 6px; }
        .kop h1 { font-size: 18px; color: #6d28d9; margin: 0; }
        .kop p { margin: 1px 0; font-size: 10px; color: #3f4756; }
        h2 { font-size: 14px; text-align: center; margin: 8px 0 4px; }
        .ringkasan { width: 60%; margin: 0 auto 10px; border-collapse: collapse; }
        .ringkasan td { border: 1px solid #c3c6d7; padding: 4px 8px; }
        .ringkasan td:first-child { font-weight: bold; background: #e6f4ea; width: 55%; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #6d28d9; color: #ffffff; padding: 5px 6px; border: 1px solid #6d28d9; font-size: 10px; }
        table.data td { border: 1px solid #c3c6d7; padding: 4px 6px; font-size: 10px; }
        table.data tr:nth-child(even) td { background: #f3f3fe; }
        table.data td.num { text-align: right; }
        table.data tr.total td { font-weight: bold; background: #e6f4ea; }
        .footer { margin-top: 8px; text-align: right; font-size: 9px; color: #3f4756; }
    </style>
</head>
<body>
    <div class="kop">
        <h1>{{ $toko->nama_toko ?: 'ReconPOS' }}</h1>
        @if ($toko->alamat)<p>{{ $toko->alamat }}</p>@endif
        @if ($toko->telepon)<p>Telp: {{ $toko->telepon }}</p>@endif
        @if ($toko->npwp)<p>NPWP: {{ $toko->npwp }}</p>@endif
    </div>
    <h2>LAPORAN PENJUALAN — {{ $ringkasan['Periode'] ?? '' }}</h2>

    <table class="ringkasan">
        @foreach ($ringkasan as $label => $nilai)
            @if ($label === 'Periode')
                @continue
            @endif
            <tr>
                <td>{{ $label }}</td>
                <td>{{ is_numeric($nilai) ? number_format((int) $nilai, 0, ',', '.') : $nilai }}</td>
            </tr>
        @endforeach
    </table>

    <table class="data">
        <thead>
            <tr>
                @foreach ($mapping as $m)
                    <th>{{ $m['nama_kolom'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($mapping as $m)
                        @php($v = $row[$m['nama_kolom']] ?? '')
                        <td class="{{ ($m['format'] ?? '') === 'int' ? 'num' : '' }}">{{ ($m['format'] ?? '') === 'int' && is_numeric($v) ? number_format((int) $v, 0, ',', '.') : $v }}</td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="6">TOTAL OMSET</td>
                <td class="num">{{ number_format((int) ($ringkasan['Total Omset Periode'] ?? 0), 0, ',', '.') }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <p class="footer">Dicetak {{ now()->format('d/m/Y H:i') }} · ReconPOS</p>
</body>
</html>
