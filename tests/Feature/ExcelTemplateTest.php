<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\KategoriBarang;
use App\Services\ExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class ExcelTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function setupData(): array
    {
        $kat = KategoriBarang::create(['nama' => 'Oli & Pelumas']);
        KategoriBarang::create(['nama' => 'Filter']);

        $a = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'T-001', 'nama' => 'Oli Mesin',
            'kategori_id' => $kat->id, 'satuan' => 'pcs', 'sn_atau_non_sn' => 'NON_SN',
            'harga_jual' => 50000, 'stock' => 8, 'lokasi_rak' => 'Rak A1',
            'status' => 'Dijual & Untuk Servis', 'mode_harga_jual' => 'otomatis',
        ]);
        $b = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'T-002', 'nama' => 'Filter Udara',
            'kategori_id' => null, 'satuan' => 'pcs', 'sn_atau_non_sn' => 'SN',
            'harga_jual' => 70000, 'stock' => 2, 'lokasi_rak' => '',
            'status' => 'Dijual', 'mode_harga_jual' => 'manual_terkunci',
        ]);

        return [$a, $b];
    }

    public function test_template_memuat_semua_data_existing(): void
    {
        [$a, $b] = $this->setupData();
        $path = app(ExcelImportService::class)->templateBarang();

        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $this->assertCount(3, $rows); // header + 2 barang
        $values = array_values($rows);
        $this->assertSame('KODE_BARANG', $values[0]['A']);
        $this->assertSame('T-001', $values[1]['A']);
        $this->assertSame('Oli Mesin', $values[1]['B']);
        $this->assertSame('Oli & Pelumas', $values[1]['D']);
        $this->assertTrue(empty($values[1]['H'])); // STOCK_AWAL existing kosong (sel kosong = null)
        $this->assertSame('T-002', $values[2]['A']);
        $this->assertSame('SN', $values[2]['F']);
    }

    public function test_template_punya_dropdown_validasi(): void
    {
        $this->setupData();
        $path = app(ExcelImportService::class)->templateBarang();

        $reader = new XlsxReader(); // readDataOnly TIDAK dipakai — validasi harus terbaca
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $validasi = array_values($sheet->getDataValidationCollection());
        $cari = fn ($cue) => collect($validasi)->first(
            fn ($v) => str_contains((string) $v->getFormula1(), $cue)
        );
        $this->assertCount(3, $validasi); // SN, STATUS, KATEGORI

        $sn = $cari('SN,NON_SN');
        $this->assertNotNull($sn);
        $this->assertSame(DataValidation::TYPE_LIST, $sn->getType());
        $this->assertStringContainsString('F2', (string) $sn->getSqref() . ':');
        $this->assertTrue($sn->getAllowBlank());

        $st = $cari('Dijual');
        $this->assertNotNull($st);
        $this->assertStringContainsString('Untuk Servis', (string) $st->getFormula1());

        $k = $cari('Daftar');
        $this->assertNotNull($k);
        $this->assertSame(DataValidation::TYPE_LIST, $k->getType());

        // sheet Daftar (hidden) berisi kategori
        $daftar = $spreadsheet->getSheetByName('Daftar');
        $this->assertNotNull($daftar);
        $this->assertSame(Worksheet::SHEETSTATE_VERYHIDDEN, $daftar->getSheetState());
        $this->assertSame('Filter', $daftar->getCell('A1')->getValue()); // urut alfabetis
        $this->assertSame('Oli & Pelumas', $daftar->getCell('A2')->getValue());

        // sheet PETUNJUK ada
        $this->assertNotNull($spreadsheet->getSheetByName('PETUNJUK'));
    }

    public function test_round_trip_upload_ulang_tidak_duplikat(): void
    {
        $this->setupData();
        $svc = app(ExcelImportService::class);
        $path = $svc->templateBarang();

        $hasil = $svc->importBarang($path);
        $this->assertSame(0, $hasil['dibuat']);
        $this->assertSame(2, $hasil['diupdate']);
        $this->assertSame(2, Barang::count());
    }

    public function test_round_trip_dengan_baris_baru_tidak_duplikat(): void
    {
        $this->setupData();
        $svc = app(ExcelImportService::class);
        $path = $svc->templateBarang();

        // tambah 1 baris SKU baru di salinan file
        $reader = new XlsxReader();
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A4', 'NEW-X'); // baris 4 — baris 2 & 3 berisi data existing
        $sheet->setCellValue('B4', 'Baru Sekali');
        $sheet->setCellValue('D4', 'Filter');
        $sheet->setCellValue('E4', 'pcs');
        $sheet->setCellValue('F4', 'NON_SN');
        $sheet->setCellValue('H4', 5); // stok awal
        $sheet->setCellValue('J4', 'Dijual');
        $mod = sys_get_temp_dir() . '/template-mod.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($mod);

        $hasil = $svc->importBarang($mod);
        $this->assertSame(1, $hasil['dibuat']);
        $this->assertSame(2, $hasil['diupdate']);
        $this->assertSame(3, Barang::count());
        $this->assertSame(5, Barang::where('kode_barang', 'NEW-X')->first()->stock);
    }

    public function test_upload_ulang_kode_lowercase_tidak_duplikat(): void
    {
        // SKU existing dengan kode huruf kecil — template memuatnya, upload ulang TIDAK boleh bikin SKU baru
        Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'stok1', 'nama' => 'Stok Kecil',
            'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual',
        ]);
        $svc = app(ExcelImportService::class);
        $path = $svc->templateBarang();

        $hasil = $svc->importBarang($path);
        $this->assertSame(0, $hasil['dibuat']);
        $this->assertSame(1, $hasil['diupdate']);
        $this->assertSame(1, Barang::count());
    }

    public function test_duplikat_dalam_file_beda_case_ditolak(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $headers = ['KODE_BARANG', 'NAMA_BARANG', 'DESKRIPSI', 'KATEGORI', 'SATUAN', 'SN_ATAU_NON_SN', 'HARGA_JUAL', 'STOCK_AWAL', 'LOKASI_RAK', 'STATUS'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('A') + $i) . '1', $h);
        }
        $sheet->setCellValue('A2', 'dupx');
        $sheet->setCellValue('B2', 'Duplikat Satu');
        $sheet->setCellValue('F2', 'NON_SN');
        $sheet->setCellValue('A3', 'DUPX');
        $sheet->setCellValue('B3', 'Duplikat Dua');
        $sheet->setCellValue('F3', 'NON_SN');
        $mod = sys_get_temp_dir() . '/duplikat-case.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($mod);

        try {
            app(ExcelImportService::class)->importBarang($mod);
            $this->fail('Harus dilempar ImportFileException karena ada KODE_BARANG duplikat (beda case).');
        } catch (\App\Exceptions\ImportFileException $e) {
            $ada = collect($e->getErrors())->contains(fn ($x) => str_contains($x, 'duplikat di dalam file'));
            $this->assertTrue($ada, 'Error harus menyebut duplikat dalam file: ' . implode(' | ', $e->getErrors()));
        }
        $this->assertSame(0, Barang::count()); // all-or-nothing: tidak ada data berubah
    }
}
