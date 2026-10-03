<?php

namespace App\Http\Controllers;

use App\Models\TokoSetting;
use App\Services\StokTrackingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class StokTrackingController extends Controller
{
    public function __construct(private StokTrackingService $stok) {}

    public function index(Request $request)
    {
        [$totalStok, $totalSku] = $this->stok->total();
        $availableMonths = $this->stok->bulanTersedia();
        [$selectedMonths, $dibatasi] = $this->stok->pilihBulan($availableMonths, $request->input('months'));

        // FIFO hanya atas batch bulan terpilih (masuk difilter di SQL).
        [$dari, $sampai] = StokTrackingService::rentang($selectedMonths);
        $filtered = $this->stok->saringDanUmur($this->stok->hasilFifo($dari, $sampai), $selectedMonths);

        $entries = (int) $request->get('entries', 10);
        if (! in_array($entries, [10, 25, 50, 100], true)) {
            $entries = 10;
        }
        $paginator = $this->stok->paginasi($filtered, $entries, $request->url(), $request->query());
        $grouped = $paginator->getCollection()->groupBy(fn ($r) => $r['tanggal']->format('Y-m'));
        // Angka header grup = scope filter penuh (bukan potongan halaman).
        $ringkasBulan = $filtered->groupBy(fn ($r) => $r['tanggal']->format('Y-m'))
            ->map(fn ($rows) => [
                'batch' => $rows->count(),
                'sisa' => (int) $rows->sum('qty_sisa'),
                'sku' => $rows->pluck('barang.id')->unique()->count(),
            ])
            ->sortKeys();

        return view('stok-tracking.index', compact(
            'grouped', 'availableMonths', 'selectedMonths',
            'totalStok', 'totalSku', 'paginator', 'entries', 'dibatasi', 'ringkasBulan',
        ));
    }

    /**
     * Export aging FIFO ke Excel (2 sheet). Cakupan = months[] bila ada,
     * tanpa param = SEMUA bulan (bypass cap-2 tampilan layar).
     * Pre-flight menolak estimasi baris raksasa sebelum kerja berat.
     */
    public function exportXlsx(Request $request)
    {
        return $this->unduh($request, 'xlsx');
    }

    /** Export aging FIFO ke PDF (A4 landscape, page-break per bulan). */
    public function exportPdf(Request $request)
    {
        return $this->unduh($request, 'pdf');
    }

    private function unduh(Request $request, string $format)
    {
        $request->validate(['months' => ['nullable', 'array'], 'months.*' => ['date_format:Y-m']]);

        $tersedia = $this->stok->bulanTersedia();
        $cakupan = $request->input('months') ?? $tersedia->all();
        $cakupan = array_values(array_filter(
            (array) $cakupan,
            fn ($m) => is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m)
        ));
        [$dari, $sampai] = StokTrackingService::rentang($cakupan);
        $label = $dari ? "{$dari}_sd_{$sampai}" : 'semua-bulan';

        $ambang = $format === 'pdf'
            ? StokTrackingService::AMBANG_BARIS_PDF
            : StokTrackingService::AMBANG_BARIS_EXPORT;
        $estimasi = $this->stok->estimasiBaris($dari, $sampai);
        if ($estimasi > $ambang) {
            return back()->with('error', "Export dibatalkan: estimasi ±{$estimasi} baris melebihi batas aman {$ambang} baris " . ($format === 'pdf' ? 'PDF' : 'Excel') . ' (risiko timeout). Persempit via filter bulan di layar — layar dibatasi 2 bulan.');
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(120);
        }
        $baris = $this->stok->saringDanUmur($this->stok->hasilFifo($dari, $sampai), $cakupan);
        $perBulan = $baris->groupBy(fn ($r) => $r['tanggal']->format('Y-m'))->sortKeys();
        $ringkasan = [
            'Cakupan' => $dari ? "{$dari} s.d. {$sampai}" : 'Semua bulan',
            'Batch tersisa' => $baris->count() . ' batch',
            'Total sisa' => (int) $baris->sum('qty_sisa'),
        ];

        $nama = "stok-tracking-{$label}.{$format}";
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('Ymd-His') . '.' . $format);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet();
            $this->isiSheetRingkasan($spreadsheet->getActiveSheet(), $ringkasan, $perBulan);
            $this->isiSheetRincian($spreadsheet->createSheet(), $baris);
            (new XlsxWriter($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();

            return response()->download($path, $nama, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $pdf = Pdf::loadView('stok-tracking.export-pdf', [
            'toko' => TokoSetting::utama(),
            'perBulan' => $perBulan,
            'ringkasan' => $ringkasan,
        ])->setPaper('a4', 'landscape');
        file_put_contents($path, $pdf->output());

        // Header eksplisit agar Symfony melewati penebak MIME (hemat memori).
        return response()->download($path, $nama, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    private function isiSheetRingkasan($sheet, array $ringkasan, $perBulan): void
    {
        $sheet->setTitle('Ringkasan');
        $sheet->setCellValue('A1', 'TRACKING STOK (FIFO) — snapshot ' . now()->format('d M Y H:i'));
        $baris = 3;
        foreach ($ringkasan as $label => $nilai) {
            $sheet->setCellValue("A{$baris}", $label);
            $sheet->setCellValue("B{$baris}", $nilai);
            $baris++;
        }
        $baris++;
        foreach (['Bulan', 'Batch', 'Total Sisa'] as $i => $h) {
            $sheet->setCellValue([$i + 1, $baris], $h);
        }
        $sheet->getStyle("A{$baris}:C{$baris}")->getFont()->setBold(true);
        $sheet->getStyle("A{$baris}:C{$baris}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4EA');
        $baris++;
        foreach ($perBulan as $bulan => $rows) {
            $sheet->fromArray([$bulan, $rows->count(), (int) $rows->sum('qty_sisa')], null, "A{$baris}");
            $baris++;
        }
        foreach (['A', 'B'] as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
        $sheet->getColumnDimension('C')->setAutoSize(true);
    }

    private function isiSheetRincian($sheet, $baris): void
    {
        $sheet->setTitle('Rincian');
        $headers = ['Bulan', 'Nama', 'Kode', 'Sumber', 'Tanggal Masuk', 'Qty Awal', 'Sisa', 'Umur (hari)'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4EA');

        // Tulis per 500 baris agar memori tetap datar.
        $no = 2;
        foreach ($baris->chunk(500) as $potongan) {
            foreach ($potongan as $r) {
                $sheet->fromArray([
                    $r['tanggal']->format('Y-m'), $r['barang']->nama, $r['barang']->kode_barang,
                    $this->labelSumber($r), $r['tanggal']->format('Y-m-d H:i'),
                    (int) $r['qty_awal'], (int) $r['qty_sisa'], (int) $r['umur_hari'],
                ], null, "A{$no}");
                $no++;
            }
        }
        foreach (['F', 'G', 'H'] as $kol) {
            $sheet->getStyle("{$kol}2:{$kol}{$no}")->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (range('A', 'H') as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
    }

    private function labelSumber(array $r): string
    {
        return match (true) {
            $r['jenis'] === 'masuk_pembelian' && $r['invoice_id'] => 'Pembelian',
            $r['jenis'] === 'stok_awal' => 'Stok Awal',
            default => 'Penyesuaian',
        };
    }
}
