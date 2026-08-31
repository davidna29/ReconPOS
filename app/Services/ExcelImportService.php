<?php

namespace App\Services;

use App\Exceptions\ImportFileException;
use App\Models\Barang;
use App\Models\KategoriBarang;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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

    /** Download template master barang (PRD 4.3c). */
    public function templateBarang(): string
    {
        $headers = ['KODE_BARANG', 'NAMA_BARANG', 'DESKRIPSI', 'KATEGORI', 'SATUAN', 'SN_ATAU_NON_SN', 'HARGA_JUAL', 'STOCK_AWAL', 'LOKASI_RAK', 'STATUS'];
        $contoh = ['BLT-001', 'Oli Mesin 0.8L', '', 'Oli & Pelumas', 'pcs', 'NON_SN', '', '10', 'Rak A1', 'Dijual & Untuk Servis'];

        return $this->tulisTemplate('template-barang', $headers, [$contoh]);
    }

    /** Download template pembelian (PRD 5.3). */
    public function templatePembelian(): string
    {
        $headers = ['REF_PEMBELIAN', 'TANGGAL_WAKTU', 'SUPPLIER', 'JENIS_TRANSAKSI', 'BAYAR_DP', 'JATUH_TEMPO', 'KODE_BARANG', 'QTY', 'HARGA_BELI', 'DISKON_ITEM', 'DISKON_TOTAL'];
        $contoh = ['PB-001', date('Y-m-d') . ' 09:00', 'PT. Bintang Sparepart', 'CASH', '', '', 'BLT-001', '10', '45000', '0', ''];

        return $this->tulisTemplate('template-pembelian', $headers, [$contoh]);
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
        foreach ($rows as $i => $r) {
            $no = $i + 2;
            $kode = strtoupper(trim((string) $r['KODE_BARANG']));
            $nama = trim((string) ($r['NAMA_BARANG'] ?? ''));
            if ($kode === '') {
                $errors[] = 'Baris ' . $no . ': KODE_BARANG wajib diisi.';
                continue;
            }
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

                $barang = Barang::where('kode_barang', $r['kode'])->first();
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
                if ($r['stok_awal'] !== null && $r['stok_awal'] > 0 && $barang->stock === 0) {
                    $this->stock->stokAwal($barang, $r['stok_awal'], 'Stok awal (import template barang)');
                }
            }
        });

        return ['dibuat' => $dibuat, 'diupdate' => $diupdate];
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

            if ($ref === '') {
                $errors[] = 'Baris ' . $no . ': REF_PEMBELIAN wajib diisi (pengelompokan invoice).';
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
            $barang = Barang::where('kode_barang', $kode)->first();
            if (! $barang) {
                $errors[] = 'Baris ' . $no . ': KODE_BARANG "' . $kode . '" tidak ada di master data barang.';
                continue;
            }
            if ($qty <= 0 || $hargaBeli <= 0) {
                $errors[] = 'Baris ' . $no . ': QTY dan HARGA_BELI wajib lebih dari 0.';
                continue;
            }

            $groups[$ref]['metadata'] = [
                'tanggal' => $parsed,
                'supplier' => strtoupper($supplier),
                'jenis' => $jenis,
                'bayar_dp' => (int) round((float) ($r['BAYAR_DP'] ?? 0)),
                'jatuh_tempo' => trim((string) ($r['JATUH_TEMPO'] ?? '')),
                'diskon_total' => (int) round((float) ($r['DISKON_TOTAL'] ?? 0)),
            ];
            $groups[$ref]['items'][] = [
                'no' => $no,
                'kode' => $kode,
                'qty' => $qty,
                'harga_beli' => $hargaBeli,
                'diskon_item' => (int) round((float) ($r['DISKON_ITEM'] ?? 0)),
            ];
        }

        // BAYAR_DP tidak masuk akal (lebih besar dari nilai wajar)
        foreach ($groups as $ref => $g) {
            $m = $g['metadata'];
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
        DB::transaction(function () use ($groups, $supplierCache, &$jumlahInvoice) {
            foreach ($groups as $ref => $g) {
                $m = $g['metadata'];
                $items = [];
                foreach ($g['items'] as $it) {
                    $items[] = [
                        'barang_id' => Barang::where('kode_barang', $it['kode'])->value('id'),
                        'harga_beli' => $it['harga_beli'],
                        'qty' => $it['qty'],
                        'diskon_item' => $it['diskon_item'],
                    ];
                }
                $this->pembelian->simpan([
                    'supplier_id' => $supplierCache[$m['supplier']],
                    'tanggal_transaksi' => $m['tanggal']->format('Y-m-d H:i'),
                    'jenis' => strtolower($m['jenis']),
                    'diskon_total' => $m['diskon_total'],
                    'bayar_atau_dp' => $m['bayar_dp'],
                    'jatuh_tempo' => $m['jatuh_tempo'] !== '' ? $m['jatuh_tempo'] : null,
                    'items' => $items,
                ], 'import_excel');
                $jumlahInvoice++;
            }
        });

        return ['jumlah_invoice' => $jumlahInvoice];
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
            $barang = Barang::where('kode_barang', $r['kode'])->first();
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
