<?php

namespace App\Services;

use App\Models\ExportBatch;
use App\Models\TemplateExportPosConfig;
use App\Models\TokoSetting;
use App\Models\TransaksiPenjualan;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export transaksi (PRD 8).
 * - Format A: siap import ke POS Bengkel (template konfigurabel, chunking 5.000 baris, pre-validasi).
 * - Format B: laporan/arsip internal dengan ringkasan.
 */
class ExportPosService
{
    public const MAKS_BARIS_PER_FILE = 5000;

    /**
     * Mapping kolom default v1 (PRD 8b). Disimpan juga di DB (template_export_pos_config)
     * dan bisa diubah admin — di sini hanya fallback bila config kosong.
     */
    public static function mappingDefault(): array
    {
        return [
            ['nama_kolom' => 'REF_TRANSAKSI', 'urutan' => 1, 'wajib_opsional' => 'wajib', 'format' => 'text', 'nilai_default' => '', 'sumber_data' => 'ref_transaksi'],
            ['nama_kolom' => 'TANGGAL_WAKTU', 'urutan' => 2, 'wajib_opsional' => 'wajib', 'format' => 'Y-m-d H:i', 'nilai_default' => '', 'sumber_data' => 'tanggal_waktu'],
            ['nama_kolom' => 'CUSTOMER_ID', 'urutan' => 3, 'wajib_opsional' => 'wajib', 'format' => 'int', 'nilai_default' => '0', 'sumber_data' => 'customer_id'],
            ['nama_kolom' => 'JENIS_TRANSAKSI', 'urutan' => 4, 'wajib_opsional' => 'wajib', 'format' => 'text', 'nilai_default' => 'CASH', 'sumber_data' => 'jenis_transaksi'],
            ['nama_kolom' => 'TIPE_PEMBAYARAN', 'urutan' => 5, 'wajib_opsional' => 'wajib', 'format' => 'text', 'nilai_default' => 'CASH', 'sumber_data' => 'tipe_pembayaran'],
            ['nama_kolom' => 'BAYAR_DP', 'urutan' => 6, 'wajib_opsional' => 'opsional', 'format' => 'kosong', 'nilai_default' => '', 'sumber_data' => ''],
            ['nama_kolom' => 'JATUH_TEMPO', 'urutan' => 7, 'wajib_opsional' => 'opsional', 'format' => 'Y-m-d', 'nilai_default' => '', 'sumber_data' => 'jatuh_tempo'],
            ['nama_kolom' => 'KODE_BARANG', 'urutan' => 8, 'wajib_opsional' => 'wajib', 'format' => 'text', 'nilai_default' => '', 'sumber_data' => 'kode_barang'],
            ['nama_kolom' => 'QTY', 'urutan' => 9, 'wajib_opsional' => 'wajib', 'format' => 'int', 'nilai_default' => '', 'sumber_data' => 'qty'],
            ['nama_kolom' => 'HARGA_JUAL', 'urutan' => 10, 'wajib_opsional' => 'opsional', 'format' => 'int', 'nilai_default' => '', 'sumber_data' => 'harga_jual'],
            ['nama_kolom' => 'NO_SN', 'urutan' => 11, 'wajib_opsional' => 'opsional', 'format' => 'text', 'nilai_default' => '', 'sumber_data' => 'no_sn'],
        ];
    }

    public function mappingAktif(): array
    {
        $config = TemplateExportPosConfig::aktif(TokoSetting::utama()->template_version ?? 'v1');
        if ($config->count() > 0) {
            return $config->map(fn ($c) => [
                'nama_kolom' => $c->nama_kolom,
                'urutan' => $c->urutan,
                'wajib_opsional' => $c->wajib_opsional,
                'format' => $c->format,
                'nilai_default' => $c->nilai_default,
                'sumber_data' => $c->sumber_data,
            ])->all();
        }

        return self::mappingDefault();
    }

