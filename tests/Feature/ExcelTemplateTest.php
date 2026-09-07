<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\KategoriBarang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Services\PembelianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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

    // ----------------------------------------------------------------------
    // Template pembelian: dropdown SUPPLIER (data aplikasi) + panduan PETUNJUK
    // ----------------------------------------------------------------------

    private function setupSupplier(): void
    {
        Supplier::create(['nama' => 'PT. Bintang Sparepart', 'kontak' => '081234']);
        Supplier::create(['nama' => 'Aneka Motor Jaya', 'kontak' => '082345']);
    }

    /** Buat file xlsx import pembelian dari baris asso [kolom alfabet => nilai]; baris pertama data = row 2. */
    private function buatFilePembelian(array $rows): string
    {
        $headers = ['REF_PEMBELIAN', 'TANGGAL_WAKTU', 'SUPPLIER', 'JENIS_TRANSAKSI', 'BAYAR_DP', 'JATUH_TEMPO', 'KODE_BARANG', 'QTY', 'HARGA_BELI', 'DISKON_ITEM', 'DISKON_TOTAL', 'PPN'];
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('A') + $i) . '1', $h);
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $cell => $val) {
                $sheet->setCellValue($cell . ($r + 2), $val);
            }
        }
        $p = sys_get_temp_dir() . '/import-pbl-' . uniqid() . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($p);

        return $p;
    }

    public function test_upload_pembelian_ref_jadi_nomor_invoice(): void
    {
        Supplier::create(['nama' => 'PT. Bintang Sparepart', 'kontak' => '081']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-REF', 'nama' => 'Ban', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        $mod = $this->buatFilePembelian([
            ['A' => '04002600226207035', 'B' => '2026-09-23 10:00', 'C' => 'PT. Bintang Sparepart', 'D' => 'CASH', 'E' => 100000, 'G' => 'B-REF', 'H' => 2, 'I' => 45000, 'J' => 0, 'K' => 0],
        ]);

        $hasil = app(ExcelImportService::class)->importPembelian($mod);
        $this->assertSame(1, $hasil['dibuat']);
        $this->assertSame(0, $hasil['diupdate']);
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame('04002600226207035', $inv->no_invoice); // REF jadi nomor invoice
        $this->assertSame('import_excel', $inv->sumber);
    }

    public function test_upload_pembelian_ulang_ref_sama_mengupdate(): void
    {
        Supplier::create(['nama' => 'PT B', 'kontak' => '1']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-UPS', 'nama' => 'Ban UPS', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);
        $svc = app(ExcelImportService::class);

        // sesi 1: REF R-1 qty 2 → buat invoice
        $f1 = $this->buatFilePembelian([
            ['A' => 'R-1', 'B' => '2026-09-23 10:00', 'C' => 'PT B', 'D' => 'CASH', 'E' => 100000, 'G' => 'B-UPS', 'H' => 2, 'I' => 45000, 'J' => 0, 'K' => 0],
        ]);
        $h1 = $svc->importPembelian($f1);
        $this->assertSame(1, $h1['dibuat']);
        $this->assertSame(1, InvoicePembelian::count());
        $this->assertSame(2, (int) Barang::where('kode_barang', 'B-UPS')->first()->stock);

        // sesi 2: REF sama, qty 3 → UPDATE (tetap 1 invoice, stok disesuaikan)
        $f2 = $this->buatFilePembelian([
            ['A' => 'R-1', 'B' => '2026-09-23 10:00', 'C' => 'PT B', 'D' => 'CASH', 'E' => 150000, 'G' => 'B-UPS', 'H' => 3, 'I' => 45000, 'J' => 0, 'K' => 0],
        ]);
        $h2 = $svc->importPembelian($f2);
        $this->assertSame(0, $h2['dibuat']);
        $this->assertSame(1, $h2['diupdate']);
        $this->assertSame(1, InvoicePembelian::count());
        $inv = InvoicePembelian::firstOrFail()->load('items');
        $this->assertSame('R-1', $inv->no_invoice);
        $this->assertSame(3, (int) $inv->items->first()->qty);
        $this->assertSame(3, (int) Barang::where('kode_barang', 'B-UPS')->first()->stock);
    }

    public function test_upload_pembelian_ref_konflik_non_import_ditolak(): void
    {
        $s = Supplier::create(['nama' => 'PT C', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-CFL', 'nama' => 'Ban CFL', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);
        // invoice manual dengan no_invoice yang sama dengan REF upload
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id, 'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'no_invoice' => 'MAN-1', 'jenis' => 'cash', 'bayar_atau_dp' => 90000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 45000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        $stokSebelum = (int) $b->fresh()->stock;

        $f = $this->buatFilePembelian([
            ['A' => 'MAN-1', 'B' => '2026-09-23 10:00', 'C' => 'PT C', 'D' => 'CASH', 'E' => 200000, 'G' => 'B-CFL', 'H' => 4, 'I' => 45000, 'J' => 0, 'K' => 0],
        ]);
        try {
            app(ExcelImportService::class)->importPembelian($f);
            $this->fail('Harus ImportFileException utk bentrok nomor invoice non-import.');
        } catch (\App\Exceptions\ImportFileException $e) {
            $this->assertStringContainsString('bukan dari upload', implode(' | ', $e->getErrors()));
        }
        $this->assertSame(1, InvoicePembelian::count()); // semua di-rollback
        $this->assertSame($stokSebelum, (int) $b->fresh()->stock);
    }

    public function test_upload_pembelian_cash_bayar_dp_kosong_bayar_pas(): void
    {
        Supplier::create(['nama' => 'PT B', 'kontak' => '1']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-DP', 'nama' => 'Ban DP', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        // CASH, BAYAR_DP dikosongkan (sel kosong) + PPN kosong semua → auto 11%:
        // DPP 90000 → PPN 9900 → bayar pas = 99900 (PRD 5.3 + PPN otomatis)
        $f = $this->buatFilePembelian([
            ['A' => 'C-1', 'B' => '2026-09-23 10:00', 'C' => 'PT B', 'D' => 'CASH', 'G' => 'B-DP', 'H' => 2, 'I' => 45000, 'J' => 0, 'K' => 0],
        ]);
        $hasil = app(ExcelImportService::class)->importPembelian($f);
        $this->assertSame(1, $hasil['dibuat']);
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame(9900, (int) $inv->ppn); // auto 11% × 90000
        $this->assertSame(99900, (int) $inv->bayar_atau_dp); // bayar pas = DPP + PPN auto
        $this->assertSame('lunas', $inv->status);
        $this->assertSame(2, (int) Barang::where('kode_barang', 'B-DP')->first()->stock);
    }

    public function test_upload_pembelian_cash_bayar_dp_parsial_ditolak(): void
    {
        Supplier::create(['nama' => 'PT C', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-DPP', 'nama' => 'Ban DPP', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        // CASH dengan BAYAR_DP diisi sebagian (< total) → file ditolak all-or-nothing
        $f = $this->buatFilePembelian([
            ['A' => 'C-2', 'B' => '2026-09-23 10:00', 'C' => 'PT C', 'D' => 'CASH', 'E' => 50000, 'G' => 'B-DPP', 'H' => 2, 'I' => 45000, 'J' => 0, 'K' => 0],
        ]);
        try {
            app(ExcelImportService::class)->importPembelian($f);
            $this->fail('Harus ImportFileException utk BAYAR_DP parsial pd CASH.');
        } catch (\App\Exceptions\ImportFileException $e) {
            $this->assertStringContainsString('kurang dari total', implode(' | ', $e->getErrors()));
        }
        $this->assertSame(0, InvoicePembelian::count());
        $this->assertSame(0, (int) $b->fresh()->stock);
    }

    public function test_upload_pembelian_hutang_dp_kosong_belum_lunas(): void
    {
        Supplier::create(['nama' => 'PT D', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-DPH', 'nama' => 'Ban DPH', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        // HUTANG, DP kosong + PPN kosong semua → auto 11%; sisa = DPP + PPN auto
        $f = $this->buatFilePembelian([
            ['A' => 'H-1', 'B' => '2026-09-23 10:00', 'C' => 'PT D', 'D' => 'HUTANG', 'F' => '2026-12-31', 'G' => 'B-DPH', 'H' => 5, 'I' => 20000, 'J' => 0, 'K' => 0],
        ]);
        $hasil = app(ExcelImportService::class)->importPembelian($f);
        $this->assertSame(1, $hasil['dibuat']);
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame('belum_lunas', $inv->status);
        $this->assertSame(0, (int) $inv->bayar_atau_dp);
        $this->assertSame(11000, (int) $inv->ppn); // auto 11% × 100000
        $this->assertSame(111000, (int) $inv->sisa_hutang);
    }

    public function test_upload_pembelian_ppn_ikut_import(): void
    {
        Supplier::create(['nama' => 'PT E', 'kontak' => '1']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-PPNI', 'nama' => 'Ban PPNI', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        // HUTANG dgn PPN 55000 → sisa = total + ppn - dp
        $f = $this->buatFilePembelian([
            ['A' => 'H-PPN', 'B' => '2026-09-23 10:00', 'C' => 'PT E', 'D' => 'HUTANG', 'F' => '2026-12-31', 'G' => 'B-PPNI', 'H' => 2, 'I' => 50000, 'J' => 0, 'K' => 0, 'L' => 55000],
        ]);
        $hasil = app(ExcelImportService::class)->importPembelian($f);
        $this->assertSame(1, $hasil['dibuat']);
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame(55000, (int) $inv->ppn);
        $this->assertSame(100000, (int) $inv->total_setelah_diskon);
        $this->assertSame(155000, (int) $inv->sisa_hutang); // 100000 + 55000 - 0
        $this->assertSame('belum_lunas', $inv->status);
    }

    public function test_upload_pembelian_cash_dp_kosong_dengan_ppn_bayar_pas(): void
    {
        Supplier::create(['nama' => 'PT F', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-PPNC', 'nama' => 'Ban PPNC', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        // CASH, BAYAR_DP kosong, PPN 11000 → bayar pas = total 100000 + ppn 11000 = 111000 → lunas
        $f = $this->buatFilePembelian([
            ['A' => 'C-PPN', 'B' => '2026-09-23 10:00', 'C' => 'PT F', 'D' => 'CASH', 'G' => 'B-PPNC', 'H' => 2, 'I' => 50000, 'J' => 0, 'K' => 0, 'L' => 11000],
        ]);
        $hasil = app(ExcelImportService::class)->importPembelian($f);
        $this->assertSame(1, $hasil['dibuat']);
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame(11000, (int) $inv->ppn);
        $this->assertSame(111000, (int) $inv->bayar_atau_dp); // bayar pas termasuk PPN
        $this->assertSame('lunas', $inv->status);
        $this->assertSame(2, (int) $b->fresh()->stock);
    }

    public function test_upload_pembelian_ppn_total_cukup_di_satu_baris_grup_multiskus(): void
    {
        // e-Faktur: PPN total per invoice. Grup REF dgn 2 SKU — PPN & DISKON_TOTAL hanya diisi di baris PERTAMA.
        Supplier::create(['nama' => 'PT G', 'kontak' => '1']);
        $b1 = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-MSK1', 'nama' => 'Ban 1', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);
        $b2 = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-MSK2', 'nama' => 'Ban 2', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        $f = $this->buatFilePembelian([
            ['A' => 'G-MULTI', 'B' => '2026-09-23 10:00', 'C' => 'PT G', 'D' => 'HUTANG', 'G' => 'B-MSK1', 'H' => 2, 'I' => 50000, 'J' => 0, 'K' => 5000, 'L' => 55000], // baris 1: PPN total + diskon total
            ['A' => 'G-MULTI', 'B' => '2026-09-23 10:00', 'C' => 'PT G', 'D' => 'HUTANG', 'G' => 'B-MSK2', 'H' => 1, 'I' => 30000, 'J' => 0, 'K' => 0],       // baris 2: kosong (PPN & diskon total tidak diulang)
        ]);
        $hasil = app(ExcelImportService::class)->importPembelian($f);
        $this->assertSame(1, $hasil['dibuat']);
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame(55000, (int) $inv->ppn);              // nilai pertama yang terisi dipakai
        $this->assertSame(5000, (int) $inv->diskon_total);      // tidak tertimpa baris kosong
        $this->assertSame(125000, (int) $inv->total_setelah_diskon); // (100000 + 30000) - 5000
        $this->assertSame(180000, (int) $inv->sisa_hutang);     // 125000 + 55000 - 0
    }

    public function test_upload_pembelian_ppn_per_sku_dijumlahkan(): void
    {
        // PPN per baris SKU dijumlahkan menjadi total invoice (bukan nilai pertama saja).
        Supplier::create(['nama' => 'PT H', 'kontak' => '1']);
        $b1 = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-PS1', 'nama' => 'Ban PS1', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);
        $b2 = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-PS2', 'nama' => 'Ban PS2', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        $f = $this->buatFilePembelian([
            ['A' => 'G-PS', 'B' => '2026-09-23 10:00', 'C' => 'PT H', 'D' => 'HUTANG', 'G' => 'B-PS1', 'H' => 2, 'I' => 50000, 'J' => 0, 'K' => 0, 'L' => 5000],
            ['A' => 'G-PS', 'B' => '2026-09-23 10:00', 'C' => 'PT H', 'D' => 'HUTANG', 'G' => 'B-PS2', 'H' => 1, 'I' => 30000, 'J' => 0, 'K' => 0, 'L' => 3000],
        ]);
        $hasil = app(ExcelImportService::class)->importPembelian($f);
        $this->assertSame(1, $hasil['dibuat']);
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame(8000, (int) $inv->ppn); // 5000 + 3000
        $this->assertSame(138000, (int) $inv->sisa_hutang); // 130000 + 8000
        // HPP per SKU memakai PPN barisnya langsung (bukan proporsional)
        $this->assertSame(52500, (int) $b1->fresh()->harga_beli_terakhir); // 50000 + 5000/2
        $this->assertSame(33000, (int) $b2->fresh()->harga_beli_terakhir); // 30000 + 3000/1
        // PPN per item tersimpan untuk audit
        $this->assertSame(5000, (int) $inv->items()->whereHas('barang', fn ($q) => $q->where('kode_barang', 'B-PS1'))->first()->ppn);
        $this->assertSame(3000, (int) $inv->items()->whereHas('barang', fn ($q) => $q->where('kode_barang', 'B-PS2'))->first()->ppn);
    }

    public function test_upload_pembelian_ppn_campuran_kosong_dihitung_nol(): void
    {
        // Satu baris diisi, satu kosong → yang kosong = 0 (tidak ikut auto per baris).
        Supplier::create(['nama' => 'PT I', 'kontak' => '1']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-PC1', 'nama' => 'Ban PC1', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-PC2', 'nama' => 'Ban PC2', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        $f = $this->buatFilePembelian([
            ['A' => 'G-PC', 'B' => '2026-09-23 10:00', 'C' => 'PT I', 'D' => 'HUTANG', 'G' => 'B-PC1', 'H' => 2, 'I' => 50000, 'J' => 0, 'K' => 0, 'L' => 11000],
            ['A' => 'G-PC', 'B' => '2026-09-23 10:00', 'C' => 'PT I', 'D' => 'HUTANG', 'G' => 'B-PC2', 'H' => 1, 'I' => 30000, 'J' => 0, 'K' => 0],
        ]);
        app(ExcelImportService::class)->importPembelian($f);
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame(11000, (int) $inv->ppn); // hanya baris terisi
        $this->assertSame(141000, (int) $inv->sisa_hutang); // 130000 + 11000
    }

    public function test_upload_pembelian_ppn_negatif_ditolak(): void
    {
        Supplier::create(['nama' => 'PT J', 'kontak' => '1']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-PN1', 'nama' => 'Ban PN1', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        $f = $this->buatFilePembelian([
            ['A' => 'G-PN', 'B' => '2026-09-23 10:00', 'C' => 'PT J', 'D' => 'HUTANG', 'G' => 'B-PN1', 'H' => 1, 'I' => 50000, 'J' => 0, 'K' => 0, 'L' => -1000],
        ]);
        try {
            app(ExcelImportService::class)->importPembelian($f);
            $this->fail('PPN negatif harus ditolak.');
        } catch (\App\Exceptions\ImportFileException $e) {
            $this->assertStringContainsString('PPN tidak boleh negatif', implode(' | ', $e->getErrors()));
        }
        $this->assertSame(0, InvoicePembelian::count());
    }

    public function test_upload_pembelian_dua_grup_cash_dan_hutang_tidak_tertukar(): void
    {
        // Regresi reference-leak: dua grup (CASH lalu HUTANG) — metadata grup ke-2 TIDAK boleh ikut
        // tertimpa metadata grup pertama (HUTANG→CASH, supplier salah, PPN salah).
        Supplier::create(['nama' => 'PT. PRIMA JAYA TYRE MART', 'kontak' => '1']);
        Supplier::create(['nama' => 'PT. TRIJAYA PERKASA ABADI', 'kontak' => '2']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-G1', 'nama' => 'Ban G1', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'B-G2', 'nama' => 'Ban G2', 'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual']);

        $f = $this->buatFilePembelian([
            ['A' => 'G1', 'B' => '2026-06-30 12:59', 'C' => 'PT. PRIMA JAYA TYRE MART', 'D' => 'CASH', 'G' => 'B-G1', 'H' => 2, 'I' => 100000, 'J' => 0, 'K' => 0, 'L' => 1000],
            ['A' => 'G2', 'B' => '2026-06-27 16:14', 'C' => 'PT. TRIJAYA PERKASA ABADI', 'D' => 'HUTANG', 'G' => 'B-G2', 'H' => 1, 'I' => 50000, 'J' => 0, 'K' => 0, 'L' => 2000],
        ]);
        $hasil = app(ExcelImportService::class)->importPembelian($f);
        $this->assertSame(2, $hasil['dibuat']);

        $inv1 = InvoicePembelian::where('no_invoice', 'G1')->firstOrFail();
        $this->assertSame('cash', $inv1->jenis);
        $this->assertSame('PT. PRIMA JAYA TYRE MART', $inv1->supplier->nama);
        $this->assertSame(1000, (int) $inv1->ppn);
        $this->assertSame(201000, (int) $inv1->bayar_atau_dp); // bayar pas = 200000 + 1000

        $inv2 = InvoicePembelian::where('no_invoice', 'G2')->firstOrFail();
        $this->assertSame('hutang', $inv2->jenis);
        $this->assertSame('PT. TRIJAYA PERKASA ABADI', $inv2->supplier->nama);
        $this->assertSame(2000, (int) $inv2->ppn);
        $this->assertSame(52000, (int) $inv2->sisa_hutang); // 50000 + 2000 - 0
    }
    public function test_template_pembelian_punya_dropdown_supplier_dan_petunjuk(): void
    {
        $this->setupSupplier();

        $path = app(ExcelImportService::class)->templatePembelian();

        $reader = new XlsxReader(); // tanpa readDataOnly agar data validation ikut terbaca
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // sheet data bernama DATA & header 11 kolom tetap
        $this->assertSame('DATA', $sheet->getTitle());
        $this->assertSame('REF_PEMBELIAN', $sheet->getCell('A1')->getValue());
        $this->assertSame('SUPPLIER', $sheet->getCell('C1')->getValue());
        $this->assertSame('DISKON_TOTAL', $sheet->getCell('K1')->getValue());
        // TANPA baris contoh data (baris 2 kosong)
        $this->assertTrue(empty($sheet->getCell('A2')->getValue()), 'Tidak boleh ada baris contoh data di A2.');
        $this->assertTrue(empty($sheet->getCell('C2')->getValue()), 'Tidak boleh ada data contoh SUPPLIER di C2.');

        $validasi = array_values($sheet->getDataValidationCollection());
        $cari = fn ($cue) => collect($validasi)->first(
            fn ($v) => str_contains((string) $v->getFormula1(), $cue)
        );

        // SUPPLIER (kolom C): dropdown dari sheet hidden 'Daftar'
        $sup = $cari('Daftar');
        $this->assertNotNull($sup, 'Harus ada data validation SUPPLIER yang merujuk sheet Daftar.');
        $this->assertSame(DataValidation::TYPE_LIST, $sup->getType());
        // sqref dinormalisasi PhpSpreadsheet ke sel anchor (C2) setelah baca ulang
        $this->assertStringContainsString('C2:', (string) $sup->getSqref() . ':');
        $this->assertTrue($sup->getAllowBlank());

        // JENIS_TRANSAKSI (kolom D): literal CASH/HUTANG
        $jenis = $cari('CASH,HUTANG');
        $this->assertNotNull($jenis);
        $this->assertSame(DataValidation::TYPE_LIST, $jenis->getType());
        $this->assertStringContainsString('D2', (string) $jenis->getSqref());

        // sheet Daftar (hidden) berisi nama supplier urut alfabetis
        $daftar = $spreadsheet->getSheetByName('Daftar');
        $this->assertNotNull($daftar);
        $this->assertSame(Worksheet::SHEETSTATE_VERYHIDDEN, $daftar->getSheetState());
        $this->assertSame('Aneka Motor Jaya', $daftar->getCell('A1')->getValue());
        $this->assertSame('PT. Bintang Sparepart', $daftar->getCell('A2')->getValue());

        // sheet PETUNJUK ada (panduan)
        $petunjuk = $spreadsheet->getSheetByName('PETUNJUK');
        $this->assertNotNull($petunjuk);
        $this->assertStringContainsString('PANDUAN UPLOAD PEMBELIAN', (string) $petunjuk->getCell('A1')->getValue());
    }

    public function test_template_pembelian_tanpa_supplier_tidak_crash(): void
    {
        // Tidak ada supplier terdaftar — dropdown SUPPLIER dilewati, file tetap valid.
        $path = app(ExcelImportService::class)->templatePembelian();

        $reader = new XlsxReader();
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $validasi = array_values($sheet->getDataValidationCollection());
        $adaDaftar = collect($validasi)->contains(
            fn ($v) => str_contains((string) $v->getFormula1(), 'Daftar')
        );
        $this->assertFalse($adaDaftar, 'Tanpa supplier tidak boleh ada validation yang merujuk sheet Daftar.');
        $this->assertSame('DATA', $sheet->getTitle());
        $this->assertNotNull($spreadsheet->getSheetByName('PETUNJUK'));
    }

    public function test_round_trip_upload_pembelian_isi_dari_template(): void
    {
        Supplier::create(['nama' => 'PT. Bintang Sparepart', 'kontak' => '081234']);
        Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'BLT-001', 'nama' => 'Baut',
            'sn_atau_non_sn' => 'NON_SN', 'status' => 'Dijual',
        ]);

        $svc = app(ExcelImportService::class);
        $path = $svc->templatePembelian();

        // isi 2 baris dengan REF sama (1 invoice) memakai nama supplier dari dropdown
        $reader = new XlsxReader();
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A2', 'PB-001');
        $sheet->setCellValue('B2', '2026-09-05 09:30');
        $sheet->setCellValue('C2', 'PT. Bintang Sparepart');
        $sheet->setCellValue('D2', 'CASH');
        // BAYAR_DP dikosongkan → bayar pas otomatis = DPP + PPN auto (135000 + 14850)
        $sheet->setCellValue('G2', 'BLT-001');
        $sheet->setCellValue('H2', 2);
        $sheet->setCellValue('I2', 45000);
        $sheet->setCellValue('J2', 0);
        $sheet->setCellValue('K2', 0);

        $sheet->setCellValue('A3', 'PB-001');
        $sheet->setCellValue('B3', '2026-09-05 09:30');
        $sheet->setCellValue('C3', 'PT. Bintang Sparepart');
        $sheet->setCellValue('D3', 'CASH');
        $sheet->setCellValue('G3', 'BLT-001');
        $sheet->setCellValue('H3', 1);
        $sheet->setCellValue('I3', 45000);
        $sheet->setCellValue('J3', 0);

        $mod = sys_get_temp_dir() . '/template-pembelian-isi.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($mod);

        $hasil = $svc->importPembelian($mod);
        $this->assertSame(1, $hasil['jumlah_invoice']);

        // no_invoice = REF_PEMBELIAN (bukan generate sistem)
        $inv = InvoicePembelian::firstOrFail();
        $this->assertSame('PB-001', $inv->no_invoice);
        $this->assertSame('PT. Bintang Sparepart', $inv->supplier->nama);
        $this->assertSame(135000, (int) $inv->total_setelah_diskon);
        $this->assertSame(14850, (int) $inv->ppn); // auto 11% × 135000
        $this->assertSame(149850, (int) $inv->bayar_atau_dp); // bayar pas termasuk PPN auto
        $this->assertSame('lunas', $inv->status);

        $this->assertSame(3, (int) Barang::where('kode_barang', 'BLT-001')->first()->stock); // 2+1 masuk

        // Upload template kosong (baris contoh sudah tidak ada) -> "File kosong" tanpa crash
        try {
            $svc->importPembelian($path);
            $this->fail('Harus dilempar ImportFileException "File kosong" utk template tanpa data.');
        } catch (\App\Exceptions\ImportFileException $e) {
            $this->assertStringContainsString('File kosong', $e->getMessage());
        }
    }

    // ----------------------------------------------------------------------
    // Diagnosa lingkungan: pre-flight ekstensi PHP (regresi 500 saat import
    // di shared hosting yg ekstensi XML-reader tidak aktif tapi export jalan)
    // ----------------------------------------------------------------------

    public function test_cek_ekstensi_excel_semua_tersedia(): void
    {
        // Lingkungan lokal/dev harus punya semua ekstensi (zip, xml, dom,
        // simplexml, xmlreader, xmlwriter, fileinfo, mbstring, iconv, gd).
        $kurang = app(ExcelImportService::class)->cekEkstensiExcel();
        $this->assertSame([], $kurang);
    }

    public function test_cek_ekstensi_excel_mendeteksi_yang_hilang(): void
    {
        // $periksa memakai subset/ekstensi sintetis agar hasil deterministik
        // (tidak mungkin menonaktifkan ekstensi sungguhan di dalam test).
        $kurang = app(ExcelImportService::class)->cekEkstensiExcel([
            'zip' => 'keterangan zip',
            'ekstensi_palsu_xyz' => 'keterangan palsu',
        ]);
        $this->assertArrayHasKey('ekstensi_palsu_xyz', $kurang, 'Ekstensi yang tidak ada harus terdeteksi.');
        $this->assertArrayNotHasKey('zip', $kurang, 'Ekstensi yang ada tidak boleh masuk daftar hilang.');
    }

    public function test_upload_pembelian_error_teknis_tidak_jadi_500(): void
    {
        // Regresi: dulu fatal di XlsxReader (mis. ekstensi simplexml/xmlreader
        // belum aktif di server) menghasilkan halaman putih 500. Sekarang harus
        // redirect + flash error ramah + tercatat di log — bukan 500.
        $user = User::create(['name' => 'Admin', 'email' => 'excel@a.id', 'password' => 'pass1234']);
        $this->actingAs($user);

        // File asli xlsx agar validasi mimes:xlsx lolos (konten diperiksa via finfo).
        $p = $this->buatFilePembelian([
            ['A' => 'E-X', 'B' => '2026-09-23 10:00', 'C' => 'PT X', 'D' => 'CASH', 'E' => 100000, 'G' => 'B-X', 'H' => 2, 'I' => 45000, 'J' => 0, 'K' => 0],
        ]);
        $file = UploadedFile::fake()->createWithContent('upload-pembelian.xlsx', (string) file_get_contents($p));

        // Service dilempar Throwable (simulasi ekstensi hilang / file korup di server).
        $mock = \Mockery::mock(ExcelImportService::class);
        $mock->shouldReceive('importPembelian')->once()
            ->andThrow(new \RuntimeException('Simulasi: ekstensi simplexml tidak aktif'));
        $this->app->instance(ExcelImportService::class, $mock);
        Log::shouldReceive('error')->once();

        $response = $this->post(route('pembelian.template.import'), ['file' => $file]);

        $response->assertStatus(302); // redirect, bukan 500
        $response->assertSessionHas('error', function (string $pesan) {
            return str_contains($pesan, 'Terjadi kesalahan membaca file Excel');
        });
        $this->assertSame(0, InvoicePembelian::count()); // tidak ada data berubah
    }
}
