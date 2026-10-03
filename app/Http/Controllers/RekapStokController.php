<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\TokoSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordFactory;
use PhpOffice\PhpWord\PhpWord;

class RekapStokController extends Controller
{
    /** Ungu primer + hijau total + zebra — bahasa visual Precision Ledger. */
    private const UNGU = '6D28D9';

    private const ZEBRA = 'F5F3FF';

    private const HIJAU_TOTAL = 'E6F4EA';

    private const GARIS = 'C3C6D7';

    public function index(Request $request)
    {
        [$groups, $totalSku, $totalStok, $merks] = self::rekap($request);

        return view('rekap-stok.index', compact('groups', 'totalSku', 'totalStok', 'merks'));
    }

    /**
     * Query + grouping bersama utk admin & publik (lihat Publik\RekapStokController):
     * Merk → Varian → baris SKU (kode, nama, ukuran, harga, stok).
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
        $this->validasiFilter($request);
        [$groups, $totalSku, $totalStok] = self::rekap($request);

        $spreadsheet = new Spreadsheet();
        $this->isiSheetRingkasan($spreadsheet->getActiveSheet(), $groups, $totalSku, $totalStok);
        $this->isiSheetRincian($spreadsheet->createSheet(), $groups);

        $nama = 'rekap-stok-' . now()->format('Ymd') . '.xlsx';

        return $this->unduhSpreadsheet($spreadsheet, $nama, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /**
     * Export rekap + rincian stok ke PDF (A4 landscape).
     */
    public function exportPdf(Request $request)
    {
        $this->validasiFilter($request);
        [$groups, $totalSku, $totalStok] = self::rekap($request);

        $pdf = Pdf::loadView('rekap-stok.export-pdf', [
            'toko' => TokoSetting::utama(),
            'groups' => $groups,
            'totalSku' => $totalSku,
            'totalStok' => $totalStok,
            'filter' => $this->labelFilter($request),
        ])->setPaper('a4', 'landscape');

        $nama = 'rekap-stok-' . now()->format('Ymd') . '.pdf';

        return $this->unduhFile($pdf->output(), $nama, 'application/pdf');
    }