    /**
     * Export Format A (siap import POS Bengkel). Pre-validasi sebelum file diunduh (PRD 8b).
     *
     * @return array{ok: bool, files: array, errors: array, export_batch?: ExportBatch}
     */
    public function buildFormatA(?string $mulai, ?string $selesai, ?string $bulan = null): array
    {
        [$from, $to] = $this->resolvePeriode($mulai, $selesai, $bulan);

        $transaksi = TransaksiPenjualan::with(['items.barang'])
            ->where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('tanggal_waktu')
            ->get();

        $mapping = $this->mappingAktif();

        // bangun baris
        $rows = [];
        $errors = [];
        foreach ($transaksi as $trx) {
            foreach ($trx->items as $item) {
                $barang = $item->barang;
                $row = [];
                foreach ($mapping as $m) {
                    $row[$m['nama_kolom']] = $this->nilaiKolom($m, $trx, $item, $barang);
                }

                // pre-validasi
                if (! $barang) {
                    $errors[] = 'KODE_BARANG ' . $item->barang_id . ' tidak ada di referensi barang (transaksi ' . $trx->ref_transaksi . ').';
                    continue;
                }
                $isSn = $barang->isSn();
                if ($isSn && ($item->qty !== 1 || empty($item->no_sn))) {
                    $errors[] = 'Barang ber-SN ' . $barang->kode_barang . ' wajib QTY 1 dengan NO_SN terisi (transaksi ' . $trx->ref_transaksi . ').';
                }
                if (! $isSn && ! empty($item->no_sn)) {
                    $errors[] = 'Barang non-SN ' . $barang->kode_barang . ' wajib NO_SN kosong (transaksi ' . $trx->ref_transaksi . ').';
                }
                $rows[] = $row;
            }
        }

        $errors = array_values(array_unique($errors));
        if (count($errors) > 0) {
            return ['ok' => false, 'files' => [], 'errors' => $errors];
        }

        // chunking max 5.000 baris/file (PRD 8b)
        $chunks = array_chunk($rows, self::MAKS_BARIS_PER_FILE);
        $paths = [];
        $stamp = now()->format('Ymd-His');
        foreach ($chunks as $i => $chunk) {
            $nama = 'export-pos-' . $stamp . ($i > 0 ? '-part' . ($i + 1) : '') . '.xlsx';
            $this->tulisXlsx($nama, $mapping, $chunk, 'IMPORT_PENJUALAN');
            $paths[] = $nama;
        }

        $batch = ExportBatch::create([
            'format' => 'A',
            'filter_mulai' => $from->toDateString(),
            'filter_selesai' => $to->toDateString(),
            'filter_bulan' => $bulan,
            'jumlah_file' => count($paths),
            'file_names' => $paths,
            'status_upload_manual' => false,
        ]);

        return ['ok' => true, 'files' => $paths, 'errors' => [], 'export_batch' => $batch];
    }

