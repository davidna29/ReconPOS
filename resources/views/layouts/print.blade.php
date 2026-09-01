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
        @yield('content')
    </div>
</body>

</html>
