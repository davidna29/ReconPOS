<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Cetak')</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; color: #111827; font-size: 13px; margin: 0; }
        .invoice { max-width: 640px; margin: 0 auto; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f8fafc; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; }
        .right { text-align: right; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .total-row td { font-weight: 700; background: #f8fafc; }
        .barcode-label { text-align: center; padding: 8px; border: 1px dashed #cbd5e1; }
    </style>
</head>

<body>
    <div class="invoice print-area">
        @yield('content')
    </div>
</body>

</html>