    /**
     * Export rekap + rincian stok ke Word (.docx, landscape).
     * Tabel bergaya: header ungu, zebra, baris total hijau.
     */
    public function exportDocx(Request $request)
    {
        $this->validasiFilter($request);
        [$groups, $totalSku, $totalStok] = self::rekap($request);
        $toko = TokoSetting::utama();

        $phpWord = new PhpWord();
        $phpWord->addTitleStyle(1, ['name' => 'Calibri', 'size' => 18, 'bold' => true, 'color' => self::UNGU]);
        $phpWord->addTitleStyle(2, ['name' => 'Calibri', 'size' => 13, 'bold' => true, 'color' => self::UNGU]);

        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'marginLeft' => 720, 'marginRight' => 720,
            'marginTop' => 720, 'marginBottom' => 720,
        ]);
        $section->addTitle($toko->nama_toko ?: 'ReconPOS', 1);
        if ($toko->alamat) {
            $section->addText($toko->alamat, ['size' => 9, 'color' => '3F4756']);
        }
        $section->addTitle('REKAP STOK PER MERK', 2);
        $section->addText(
            'Snapshot ' . now()->format('d M Y H:i') . ' · ' . $this->labelFilter($request) . " · {$totalSku} SKU · total " . number_format($totalStok, 0, ',', '.') . ' pcs',
            ['size' => 9, 'color' => '3F4756']
        );

        $gayaTabel = ['borderColor' => self::GARIS, 'borderSize' => 6, 'cellMargin' => 80];
        $phpWord->addTableStyle('Ringkasan', $gayaTabel, ['bgColor' => self::UNGU]);
        $phpWord->addTableStyle('Rincian', $gayaTabel, ['bgColor' => self::UNGU]);
        $fontHead = ['name' => 'Calibri', 'size' => 9, 'bold' => true, 'color' => 'FFFFFF'];
        $fontIsi = ['name' => 'Calibri', 'size' => 9];
        $fontTotal = ['name' => 'Calibri', 'size' => 9, 'bold' => true];

        $section->addText('Ringkasan per Merk', ['size' => 11, 'bold' => true]);
        $ringkas = $section->addTable('Ringkasan');
        $ringkas->addRow();
        foreach (['Merk', 'SKU', 'Total Stok'] as $h) {
            $ringkas->addCell(3000)->addText($h, $fontHead);
        }
        foreach ($groups as $i => $g) {
            $ringkas->addRow();
            $sel = $i % 2 === 1 ? ['bgColor' => self::ZEBRA] : [];
            $ringkas->addCell(3000, $sel)->addText($g['merk'], $fontIsi);
            $ringkas->addCell(3000, $sel)->addText((string) $g['total_sku'], $fontIsi);
            $ringkas->addCell(3000, $sel)->addText(number_format($g['total_stok'], 0, ',', '.'), $fontIsi);
        }
        $ringkas->addRow();
        $selTotal = ['bgColor' => self::HIJAU_TOTAL];
        $ringkas->addCell(3000, $selTotal)->addText('GRAND TOTAL', $fontTotal);
        $ringkas->addCell(3000, $selTotal)->addText((string) $totalSku, $fontTotal);
        $ringkas->addCell(3000, $selTotal)->addText(number_format($totalStok, 0, ',', '.'), $fontTotal);

        $section->addTextBreak();
        $section->addText('Rincian SKU', ['size' => 11, 'bold' => true]);
        $rinci = $section->addTable('Rincian');
        $kepala = ['Merk', 'Varian', 'Kode', 'Nama', 'Ukuran', 'Harga Jual', 'Stok'];
        $lebar = [1800, 1800, 1600, 2800, 1300, 1500, 1000];
        $rinci->addRow();
        foreach ($kepala as $i => $h) {
            $rinci->addCell($lebar[$i])->addText($h, $fontHead);
        }
        $no = 0;
        foreach ($groups as $g) {
            foreach ($g['varians'] as $v) {
                foreach ($v['items'] as $b) {
                    $rinci->addRow();
                    $sel = $no % 2 === 1 ? ['bgColor' => self::ZEBRA] : [];
                    $nilai = [
                        $g['merk'], $v['varian'], $b->kode_barang, $b->nama,
                        $b->ukuran_ban ?? '-', number_format((int) $b->harga_jual, 0, ',', '.'), (string) (int) $b->stock,
                    ];
                    foreach ($nilai as $i => $teks) {
                        $rinci->addCell($lebar[$i], $sel)->addText($teks, $fontIsi);
                    }
                    $no++;
                }
            }
        }

        $section->addFooter()->addPreserveText(
            'Dicetak ' . now()->format('d M Y H:i') . ' · ReconPOS · Halaman {PAGE} dari {NUMPAGES}',
            ['size' => 8, 'color' => '3F4756']
        );

        $nama = 'rekap-stok-' . now()->format('Ymd') . '.docx';
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('His') . '.docx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        WordFactory::createWriter($phpWord, 'Word2007')->save($path);

        return response()->download($path, $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    private function validasiFilter(Request $request): void
    {
        $request->validate([
            'merk' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'sembunyikan_kosong' => ['nullable', 'boolean'],
        ]);
    }

    private function labelFilter(Request $request): string
    {
        $label = trim((string) $request->get('merk')) !== '' ? 'Merk: ' . $request->get('merk') : 'Semua merk';
        if (trim((string) $request->get('q')) !== '') {
            $label .= ' · Cari: ' . $request->get('q');
        }
        if ($request->boolean('sembunyikan_kosong')) {
            $label .= ' · Tanpa stok 0';
        }

        return $label;
    }

    private function unduhSpreadsheet(Spreadsheet $spreadsheet, string $nama, string $mime)
    {
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('His') . '.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $nama, ['Content-Type' => $mime])
            ->deleteFileAfterSend(true);
    }

    private function unduhFile(string $isi, string $nama, string $mime)
    {
        $path = storage_path('app/private/export/' . pathinfo($nama, PATHINFO_FILENAME) . '-' . now()->format('His') . '.' . pathinfo($nama, PATHINFO_EXTENSION));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $isi);

        // Header eksplisit agar Symfony melewati penebak MIME (hemat memori).
        return response()->download($path, $nama, ['Content-Type' => $mime])
            ->deleteFileAfterSend(true);
    }

    private function gayaKepala($sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::UNGU);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function gayaBingkai($sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::GARIS);
    }

    private function aturCetak($sheet): void
    {
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
    }

    private function isiSheetRingkasan($sheet, $groups, int $totalSku, int $totalStok): void
    {
        $sheet->setTitle('Ringkasan');
        $sheet->mergeCells('A1:C1');
        $sheet->setCellValue('A1', 'REKAP STOK PER MERK');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::UNGU);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->mergeCells('A2:C2');
        $sheet->setCellValue('A2', 'Snapshot ' . now()->format('d M Y H:i') . '  ·  ' . $totalSku . ' SKU  ·  total ' . number_format($totalStok, 0, ',', '.') . ' pcs');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('3F4756');

        $headers = ['Merk', 'SKU', 'Total Stok'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 4], $h);
        }
        $this->gayaKepala($sheet, 'A4:C4');
        $sheet->getRowDimension(4)->setRowHeight(20);
        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:C4');

        $baris = 5;
        foreach ($groups as $i => $g) {
            $sheet->fromArray([$g['merk'], $g['total_sku'], $g['total_stok']], null, "A{$baris}");
            if ($i % 2 === 1) {
                $sheet->getStyle("A{$baris}:C{$baris}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ZEBRA);
            }
            $baris++;
        }
        $sheet->fromArray(['GRAND TOTAL', $totalSku, $totalStok], null, "A{$baris}");
        $sheet->getStyle("A{$baris}:C{$baris}")->getFont()->setBold(true);
        $sheet->getStyle("A{$baris}:C{$baris}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HIJAU_TOTAL);
        $sheet->getStyle("A{$baris}:C{$baris}")->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_DOUBLE)->getColor()->setRGB(self::UNGU);
        $sheet->getStyle("B5:C{$baris}")->getNumberFormat()->setFormatCode('#,##0');
        $this->gayaBingkai($sheet, "A4:C{$baris}");
        foreach (range('A', 'C') as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
        $this->aturCetak($sheet);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 4);
    }

    private function isiSheetRincian($sheet, $groups): void
    {
        $sheet->setTitle('Rincian');
        $headers = ['Merk', 'Varian', 'Kode', 'Nama', 'Ukuran', 'Harga Jual', 'Stok'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $this->gayaKepala($sheet, 'A1:G1');
        $sheet->getRowDimension(1)->setRowHeight(20);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:G1');

        $no = 2;
        $barisData = 0;
        foreach ($groups as $g) {
            foreach ($g['varians'] as $v) {
                foreach ($v['items'] as $b) {
                    $sheet->fromArray([
                        $g['merk'], $v['varian'], $b->kode_barang, $b->nama,
                        $b->ukuran_ban ?? '-', (int) $b->harga_jual, (int) $b->stock,
                    ], null, "A{$no}");
                    if ($barisData % 2 === 1) {
                        $sheet->getStyle("A{$no}:G{$no}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ZEBRA);
                    }
                    $no++;
                    $barisData++;
                }
            }
        }
        $akhir = max(2, $no - 1);
        $sheet->getStyle("F2:G{$akhir}")->getNumberFormat()->setFormatCode('#,##0');
        $this->gayaBingkai($sheet, "A1:G{$akhir}");
        foreach (range('A', 'G') as $kol) {
            $sheet->getColumnDimension($kol)->setAutoSize(true);
        }
        $this->aturCetak($sheet);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
    }
}
