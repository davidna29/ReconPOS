<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\TokoSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class RekapStokController extends Controller
{
    public function index(Request $request)
    {
        [$groups, $totalSku, $totalStok, $merks] = self::rekap($request);

        return view('rekap-stok.index', compact('groups', 'totalSku', 'totalStok', 'merks'));
    }

    /**
     * Query + grouping bersama utk admin & publik (lihat Publik\RekapStokController):
     * Merk → Varian → baris SKU (kode, nama, ukuran, stok).
     *
     * @return array{0: \Illuminate\Support\Collection, 1: int, 2: int, 3: \Illuminate\Support\Collection}
     */
    public static function rekap(Request $request): array
    {
        $query = Barang::where('tipe_item', 'barang');

        if (($merk = trim((string) $request->get('merk'))) !== '') {
            $query->where('merk', $merk);
        }
        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('kode_barang', 'like', "%{$s}%")
                ->orWhere('nama', 'like', "%{$s}%")
                ->orWhere('ukuran_ban', 'like', "%{$s}%"));
        }

        $barangs = $query->orderBy('merk')->orderBy('varian')
            ->orderBy('ukuran_ban')->orderBy('kode_barang')->get();

        if ($request->boolean('sembunyikan_kosong')) {
            $barangs = $barangs->filter(fn ($b) => $b->stock > 0)->values();
        }

        $groups = $barangs->groupBy(fn ($b) => $b->labelMerk())
            ->map(fn ($items, $namaMerk) => [
                'merk' => $namaMerk,
                'total_sku' => $items->count(),
                'total_stok' => (int) $items->sum('stock'),
                'varians' => $items
                    ->groupBy(fn ($b) => trim((string) $b->varian) !== '' ? $b->varian : '-')
                    ->map(fn ($vs, $namaVarian) => [
                        'varian' => $namaVarian,
                        'total_stok' => (int) $vs->sum('stock'),
                        'items' => $vs->values(),
                    ])->values(),
            ])
            // 'Tanpa Merk' selalu paling bawah
            ->sortBy(fn ($g) => $g['merk'] === 'Tanpa Merk' ? '~~~' : $g['merk'])
            ->values();

        $merks = Barang::where('tipe_item', 'barang')
            ->whereNotNull('merk')->where('merk', '<>', '')
            ->distinct()->orderBy('merk')->pluck('merk');

        return [$groups, $barangs->count(), (int) $barangs->sum('stock'), $merks];
    }

    /**
     * Export rekap + rincian stok ke Excel (2 sheet).
     * Snapshot terfilter apa adanya (ikut merk/cari/sembunyikan stok 0).
     */
    public function exportXlsx(Request $request)
    {
        $request->validate([
            'merk' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'sembunyikan_kosong' => ['nullable', 'boolean'],
        ]);
        [$groups, $totalSku, $totalStok] = self::rekap($request);

        $spreadsheet = new Spreadsheet();
        $this->isiSheetRingkasan($spreadsheet->getActiveSheet(), $groups, $totalSku, $totalStok);
        $this->isiSheetRincian($spreadsheet->createSheet(), $groups);

        $nama = 'rekap-stok-' . now()->format('Ymd') . '.xlsx';
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('His') . '.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Export rekap + rincian stok ke PDF (A4 landscape).
     */
    public function exportPdf(Request $request)
    {
        $request->validate([
            'merk' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'sembunyikan_kosong' => ['nullable', 'boolean'],
        ]);
        [$groups, $totalSku, $totalStok] = self::rekap($request);

        $pdf = Pdf::loadView('rekap-stok.export-pdf', [
            'toko' => TokoSetting::utama(),
            'groups' => $groups,
            'totalSku' => $totalSku,
            'totalStok' => $totalStok,
            'filter' => trim((string) $request->get('merk')) !== '' ? 'Merk: ' . $request->get('merk') : 'Semua merk',
        ])->setPaper('a4', 'landscape');

        $nama = 'rekap-stok-' . now()->format('Ymd') . '.pdf';
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('His') . '.pdf');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $pdf->output());

        // Header eksplisit agar Symfony melewati penebak MIME (hemat memori).
        return response()->download($path, $nama, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    private function isiSheetRingkasan($sheet, $groups, int $totalSku, int $totalStok): void
    {
        $sheet->setTitle('Ringkasan');
        $sheet->setCellValue('A1', 'REKAP STOK PER MERK');
        $sheet->setCellValue('A2', 'Snapshot ' . now()->format('d M Y H:i'));
        $headers = ['Merk', 'SKU', 'Total Stok'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 4], $h);
        }
        $sheet->getStyle('A4:C4')->getFont()->setBold(true);
        $sheet->getStyle('A4:C4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4EA');

        $baris = 5;
        foreach ($groups as $g) {
            $sheet->fromArray([$g['merk'], $g['total_sku'], $g['total_stok']], null, "A{$baris}");
            $baris++;
        }
        $sheet->fromArray(['GRAND TOTAL', $totalSku, $totalStok], null, "A{$baris}");
        $sheet->getStyle("A{$baris}:C{$baris}")->getFont()->setBold(true);
        $sheet->getStyle("B5:C{$baris}")->getNumberFormat()->setFormatCode('#,##0');
        foreach (range('A', 'C') as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
    }

    private function isiSheetRincian($sheet, $groups): void
    {
        $sheet->setTitle('Rincian');
        $headers = ['Merk', 'Varian', 'Kode', 'Nama', 'Ukuran', 'Stok'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4EA');

        $no = 2;
        foreach ($groups as $g) {
            foreach ($g['varians'] as $v) {
                foreach ($v['items'] as $b) {
                    $sheet->fromArray([
                        $g['merk'], $v['varian'], $b->kode_barang, $b->nama,
                        $b->ukuran_ban ?? '-', (int) $b->stock,
                    ], null, "A{$no}");
                    $no++;
                }
            }
        }
        $sheet->getStyle("F2:F{$no}")->getNumberFormat()->setFormatCode('#,##0');
        foreach (range('A', 'F') as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
    }
}