    /**
     * Export Format B — laporan/arsip internal + ringkasan (PRD 8c).
     */
    /**
     * Kumpulkan data Format B (dipakai Excel & PDF agar query/agregat identik).
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array, 2: array, 3: Carbon, 4: Carbon}
     */
    private function kumpulkanFormatB(?string $mulai, ?string $selesai, ?string $bulan, ?string $sumber): array
    {
        [$from, $to] = $this->resolvePeriode($mulai, $selesai, $bulan);

        $q = TransaksiPenjualan::with(['items.barang'])
            ->where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($sumber && in_array($sumber, ['manual', 'rekonstruksi'], true)) {
            $q->where('sumber', $sumber);
        }
        $transaksi = $q->orderBy('tanggal_waktu')->get();

        $mapping = [
            ['nama_kolom' => 'Tanggal Transaksi', 'urutan' => 1, 'wajib_opsional' => 'wajib', 'format' => 'Y-m-d H:i', 'nilai_default' => '', 'sumber_data' => 'tanggal_waktu'],
            ['nama_kolom' => 'No. Nota', 'urutan' => 2, 'wajib_opsional' => 'wajib', 'format' => 'text', 'nilai_default' => '', 'sumber_data' => 'ref_transaksi'],
            ['nama_kolom' => 'Nama Item', 'urutan' => 3, 'wajib_opsional' => 'wajib', 'format' => 'text', 'nilai_default' => '', 'sumber_data' => 'nama'],
            ['nama_kolom' => 'Qty', 'urutan' => 4, 'wajib_opsional' => 'wajib', 'format' => 'int', 'nilai_default' => '', 'sumber_data' => 'qty'],
            ['nama_kolom' => 'Harga Satuan', 'urutan' => 5, 'wajib_opsional' => 'wajib', 'format' => 'int', 'nilai_default' => '', 'sumber_data' => 'harga_jual'],
            ['nama_kolom' => 'Subtotal', 'urutan' => 6, 'wajib_opsional' => 'wajib', 'format' => 'int', 'nilai_default' => '', 'sumber_data' => 'subtotal'],
            ['nama_kolom' => 'Total per Nota', 'urutan' => 7, 'wajib_opsional' => 'opsional', 'format' => 'int', 'nilai_default' => '', 'sumber_data' => 'total_nota'],
            ['nama_kolom' => 'Sumber', 'urutan' => 8, 'wajib_opsional' => 'opsional', 'format' => 'text', 'nilai_default' => '', 'sumber_data' => 'sumber'],
        ];

        $rows = [];
        foreach ($transaksi as $trx) {
            foreach ($trx->items as $item) {
                $rows[] = [
                    'Tanggal Transaksi' => $trx->tanggal_waktu->format('Y-m-d H:i'),
                    'No. Nota' => $trx->ref_transaksi,
                    'Nama Item' => $item->barang?->nama ?? '-',
                    'Qty' => $item->qty,
                    'Harga Satuan' => $item->harga_jual,
                    'Subtotal' => $item->subtotal,
                    'Total per Nota' => $trx->total,
                    'Sumber' => $trx->sumber === 'rekonstruksi' ? 'rekonstruksi' : 'manual',
                ];
            }
        }

        $totalOmset = (int) $transaksi->sum('total');
        $jumlahNota = $transaksi->count();
        $jumlahItem = (int) $transaksi->sum(fn ($t) => $t->items->sum('qty'));

        return [$transaksi, $mapping, $rows, [
            'Total Omset Periode' => $totalOmset,
            'Jumlah Nota' => $jumlahNota,
            'Jumlah Item Terjual' => $jumlahItem,
            'Periode' => $from->format('d/m/Y') . ' - ' . $to->format('d/m/Y'),
        ], $from, $to];
    }

    public function buildFormatB(?string $mulai, ?string $selesai, ?string $bulan = null, ?string $sumber = null): array
    {
        [$transaksi, $mapping, $rows, $ringkasan, $from, $to] = $this->kumpulkanFormatB($mulai, $selesai, $bulan, $sumber);

        $nama = 'laporan-penjualan-' . now()->format('Ymd-His') . '.xlsx';
        $this->tulisLaporan($nama, $mapping, $rows, $ringkasan);

        $batch = ExportBatch::create([
            'format' => 'B',
            'filter_mulai' => $from->toDateString(),
            'filter_selesai' => $to->toDateString(),
            'filter_bulan' => $bulan,
            'jumlah_file' => 1,
            'file_names' => [$nama],
            'status_upload_manual' => false,
        ]);

        return ['ok' => true, 'files' => [$nama], 'errors' => [], 'export_batch' => $batch];
    }

    /**
     * Maks nota per file PDF — dompdf membangun seluruh layout tabel di memori
     * (±230KB/baris + ±52MB biaya tetap; 100 nota ≈ 90MB, aman di limit 128MB).
     */
    public const MAKS_NOTA_PER_PDF = 100;

