<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Cetak') — ReconPOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; color: #111827; font-size: 13px; margin: 0; background: #fff; }
        .invoice { max-width: 640px; margin: 0 auto; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f8fafc; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; }
        .right { text-align: right; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
        .kop { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #6d28d9; }
        .kop-mark { width: 36px; height: 36px; border-radius: 8px; background: #6d28d9; color: #fff; font-weight: 800; font-size: 18px; display: flex; align-items: center; justify-content: center; }
        .kop-name { font-size: 16px; font-weight: 700; color: #111827; }
        .kop-sub { font-size: 11px; color: #6b7280; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .total-row td { font-weight: 700; background: #f8fafc; }
        .barcode-label { text-align: center; padding: 8px; border: 1px dashed #cbd5e1; }
        /* Bulk print: default page break per nota; toggle .pb-off mematikan */
        .print-area .print-page { page-break-after: always; }
        .print-area.pb-off .print-page { page-break-after: auto; }
        .print-page:last-child { page-break-after: auto; }
        /* Ruang atas-bawah antar nota agar tidak menempel */
        .print-page { padding: 24px 0; }
        /* Kontrol toggle "Halaman baru per nota" (tidak ikut tercetak) */
        .bulk-controls {
            position: fixed; top: 12px; right: 12px; z-index: 50;
            display: flex; align-items: center; gap: 8px;
            background: #fff; border: 1px solid #cbd5e1; border-radius: 8px;
            padding: 6px 10px; font-size: 12px; color: #111827;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
        }
        @media print {
            .invoice { break-inside: avoid; }
            .bulk-controls { display: none !important; }
        }
    </style>
</head>

<body>
    <div class="invoice print-area">
        @hasSection('kop')
            @yield('kop')
        @endif
        @yield('content')
    </div>
</body>

</html>
