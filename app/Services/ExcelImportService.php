<?php

namespace App\Services;

use App\Exceptions\ImportFileException;
use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\KategoriBarang;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Import/export Excel (PRD 4.3c, 5.3, 8).
 * Aturan "all-or-nothing": ada 1 baris gagal validasi → seluruh file dibatalkan, tidak ada data berubah.
 */
class ExcelImportService
{
    public function __construct(
        private PembelianService $pembelian,
        private StockService $stock,
    ) {}

    /**
     * Download template master barang (PRD 4.3c) — ALREADY prefilled dengan seluruh
     * data barang existing (agar minim duplikat: kode yang ada = update), plus
     * dropdown validasi (SN/STATUS/KATEGORI) di Excel.
     */
    public function templateBarang(): string
    {
        $barangs = Barang::with('kategori')
            ->where('tipe_item', 'barang')
            ->orderBy('kode_barang')
            ->get();

        return $this->tulisTemplateBarang($barangs);
    }

    private function tulisTemplateBarang($barangs): string
    {
        $headers = ['KODE_BARANG', 'NAMA_BARANG', 'DESKRIPSI', 'KATEGORI', 'SATUAN', 'SN_ATAU_NON_SN', 'HARGA_JUAL', 'STOCK_AWAL', 'LOKASI_RAK', 'STATUS'];
        $col = fn (string $h) => chr(ord('A') + array_search($h, $headers, true));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('A') + $i) . '1', $h);
        }
        $sheet->getStyle('A1:' . chr(ord('A') + count($headers) - 1) . '1')->getFont()->setBold(true);

        $row = 2;
        foreach ($barangs as $b) {
            $sheet->setCellValue($col('KODE_BARANG') . $row, $b->kode_barang);
            $sheet->setCellValue($col('NAMA_BARANG') . $row, $b->nama);
            $sheet->setCellValue($col('DESKRIPSI') . $row, $b->deskripsi ?? '');
            $sheet->setCellValue($col('KATEGORI') . $row, $b->kategori?->nama ?? '');
            $sheet->setCellValue($col('SATUAN') . $row, $b->satuan);
            $sheet->setCellValue($col('SN_ATAU_NON_SN') . $row, $b->sn_atau_non_sn);
            $sheet->setCellValue($col('HARGA_JUAL') . $row, $b->harga_jual > 0 ? $b->harga_jual : '');
            $sheet->setCellValue($col('STOCK_AWAL') . $row, ''); // hanya utk barang baru
            $sheet->setCellValue($col('LOKASI_RAK') . $row, $b->lokasi_rak ?? '');
            $sheet->setCellValue($col('STATUS') . $row, $b->status);
            $row++;
        }

        $this->terapkanValidasiDropdown($spreadsheet, $sheet, $headers, $col);

        // Sheet PETUNJUK
        $petunjuk = $spreadsheet->createSheet();
        $petunjuk->setTitle('PETUNJUK');
        $petunjuk->setCellValue('A1', 'Template ini sudah berisi SEMUA data barang yang ada — hindari KODE_BARANG ganda.');
        $petunjuk->setCellValue('A2', 'KODE_BARANG yang sudah ada akan DIUPDATE (bukan duplikat) saat file di-upload.');
        $petunjuk->setCellValue('A3', 'Tambah baris di bawah utk SKU baru; kolom STOCK_AWAL hanya berlaku utk barang baru.');
        $petunjuk->setCellValue('A4', 'HARGA_JUAL hanya dipakai utk SKU mode "Kunci Manual"; mode otomatis dihitung dari margin.');
        $petunjuk->setCellValue('A5', 'KATEGORI: pilih dari dropdown (kategori baru bisa dibuat lewat aplikasi).');
        $petunjuk->getColumnDimension('A')->setWidth(120);

        return $this->simpanSpreadsheet($spreadsheet, 'template-barang');
    }

    /** Dropdown Excel (data validation) utk kolom SN, STATUS, dan KATEGORI (API PhpSpreadsheet v5). */
    private function terapkanValidasiDropdown(Spreadsheet $spreadsheet, Worksheet $sheet, array $headers, \Closure $col): void
    {
        $akhir = 1000; // baris kosong di bawah data tetap punya dropdown (allowBlank)

        $tambah = function (string $cell, string $formula) use ($akhir, $sheet): void {
            $v = new DataValidation();
            $v->setSqref($cell . '2:' . $cell . $akhir);
            $v->setType(DataValidation::TYPE_LIST);
            $v->setFormula1($formula);
            $v->setAllowBlank(true);
            $v->setShowDropDown(false); // false = panah dropdown TAMPIL (konvensi OOXML terbalik)
            $sheet->setDataValidation($cell . '2', $v);
        };

        $tambah($col('SN_ATAU_NON_SN'), '"SN,NON_SN"');
        $tambah($col('STATUS'), '"Dijual,Untuk Servis,Dijual & Untuk Servis"');

        // KATEGORI: daftar kategori existing lewat sheet hidden "Daftar" (hindari limit 255 char)
        $kategori = KategoriBarang::orderBy('nama')->pluck('nama');
        if ($kategori->count() > 0) {
            $daftar = $spreadsheet->getSheetByName('Daftar');
            if (! $daftar) {
                $daftar = $spreadsheet->createSheet();
                $daftar->setTitle('Daftar');
            }
            foreach ($kategori as $i => $nama) {
                $daftar->setCellValue('A' . ($i + 1), $nama);
            }
            $daftar->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

            $tambah($col('KATEGORI'), "'Daftar'!\$A\$1:\$A\$" . $kategori->count());
        }
    }

    private function simpanSpreadsheet(Spreadsheet $spreadsheet, string $nama): string
    {
        $dir = storage_path('app/private/export');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $nama . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return $path;
    }

    /**
     * Download template transaksi pembelian (PRD 5.3).
     * - Kolom SUPPLIER punya DROPDOWN dari master supplier (sheet hidden 'Daftar').
     * - Kolom JENIS_TRANSAKSI dropdown literal CASH/HUTANG.
     * - Sheet PETUNJUK berisi panduan langkah-demi-langkah agar upload berhasil.
     * - TANPA baris contoh data: baris contoh lama (mis. "PT. Bintang Sparepart"/"BLT-001")
     *   ikut terbaca sebagai transaksi saat upload dan membuat seluruh file ditolak.
     */
    public function templatePembelian(): string
    {
        $headers = ['REF_PEMBELIAN', 'TANGGAL_WAKTU', 'SUPPLIER', 'JENIS_TRANSAKSI', 'BAYAR_DP', 'JATUH_TEMPO', 'KODE_BARANG', 'QTY', 'HARGA_BELI', 'DISKON_ITEM', 'DISKON_TOTAL', 'PPN'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('DATA'); // Nama bebas, tetap ACTIVE sheet (bacaSheet pakai getActiveSheet)
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('A') + $i) . '1', $h);
        }
        $sheet->getStyle('A1:' . chr(ord('A') + count($headers) - 1) . '1')->getFont()->setBold(true);

        // Daftar supplier utk dropdown (sheet hidden 'Daftar')
        $suppliers = Supplier::orderBy('nama')->pluck('nama')->values();
        if ($suppliers->count() > 0) {
            $daftar = $spreadsheet->createSheet();
            $daftar->setTitle('Daftar');
            foreach ($suppliers as $i => $nama) {
                $daftar->setCellValue('A' . ($i + 1), $nama);
            }
            $daftar->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        }

        $this->terapkanValidasiPembelian($spreadsheet, $sheet, $suppliers->count());
        $this->tulisPetunjukPembelian($spreadsheet);

        // Sheet data Wajib tetap active: createSheet() membuat sheet baru menjadi active,
        // padahal bacaSheet() membaca getActiveSheet() — kalau active = PETUNJUK, import gagal.
        $spreadsheet->setActiveSheetIndex(0);

        return $this->simpanSpreadsheet($spreadsheet, 'template-pembelian');
    }

    /** Dropdown Excel utk template pembelian: JENIS_TRANSAKSI (literal) & SUPPLIER (sheet hidden 'Daftar'). */
    private function terapkanValidasiPembelian(Spreadsheet $spreadsheet, Worksheet $sheet, int $jumlahSupplier): void
    {
        $akhir = 1000; // baris kosong di bawah header tetap punya dropdown (allowBlank)

        $tambah = function (string $cell, string $formula) use ($akhir, $sheet): void {
            $v = new DataValidation();
            $v->setSqref($cell . '2:' . $cell . $akhir);
            $v->setType(DataValidation::TYPE_LIST);
            $v->setFormula1($formula);
            $v->setAllowBlank(true);
            $v->setShowDropDown(false); // false = panah dropdown TAMPIL (konvensi OOXML terbalik)
            $sheet->setDataValidation($cell . '2', $v);
        };

        // JENIS_TRANSAKSI (kolom D)
        $tambah('D', '"CASH,HUTANG"');

        // SUPPLIER (kolom C) — dari master supplier; pakai range sheet hidden agar nama
        // berisi koma/tanda baca tidak merusak formula list literal.
        if ($jumlahSupplier > 0) {
            $tambah('C', "'Daftar'!\$A\$1:\$A\$" . $jumlahSupplier);
        }
    }

    /** Sheet PETUNJUK utk template pembelian — panduan agar upload berhasil (PRD 5.3). */
    private function tulisPetunjukPembelian(Spreadsheet $spreadsheet): void
    {
        $petunjuk = $spreadsheet->createSheet();
        $petunjuk->setTitle('PETUNJUK');
        $petunjuk->getColumnDimension('A')->setWidth(160);

        $garis = [
            'PANDUAN UPLOAD PEMBELIAN (Agar Berhasil)',
            '=================================================================',
            '1) Prasyarat:',
            '   - Semua KODE_BARANG yang dipakai HARUS sudah ada di menu Data Barang.',
            '   - Semua SUPPLIER HARUS sudah terdaftar di menu Data Supplier.',
            '   - Kolom SUPPLIER memakai DROPDOWN: klik sel lalu pilih nama dari daftar, JANGAN ketik manual.',
            '     (Kalau sel SUPPLIER tidak menampilkan panah dropdown, berarti belum ada supplier terdaftar —',
            '      daftarkan dulu di Data Supplier, lalu unduh template baru.)',
            '2) Kolom WAJIB diisi: REF_PEMBELIAN, TANGGAL_WAKTU, SUPPLIER, JENIS_TRANSAKSI, KODE_BARANG, QTY, HARGA_BELI.',
            '3) Format penulisan:',
            '   - TANGGAL_WAKTU : YYYY-MM-DD + spasi + HH:MM  (contoh: 2026-09-05 09:30).',
            '   - JENIS_TRANSAKSI: pilih CASH atau HUTANG dari dropdown.',
            '   - QTY & HARGA_BELI: angka lebih dari 0, tanpa huruf/Rp/titik ribuan.',
            '4) REF_PEMBELIAN = pengelompokan invoice:',
            '   - Semua baris dengan REF_PEMBELIAN sama dijadikan SATU invoice.',
            '   - Metadata (SUPPLIER, TANGGAL_WAKTU, JENIS_TRANSAKSI) antar baris dalam 1 REF wajib konsisten.',
            '5) BAYAR_DP:',
            '   - JENIS CASH   : isi nominal bayar, TIDAK boleh kurang dari total (kosongkan = bayar pas).',
            '   - JENIS HUTANG : isi uang muka/DP (boleh 0).',
            '6) JATUH_TEMPO: hanya untuk HUTANG, boleh dikosongkan (otomatis = akhir bulan ke-3).',
            '7) DISKON:',
            '   - DISKON_ITEM  : nominal Rp potongan per baris (isi 0 kalau tidak ada).',
            '   - DISKON_TOTAL : nominal Rp potongan sekali per invoice, isi di SALAH SATU baris grup REF',
            '     (nilai pertama yang terisi dipakai — tidak perlu diulang di setiap baris).',
            '8) Contoh pengisian 1 baris (baris 2 = data pertama):',
            '   REF_PEMBELIAN | TANGGAL_WAKTU   | SUPPLIER pilihan dropdown   | JENIS | BAYAR_DP | KODE_BARANG | QTY | HARGA_BELI | DISKON_ITEM | DISKON_TOTAL | PPN',
            '   PB-001        | 2026-09-05 09:30 | PT. Bintang Sparepart       | CASH  | 140000    | BLT-001      | 2   | 45000      | 0           | 0           | 0',
            '9) Cara upload: menu Pembelian -> "Upload Transaksi Excel" -> pilih file -> Upload.',
            '10) ATURAN ALL-OR-NOTHING: kalau ada 1 baris kesalahan, SELURUH file dibatalkan',
            '    (tidak ada data yang tersimpan). Pesan error menyebut nomor baris (baris 2 = data pertama).',
            '    Perbaiki baris tsb lalu upload ulang.',
            '11) Kalau memakai template LAMA yang masih berisi baris contoh (mis. supplier "PT. Bintang',
            '    Sparepart" atau kode "BLT-001"): HAPUS baris contoh itu sebelum mengisi — baris contoh',
            '    ikut diimpor sebagai transaksi dan menyebabkan file ditolak.',
            '12) NOMOR INVOICE = REF_PEMBELIAN: nomor yang Anda isi dipakai langsung sebagai nomor invoice',
            '    (bukan otomatis). Isi sebagai TEKS (format cell "Text" / awali tanda kutip) agar angka',
            '    nol di depan tidak hilang (mis. 04002600226207035). Maksimal 40 karakter.',
            '13) UPLOAD ULANG dengan REF_PEMBELIAN yang sama akan MEMPERBARUI invoice tsb (stok & histori',
            '    disesuaikan otomatis) — bukan membuat invoice ganda. Perubahan qty di bawah qty yang sudah',
            '    terjual akan ditolak demi menjaga stok.',
            '14) PPN (Rp): kolom OPSIONAL utk PPN manual dari e-Faktur supplier — kosongkan = 0; hanya dijumlahkan',
            '    ke Sisa Hutang/Cash (tanpa tarif). PPN di e-Faktur adalah nilai TOTAL per invoice: cukup isi SEKALI',
            '    di salah satu baris grup REF (nilai pertama yang terisi dipakai) — TIDAK perlu dipecah per SKU.',
            '    Untuk CASH, kosongkan BAYAR_DP agar bayar pas termasuk PPN.',
        ];
        foreach ($garis as $i => $teks) {
            $petunjuk->setCellValue('A' . ($i + 1), $teks);
        }
        $petunjuk->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }

    /** Download template update harga (PRD 4.3a). */
    public function templateUpdateHarga(): string
    {
        $headers = ['KODE_BARANG', 'HARGA_JUAL'];

        return $this->tulisTemplate('template-update-harga', $headers, [['BLT-001', '75000']]);
    }

    private function tulisTemplate(string $nama, array $headers, array $contoh): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('A') + $i) . '1', $h);
        }
        $sheet->getStyle('A1:' . chr(ord('A') + count($headers) - 1) . '1')->getFont()->setBold(true);
        foreach ($contoh as $r => $row) {
            foreach ($row as $c => $val) {
                $sheet->setCellValue(chr(ord('A') + $c) . ($r + 2), $val);
            }
        }

        $dir = storage_path('app/private/export');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $nama . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return $path;
    }

    /** Baca file xlsx → baris assoc [HEADER => value], header dinormalisasi huruf besar. */
    private function bacaSheet(string $path): array
    {
        if (! file_exists($path)) {
            throw new ImportFileException([], 'File tidak ditemukan.');
        }
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $headers = [];
        foreach ($rows[1] ?? [] as $col => $val) {
            if ($val !== null && trim((string) $val) !== '') {
                $headers[$col] = strtoupper(trim((string) $val));
            }
        }

        $data = [];
        foreach (array_slice($rows, 1) as $row) {
            $baris = [];
            $kosong = true;
            foreach ($headers as $col => $nama) {
                $baris[$nama] = $row[$col] ?? '';
                if (trim((string) ($row[$col] ?? '')) !== '') {
                    $kosong = false;
                }
            }
            if (! $kosong) {
                $data[] = $baris;
            }
        }

        return $data;
    }

    private function cekHeader(array $dataRows, array $wajib, string $konteks): void
    {
        $data = $dataRows[0] ?? [];
        $ada = array_keys($data);
        $kurang = array_values(array_diff($wajib, $ada));
        if (count($kurang) > 0) {
            throw new ImportFileException(
                [],
                'Template ' . $konteks . ' tidak valid. Kolom wajib yang tidak ditemukan: ' . implode(', ', $kurang)
                . '. Unduh template terbaru lalu isi ulang.'
            );
        }
    }

    /**
     * Import master barang (PRD 4.3c). Kode existing = update non-destruktif.
     */
    public function importBarang(string $path): array
    {
        $rows = $this->bacaSheet($path);
        if (count($rows) === 0) {
            throw new ImportFileException([], 'File kosong — tidak ada baris data.');
        }
        $this->cekHeader($rows, ['KODE_BARANG', 'NAMA_BARANG'], 'barang');

        $errors = [];
        $barisOk = [];
        $seenKode = []; // deteksi duplikat dalam file (huruf besar/kecil dianggap sama)
        foreach ($rows as $i => $r) {
            $no = $i + 2;
            $kode = trim((string) $r['KODE_BARANG']);      // asli (dipertahankan saat create)
            $kodeUnik = strtoupper($kode);                 // kunci matching & dedup
            $nama = trim((string) ($r['NAMA_BARANG'] ?? ''));
            if ($kode === '') {
                $errors[] = 'Baris ' . $no . ': KODE_BARANG wajib diisi.';
                continue;
            }
            if (isset($seenKode[$kodeUnik])) {
                $errors[] = 'Baris ' . $no . ': KODE_BARANG "' . $kode . '" duplikat di dalam file (huruf besar/kecil dianggap sama dengan "' . $seenKode[$kodeUnik] . '").';
                continue;
            }
            $seenKode[$kodeUnik] = $kode;
            if ($nama === '') {
                $errors[] = 'Baris ' . $no . ': NAMA_BARANG wajib diisi.';
                continue;
            }
            $sn = strtoupper(trim((string) ($r['SN_ATAU_NON_SN'] ?? 'NON_SN')));
            if (! in_array($sn, ['SN', 'NON_SN'], true)) {
                $errors[] = 'Baris ' . $no . ': SN_ATAU_NON_SN harus SN atau NON_SN.';
                continue;
            }
            $kategori = trim((string) ($r['KATEGORI'] ?? ''));
            $status = trim((string) ($r['STATUS'] ?? 'Dijual & Untuk Servis'));
            if (! in_array($status, ['Dijual', 'Untuk Servis', 'Dijual & Untuk Servis'], true)) {
                $errors[] = 'Baris ' . $no . ': STATUS tidak dikenal (' . $status . ').';
                continue;
            }
            $hargaJual = $r['HARGA_JUAL'] !== '' && $r['HARGA_JUAL'] !== null ? (int) round((float) $r['HARGA_JUAL']) : null;
            $stokAwal = $r['STOCK_AWAL'] !== '' && $r['STOCK_AWAL'] !== null ? (int) round((float) $r['STOCK_AWAL']) : null;
            if ($stokAwal !== null && $stokAwal < 0) {
                $errors[] = 'Baris ' . $no . ': STOCK_AWAL tidak boleh negatif.';
                continue;
            }
            $barisOk[] = [
                'no' => $no,
                'kode' => $kode,
                'kode_unik' => $kodeUnik,
                'nama' => $nama,
                'deskripsi' => trim((string) ($r['DESKRIPSI'] ?? '')),
                'kategori' => $kategori,
                'satuan' => trim((string) ($r['SATUAN'] ?? 'pcs')) ?: 'pcs',
                'sn' => $sn,
                'harga_jual' => $hargaJual,
                'stok_awal' => $stokAwal,
                'lokasi' => trim((string) ($r['LOKASI_RAK'] ?? '')),
                'status' => $status,
            ];
        }

        if (count($errors) > 0) {
            throw new ImportFileException(array_slice($errors, 0, 50));
        }

        $dibuat = 0;
        $diupdate = 0;
        DB::transaction(function () use ($barisOk, &$dibuat, &$diupdate) {
            foreach ($barisOk as $r) {
                $kategoriId = null;
                if ($r['kategori'] !== '') {
                    $kategoriId = KategoriBarang::firstOrCreate(['nama' => $r['kategori']])->id;
                }

                // lookup case-insensitive: kode "stok1" harus sama dengan "STOK1" — hindari SKU ganda
                $barang = Barang::whereRaw('UPPER(kode_barang) = ?', [$r['kode_unik']])->first();
                if ($barang) {
                    // update non-destruktif: Harga Beli tidak berubah lewat jalur ini (PRD 4.3c)
                    $barang->update([
                        'nama' => $r['nama'],
                        'deskripsi' => $r['deskripsi'] ?: $barang->deskripsi,
                        'kategori_id' => $kategoriId ?: $barang->kategori_id,
                        'satuan' => $r['satuan'],
                        'sn_atau_non_sn' => $r['sn'],
                        'lokasi_rak' => $r['lokasi'] ?: $barang->lokasi_rak,
                        'status' => $r['status'],
                    ]);
                    if ($r['harga_jual'] !== null && $barang->mode_harga_jual === 'manual_terkunci') {
                        $barang->harga_jual = $r['harga_jual'];
                        $barang->save();
                    }
                    $diupdate++;
                } else {
                    $mode = 'otomatis';
                    $hargaJual = 0;
                    if ($r['harga_jual'] !== null) {
                        // HARGA_JUAL hanya berlaku untuk mode manual_terkunci; tanpa harga beli dipakai margin default
                        $mode = 'manual_terkunci';
                        $hargaJual = $r['harga_jual'];
                    }
                    $barang = Barang::create([
                        'tipe_item' => 'barang',
                        'kode_barang' => $r['kode'],
                        'nama' => $r['nama'],
                        'deskripsi' => $r['deskripsi'] ?: null,
                        'kategori_id' => $kategoriId,
                        'satuan' => $r['satuan'],
                        'sn_atau_non_sn' => $r['sn'],
                        'mode_harga_jual' => $mode,
                        'harga_jual' => $hargaJual,
                        'lokasi_rak' => $r['lokasi'] ?: null,
                        'status' => $r['status'],
                    ]);
                    $dibuat++;
                }

                // STOCK_AWAL hanya utk barang baru (PRD 4.3c)
                // catatan: atribut stock instance baru bernilai null (default DB), bandingkan longgar
                if ($r['stok_awal'] !== null && $r['stok_awal'] > 0 && (int) $barang->stock === 0) {
                    $this->stock->stokAwal($barang, $r['stok_awal'], 'Stok awal (import template barang)');
                }
            }
        });

        return ['dibuat' => $dibuat, 'diupdate' => $diupdate];
    }

    /**
     * Isi metadata grup REF dengan aturan "nilai pertama yang terisi" (PRD 5.3):
     * baris pertama grup menginisialisasi; baris berikutnya hanya mengisi field yang masih kosong.
     * Dipakai utk PPN / DISKON_TOTAL / BAYAR_DP / JATUH_TEMPO — cukup diisi sekali utk 1 invoice.
     */
    private function isiMetadataGroup(array &$groups, string $ref, $parsed, string $supplier, string $jenis, array $r, int $ppn): void
    {
        $bayar = (int) round((float) ($r['BAYAR_DP'] ?? 0));
        $jatuh = trim((string) ($r['JATUH_TEMPO'] ?? ''));
        $diskon = (int) round((float) ($r['DISKON_TOTAL'] ?? 0));

        if (! isset($groups[$ref]['metadata'])) {
            $groups[$ref]['metadata'] = [
                'tanggal' => $parsed,
                'supplier' => strtoupper($supplier),
                'jenis' => $jenis,
                'bayar_dp' => $bayar,
                'jatuh_tempo' => $jatuh,
                'diskon_total' => $diskon,
                'ppn' => $ppn,
            ];

            return;
        }

        $m = &$groups[$ref]['metadata'];
        if ((int) $m['bayar_dp'] === 0 && $bayar > 0) {
            $m['bayar_dp'] = $bayar;
        }
        if ($m['jatuh_tempo'] === '' && $jatuh !== '') {
            $m['jatuh_tempo'] = $jatuh;
        }
        if ((int) $m['diskon_total'] === 0 && $diskon > 0) {
            $m['diskon_total'] = $diskon;
        }
        if ((int) $m['ppn'] === 0 && $ppn > 0) {
            $m['ppn'] = $ppn;
        }
    }

    /**
     * Import transaksi pembelian (PRD 5.3). All-or-nothing per seluruh file.
     */
    public function importPembelian(string $path): array
    {
        $rows = $this->bacaSheet($path);
        if (count($rows) === 0) {
            throw new ImportFileException([], 'File kosong — tidak ada baris data.');
        }
        $this->cekHeader($rows, [
            'REF_PEMBELIAN', 'TANGGAL_WAKTU', 'SUPPLIER', 'JENIS_TRANSAKSI',
            'KODE_BARANG', 'QTY', 'HARGA_BELI',
        ], 'pembelian');

        // kumpulkan error
        $errors = [];
        $groups = [];
        foreach ($rows as $i => $r) {
            $no = $i + 2;
            $ref = trim((string) $r['REF_PEMBELIAN']);
            $tgl = trim((string) $r['TANGGAL_WAKTU']);
            $supplier = trim((string) ($r['SUPPLIER'] ?? ''));
            $jenis = strtoupper(trim((string) ($r['JENIS_TRANSAKSI'] ?? 'CASH')));
            $kode = strtoupper(trim((string) $r['KODE_BARANG']));
            $qty = (int) round((float) ($r['QTY'] ?? 0));
            $hargaBeli = (int) round((float) ($r['HARGA_BELI'] ?? 0));
            $ppn = (int) round((float) ($r['PPN'] ?? 0));

            if ($ref === '') {
                $errors[] = 'Baris ' . $no . ': REF_PEMBELIAN wajib diisi (pengelompokan invoice).';
                continue;
            }
            if (mb_strlen($ref) > 40) {
                $errors[] = 'Baris ' . $no . ': REF_PEMBELIAN maksimal 40 karakter (dipakai sebagai nomor invoice).';
                continue;
            }
            $parsed = $this->cobaParseTanggal($tgl);
            if (! $parsed) {
                $errors[] = 'Baris ' . $no . ': TANGGAL_WAKTU tidak valid (' . $tgl . '). Format: YYYY-MM-DD HH:MM.';
                continue;
            }
            if ($supplier === '') {
                $errors[] = 'Baris ' . $no . ': SUPPLIER wajib diisi.';
                continue;
            }
            if (! in_array($jenis, ['CASH', 'HUTANG'], true)) {
                $errors[] = 'Baris ' . $no . ': JENIS_TRANSAKSI harus CASH atau HUTANG.';
                continue;
            }
            $barang = Barang::whereRaw('UPPER(kode_barang) = ?', [$kode])->first();
            if (! $barang) {
                $errors[] = 'Baris ' . $no . ': KODE_BARANG "' . $kode . '" tidak ada di master data barang.';
                continue;
            }
            if ($qty <= 0 || $hargaBeli <= 0) {
                $errors[] = 'Baris ' . $no . ': QTY dan HARGA_BELI wajib lebih dari 0.';
                continue;
            }
            if ($ppn < 0) {
                $errors[] = 'Baris ' . $no . ': PPN tidak boleh negatif.';
                continue;
            }

            // Metadata invoice = NILAI PERTAMA YANG TERISI per grup REF (bukan baris terakhir).
            // Ini supaya PPN/DISKON_TOTAL/BAYAR_DP/JATUH_TEMPO cukup diisi SEKALI di salah satu baris
            // (mis. total PPN e-Faktur utk seluruh invoice) — TIDAK perlu dipecah per SKU.
            $this->isiMetadataGroup($groups, $ref, $parsed, $supplier, $jenis, $r, $ppn);
            $groups[$ref]['items'][] = [
                'no' => $no,
                'kode' => $kode,
                'qty' => $qty,
                'harga_beli' => $hargaBeli,
                'diskon_item' => (int) round((float) ($r['DISKON_ITEM'] ?? 0)),
            ];
        }

        // ---- Aturan BAYAR_DP per grup (PRD 5.3): CASH boleh kosong = "bayar pas" ----
        // total = Σ(harga_beli × qty − diskon_item) − diskon_total (rumus sama dgn simpan)
        $autoBayarPas = [];
        foreach ($groups as $ref => $g) {
            $total = 0;
            foreach ($g['items'] as $it) {
                $total += ($it['harga_beli'] * $it['qty']) - $it['diskon_item'];
            }
            $total -= $g['metadata']['diskon_total'];
            if ($total < 0) {
                $errors[] = 'REF_PEMBELIAN "' . $ref . '": total negatif setelah diskon.';
            }

            $m = &$groups[$ref]['metadata'];
            if (strtoupper($m['jenis']) === 'CASH') {
                // bayar pas / cek parsial harus mencakup PPN (Sisa = total + PPN - DP)
                $totalBayar = $total + (int) ($m['ppn'] ?? 0);
                if ($m['bayar_dp'] <= 0) {
                    // sel BAYAR_DP kosong → bayar pas = total + PPN (PRD 5.3)
                    $m['bayar_dp'] = max(0, $totalBayar);
                    $autoBayarPas[$ref] = true;
                } elseif ($m['bayar_dp'] < $totalBayar) {
                    $errors[] = 'REF_PEMBELIAN "' . $ref . '": Pembayaran Cash (' . uang($m['bayar_dp'])
                        . ') kurang dari total + PPN (' . uang($totalBayar) . ') — isi pas atau kosongkan BAYAR_DP utk bayar pas.';
                }
            }
        }

        // BAYAR_DP tidak masuk akal (lebih besar dari nilai wajar) — dilewati utk bayar pas otomatis
        foreach ($groups as $ref => $g) {
            $m = $g['metadata'];
            if (isset($autoBayarPas[$ref])) {
                continue; // nilai otomatis = total (bisa memang besar & wajar)
            }
            if ($m['bayar_dp'] >= 1_000_000_000) {
                foreach ($g['items'] as $it) {
                    $errors[] = 'Baris ' . $it['no'] . ': BAYAR_DP tidak masuk akal (' . $m['bayar_dp'] . ').';
                }
            }
        }


        if (count($errors) > 0) {
            throw new ImportFileException(array_slice($errors, 0, 50));
        }

        // supplier harus sudah terdaftar
        $supplierCache = [];
        foreach ($groups as $ref => $g) {
            $nama = $groups[$ref]['metadata']['supplier'];
            if (! isset($supplierCache[$nama])) {
                $s = Supplier::whereRaw('UPPER(nama) = ?', [$nama])->first();
                if (! $s) {
                    throw new ImportFileException(['Supplier "' . $nama . '" (REF_PEMBELIAN ' . $ref . ') belum terdaftar di master supplier.']);
                }
                $supplierCache[$nama] = $s->id;
            }
        }

        $jumlahInvoice = 0;
        $dibuat = 0;
        $diupdate = 0;
        $applyErrors = [];

        // All-or-nothing tetap: semua grup diaplikasikan; bila ada 1 error, seluruh file
        // di-rollback (transaksi ini dilempar sebelum commit).
        DB::transaction(function () use ($groups, $supplierCache, &$jumlahInvoice, &$dibuat, &$diupdate, &$applyErrors) {
            foreach ($groups as $ref => $g) {
                $m = $g['metadata'];
                $items = [];
                foreach ($g['items'] as $it) {
                    $items[] = [
                        'barang_id' => Barang::whereRaw('UPPER(kode_barang) = ?', [$it['kode']])->value('id'),
                        'harga_beli' => $it['harga_beli'],
                        'qty' => $it['qty'],
                        'diskon_item' => $it['diskon_item'],
                    ];
                }
                // REF_PEMBELIAN dipakai sebagai NOMOR INVOICE (PRD 5.3): user bisa melacak
                // invoice via nomor yang mereka pakai, dan re-upload REF yang sama = UPDATE.
                $data = [
                    'no_invoice' => $ref,
                    'supplier_id' => $supplierCache[$m['supplier']],
                    'tanggal_transaksi' => $m['tanggal']->format('Y-m-d H:i'),
                    'jenis' => strtolower($m['jenis']),
                    'diskon_total' => $m['diskon_total'],
                    'ppn' => (int) ($m['ppn'] ?? 0),
                    'bayar_atau_dp' => $m['bayar_dp'],
                    'jatuh_tempo' => $m['jatuh_tempo'] !== '' ? $m['jatuh_tempo'] : null,
                    'items' => $items,
                ];

                try {
                    $existing = InvoicePembelian::where('no_invoice', $ref)->first();
                    if ($existing) {
                        // Upload ulang dengan REF sama → perbarui invoice tsb, bukan duplikat.
                        if ($existing->sumber !== 'import_excel') {
                            throw new InvalidArgumentException(
                                'Nomor "' . $ref . '" sudah dipakai transaksi non-import (bukan dari upload). Ganti REF_PEMBELIAN atau hapus invoice tsb dulu.'
                            );
                        }
                        $this->pembelian->edit($existing, $data); // batal efek lama → terapkan baru (no_invoice tetap)
                        $diupdate++;
                    } else {
                        $this->pembelian->simpan($data, 'import_excel');
                        $dibuat++;
                    }
                    $jumlahInvoice++;
                } catch (InvalidArgumentException $e) {
                    $applyErrors[] = 'REF_PEMBELIAN "' . $ref . '": ' . $e->getMessage();
                }
            }

            if (count($applyErrors) > 0) {
                throw new ImportFileException(array_slice($applyErrors, 0, 50));
            }
        });

        return ['jumlah_invoice' => $jumlahInvoice, 'dibuat' => $dibuat, 'diupdate' => $diupdate];
    }

    /**
     * Update harga jual massal (PRD 4.3a): hanya SKU mode manual_terkunci.
     * SKU otomatis diabaikan (dihitung & dilaporkan), bukan membatalkan file.
     */
    public function importUpdateHarga(string $path): array
    {
        $rows = $this->bacaSheet($path);
        if (count($rows) === 0) {
            throw new ImportFileException([], 'File kosong — tidak ada baris data.');
        }
        $this->cekHeader($rows, ['KODE_BARANG', 'HARGA_JUAL'], 'update harga');

        $errors = [];
        $dataOk = [];
        foreach ($rows as $i => $r) {
            $no = $i + 2;
            $kode = strtoupper(trim((string) $r['KODE_BARANG']));
            $harga = (int) round((float) ($r['HARGA_JUAL'] ?? 0));
            if ($kode === '') {
                $errors[] = 'Baris ' . $no . ': KODE_BARANG wajib diisi.';
                continue;
            }
            if ($harga <= 0) {
                $errors[] = 'Baris ' . $no . ': HARGA_JUAL wajib lebih dari 0.';
                continue;
            }
            $dataOk[] = ['no' => $no, 'kode' => $kode, 'harga' => $harga];
        }

        if (count($errors) > 0) {
            throw new ImportFileException(array_slice($errors, 0, 50));
        }

        $diupdate = 0;
        $diabaikan = 0;
        $tidakDitemukan = [];
        foreach ($dataOk as $r) {
            $barang = Barang::whereRaw('UPPER(kode_barang) = ?', [$r['kode']])->first();
            if (! $barang) {
                $tidakDitemukan[] = $r['kode'];
                continue;
            }
            if ($barang->mode_harga_jual !== 'manual_terkunci') {
                $diabaikan++; // mode otomatis: harga dihitung dari margin (PRD 4.3a)
                continue;
            }
            $barang->harga_jual = $r['harga'];
            $barang->save();
            $diupdate++;
        }

        return [
            'diupdate' => $diupdate,
            'diabaikan' => $diabaikan,
            'tidak_ditemukan' => count($tidakDitemukan),
        ];
    }

    private function cobaParseTanggal(string $tgl): ?Carbon
    {
        $tgl = trim($tgl);
        if ($tgl === '') {
            return null;
        }
        try {
            return Carbon::parse($tgl);
        } catch (\Throwable) {
            return null;
        }
    }
}