    /**
     * Format B versi PDF — data & agregat identik dgn Excel (satu query).
     * Dipecah per 150 nota (pola chunking Format A) agar render dompdf hemat memori.
     * Tercatat di riwayat sebagai format 'B-PDF' agar unduh/hapus/tandai tetap jalan.
     */
    public function buildFormatBPdf(?string $mulai, ?string $selesai, ?string $bulan = null, ?string $sumber = null): array
    {
        // Best-effort: naikkan bila host mengizinkan; chunking tetap jalan utama.
        @ini_set('memory_limit', '512M');

        [$transaksi, $mapping, $rows, $ringkasan, $from, $to] = $this->kumpulkanFormatB($mulai, $selesai, $bulan, $sumber);

        $perNota = $transaksi->groupBy('id');
        $chunks = array_chunk($perNota->keys()->all(), self::MAKS_NOTA_PER_PDF);
        if (count($chunks) === 0) {
            $chunks = [[]];
        }

        $stamp = now()->format('Ymd-His');
        $files = [];
        foreach (array_values($chunks) as $i => $ids) {
            // Baris dibangun ulang per chunk dari transaksi chunk tsb
            // (hemat memori: satu render dompdf hanya memuat ±150 nota).
            $rowsChunk = [];
            foreach ($ids as $id) {
                foreach ($perNota[$id] ?? collect() as $trx) {
                    foreach ($trx->items as $item) {
                        $rowsChunk[] = [
                            'Tanggal Transaksi' => $trx->tanggal_waktu->format('Y-m-d H:i'),
                            'No. Nota' => $trx->ref_transaksi,
                            'Nama Item' => $item->barang?->nama ?? '-',
                            'Qty' => $item->qty,
                            'Harga Satuan' => $item->harga_jual,
                            'Subtotal' => $item->subtotal,
                            'Total per Nota' => $trx->total,
                            'Sumber' => $trx->sumber === 'rekonstruksi' ? 'rekonstruksi' : 'manual',
                        ];
                    }
                }
            }
            $nama = count($chunks) > 1
                ? "laporan-penjualan-{$stamp}-bagian-" . ($i + 1) . '-dari-' . count($chunks) . '.pdf'
                : "laporan-penjualan-{$stamp}.pdf";
            $this->tulisPdf($nama, $mapping, $rowsChunk, $ringkasan, $i + 1, count($chunks));
            $files[] = $nama;
            unset($rowsChunk);
            gc_collect_cycles(); // bebaskan siklus dompdf antar chunk
        }

        $batch = ExportBatch::create([
            'format' => 'B-PDF',
            'filter_mulai' => $from->toDateString(),
            'filter_selesai' => $to->toDateString(),
            'filter_bulan' => $bulan,
            'jumlah_file' => count($files),
            'file_names' => $files,
            'status_upload_manual' => false,
        ]);

        return ['ok' => true, 'files' => $files, 'errors' => [], 'export_batch' => $batch];
    }

    /** Render 1 file PDF bagian ke-N (dipisah agar testable & hemat memori). */
    private function tulisPdf(string $nama, array $mapping, array $rows, array $ringkasan, int $bagian, int $totalBagian): void
    {
        $pdf = Pdf::loadView('export.format-b-pdf', [
            'toko' => TokoSetting::utama(),
            'mapping' => $mapping,
            'rows' => $rows,
            'ringkasan' => $ringkasan,
            'bagian' => $bagian,
            'totalBagian' => $totalBagian,
        ])->setPaper('a4', 'landscape');
        file_put_contents($this->pathFile($nama), $pdf->output());
    }

    private function resolvePeriode(?string $mulai, ?string $selesai, ?string $bulan): array
    {
        // Rentang tanggal eksplisit menang; shortcut bulan dipakai hanya bila tanggal kosong.
        if (filled($mulai) && filled($selesai)) {
            $from = Carbon::parse($mulai);
            $to = Carbon::parse($selesai);
            if ($from->greaterThan($to)) {
                throw new InvalidArgumentException('Rentang tanggal tidak valid.');
            }

            return [$from, $to];
        }

        if ($bulan && preg_match('/^[0-9]{4}-[0-9]{2}$/', $bulan)) {
            $from = Carbon::parse($bulan . '-01')->startOfMonth();
            $to = $from->copy()->endOfMonth();

            return [$from, $to];
        }

        $from = Carbon::parse($mulai ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($selesai ?? now()->endOfMonth()->toDateString());
        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException('Rentang tanggal tidak valid.');
        }

        return [$from, $to];
    }

    private function nilaiKolom(array $m, $trx, $item, $barang): string|int
    {
        $sumber = $m['sumber_data'];
        $value = match ($sumber) {
            'ref_transaksi' => $trx->ref_transaksi,
            'tanggal_waktu' => $trx->tanggal_waktu->format('Y-m-d H:i'),
            'customer_id' => (int) ($m['nilai_default'] !== '' ? $m['nilai_default'] : $trx->customer_id),
            'jenis_transaksi' => $m['nilai_default'] !== '' ? $m['nilai_default'] : strtoupper($trx->jenis_transaksi),
            'tipe_pembayaran' => $m['nilai_default'] !== '' ? $m['nilai_default'] : strtoupper($trx->tipe_pembayaran),
            'jatuh_tempo' => ($trx->jenis_transaksi === 'piutang' && $trx->jatuh_tempo) ? $trx->jatuh_tempo->format('Y-m-d') : '',
            'kode_barang' => $barang?->kode_barang ?? '',
            'qty' => $item->qty,
            'harga_jual' => $item->harga_jual,
            'no_sn' => $item->no_sn ?? '',
            default => $m['nilai_default'] ?? '',
        };

        return $value;
    }

    private function tulisXlsx(string $nama, array $mapping, array $rows, string $sheetNama): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetNama);

        $headers = array_column($mapping, 'nama_kolom');
        $colAwal = 'A';
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord($colAwal) + $i) . '1', $h);
        }
        $sheet->getStyle('A1:' . chr(ord($colAwal) + count($headers) - 1) . '1')->getFont()->setBold(true);

        $rowIdx = 2;
        foreach ($rows as $row) {
            $col = 0;
            foreach ($headers as $h) {
                $sheet->setCellValue(chr(ord($colAwal) + $col) . $rowIdx, $row[$h] ?? '');
                $col++;
            }
            $rowIdx++;
        }

        // Sheet PETUNJUK (PRD 8b)
        $petunjuk = $spreadsheet->createSheet();
        $petunjuk->setTitle('PETUNJUK');
        $petunjuk->setCellValue('A1', 'Data ini dihasilkan oleh ReconPOS (rekonstruksi omset — Generate Transaksi Otomatis).');
        $petunjuk->setCellValue('A2', 'Upload file ini lewat menu Super Kasir -> Import Penjualan pada aplikasi POS Bengkel.');
        $petunjuk->setCellValue('A3', 'Semua transaksi memakai CUSTOMER_ID 0 (Customer Umum).');

        return $this->simpanXlsx($spreadsheet, $nama);
    }

    private function tulisLaporan(string $nama, array $mapping, array $rows, array $ringkasan): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('LAPORAN_PENJUALAN');

        $headers = array_column($mapping, 'nama_kolom');
        $kolomAkhir = chr(ord('A') + count($headers) - 1);
        $toko = TokoSetting::utama();

        $ungu = '6D28D9';
        $abuMuda = 'F3F3FE';
        $hijauMuda = 'E6F4EA';
        $borderTipis = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFC3C6D7']]],
        ];

        $baris = 1;
        // Kop identitas toko
        $sheet->setCellValue('A' . $baris, $toko->nama_toko ?: 'ReconPOS');
        $sheet->getStyle('A' . $baris)->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF' . $ungu);
        foreach (array_filter([$toko->alamat, $toko->telepon ? 'Telp: ' . $toko->telepon : null, $toko->npwp ? 'NPWP: ' . $toko->npwp : null]) as $info) {
            $sheet->setCellValue('A' . ++$baris, $info);
            $sheet->getStyle('A' . $baris)->getFont()->setSize(11)->getColor()->setARGB('FF3F4756');
        }
        $sheet->setCellValue('A' . ++$baris, 'LAPORAN PENJUALAN — ' . ($ringkasan['Periode'] ?? ''));
        $sheet->getStyle('A' . $baris)->getFont()->setBold(true)->setSize(13);
        $baris++;

        // Ringkasan
        $barisRingkasan = $baris;
        foreach ($ringkasan as $label => $nilai) {
            if ($label === 'Periode') {
                continue; // sudah di judul
            }
            $sheet->setCellValue('A' . $baris, $label);
            $sheet->setCellValue('B' . $baris, $nilai);
            $sheet->getStyle('A' . $baris)->getFont()->setBold(true);
            $baris++;
        }
        $sheet->getStyle('A' . $barisRingkasan . ':B' . ($baris - 1))->applyFromArray($borderTipis);
        $sheet->getStyle('A' . $barisRingkasan . ':B' . ($baris - 1))->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . $hijauMuda);
        $baris++;

        // Header tabel: ungu brand + putih bold + border
        $barisHeader = $baris;
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(chr(ord('A') + $i) . $barisHeader, $h);
        }
        $rentangHeader = 'A' . $barisHeader . ':' . $kolomAkhir . $barisHeader;
        $sheet->getStyle($rentangHeader)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($rentangHeader)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . $ungu);
        $sheet->getStyle($rentangHeader)->applyFromArray($borderTipis);
        $sheet->getStyle($rentangHeader)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Isi: zebra + border + format angka ribuan utk kolom int
        $kolomInt = [];
        foreach ($mapping as $i => $m) {
            if (($m['format'] ?? '') === 'int') {
                $kolomInt[] = chr(ord('A') + $i);
            }
        }
        $rowIdx = $barisHeader + 1;
        foreach ($rows as $n => $row) {
            $col = 0;
            foreach ($headers as $h) {
                $sheet->setCellValue(chr(ord('A') + $col) . $rowIdx, $row[$h] ?? '');
                $col++;
            }
            $rentangBaris = 'A' . $rowIdx . ':' . $kolomAkhir . $rowIdx;
            $sheet->getStyle($rentangBaris)->applyFromArray($borderTipis);
            if ($n % 2 === 1) {
                $sheet->getStyle($rentangBaris)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . $abuMuda);
            }
            $rowIdx++;
        }
        foreach ($kolomInt as $kolom) {
            if ($rowIdx > $barisHeader + 1) {
                $sheet->getStyle($kolom . ($barisHeader + 1) . ':' . $kolom . ($rowIdx - 1))
                    ->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        // Baris total omset di kaki tabel
        $sheet->setCellValue('A' . $rowIdx, 'TOTAL');
        $sheet->setCellValue('B' . $rowIdx, $ringkasan['Total Omset Periode'] ?? 0);
        $sheet->getStyle('A' . $rowIdx . ':' . $kolomAkhir . $rowIdx)->getFont()->setBold(true);
        $sheet->getStyle('A' . $rowIdx . ':' . $kolomAkhir . $rowIdx)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . $hijauMuda);
        $sheet->getStyle('B' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A' . $rowIdx . ':' . $kolomAkhir . $rowIdx)->applyFromArray($borderTipis);

        // Lebar kolom, bekukan header, setelan cetak
        $lebar = [22, 20, 34, 10, 16, 16, 18, 14];
        foreach ($headers as $i => $h) {
            $sheet->getColumnDimension(chr(ord('A') + $i))->setWidth($lebar[$i] ?? 18);
        }
        $sheet->freezePane('A' . ($barisHeader + 1));
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);

        $this->simpanXlsx($spreadsheet, $nama);
    }

    private function simpanXlsx(Spreadsheet $spreadsheet, string $nama): string
    {
        $dir = storage_path('app/private/export');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $nama;
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    public function pathFile(string $nama): string
    {
        return storage_path('app/private/export/' . $nama);
    }

    /**
     * Hapus batch export beserta file fisiknya (file regenerable — tinggal unduh ulang).
     * Nama file unik per batch (timestamp), jadi tidak menyentuh file batch lain.
     */
    public function hapusBatch(ExportBatch $batch): void
    {
        foreach ($batch->file_names ?? [] as $nama) {
            $path = $this->pathFile($nama);
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $batch->delete();
    }
}
