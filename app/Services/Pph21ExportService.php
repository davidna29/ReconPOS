<?php

namespace App\Services;

use App\Models\ExportBatch;
use App\Models\Pph21Periode;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export PPh 21 bulanan persis contoh Coretax:
 * - Excel 3 sheet (DATA/BPMP/REF) meniru "TEMPLATE BPMP Excel to XML v.3.xlsx"
 * - XML MmPayrollBulk meniru "CONTOH-HASIL-XML-bpmp.xml"
 * - Dicatat di export_batch (format PPh21) agar bisa diunduh ulang.
 */
class Pph21ExportService
{
    /** Lebar kolom sheet DATA persis template. */
    private const LEBAR_DATA = [
        'A' => 20.0, 'B' => 17.33203125, 'C' => 13.83203125, 'D' => 16.6640625,
        'E' => 25.5, 'F' => 17.5, 'G' => 8.6640625, 'H' => 10.5,
        'I' => 19.6640625, 'J' => 18.83203125, 'K' => 19.5, 'L' => 7.33203125,
        'M' => 24.5, 'N' => 17.6640625, 'P' => 6.5, 'Q' => 6.1640625,
    ];

    private const HEADER_DATA = [
        'B' => 'Masa Pajak', 'C' => 'Tahun Pajak', 'D' => 'Status Pegawai',
        'E' => 'NPWP/NIK/TIN', 'F' => 'Nomor Passport', 'G' => 'Status',
        'H' => 'Posisi', 'I' => 'Sertifikat/Fasilitas', 'J' => 'Kode Objek Pajak',
        'K' => 'Penghasilan Kotor', 'L' => 'Tarif', 'M' => 'ID TKU',
        'N' => 'Tgl Pemotongan', 'P' => 'TER A', 'Q' => 'TER B', 'R' => 'TER C',
    ];

    private const BPMP = [
        ['Kolom pada excel', 'Kolom pada xml', 'Petunjuk pengisian', 'Contoh pengisian', 'Validasi', 'Keterangan tambahan'],
        ['NPWP Pemotong', 'TIN', 'Diisi dengan NPWP pemotong', '1234567890123456', 'NPWP Pemotong harus sama dengan NPWP login', null],
        ['Masa Pajak', 'TaxPeriodMonth', 'Diisi dengan masa pajak pemotongan', '1', null, null],
        ['Tahun Pajak', 'TaxPeriodYear', 'Diisi dengan masa pajak pemotongan', '2025', null, null],
        ['Status Pegawai', 'CounterpartOpt', 'Diisi dengan status kewarganegaraan pegawai (karyawan asing atau tidak)', 'Resident', null, null],
        ['NPWP/NIK/TIN', 'CounterpartTin', 'Diisi dengan NIK pegawai tetap', '0987654321098765', 'NPWP/NIK wajib valid', null],
        ['Nomor Passport', 'CounterpartPassport', 'Diisi dengan nomor paspor pegawai tetap (jika karyawan asing)', null, null, null],
        ['Status', 'StatusTaxExemption', 'Diisi dengan status PTKP penerima penghasilan', 'TK/3', null, null],
        ['Posisi', 'Position', 'Diisi dengan posisi pegawai tetap', 'Staff', null, null],
        ['Sertifikat/Fasilitas', 'TaxCertificate', 'Diisi dengan fasilitas perpajakan yang digunakan', 'N/A', null, null],
        ['Kode Objek Pajak', 'TaxObjectCode', 'Diisi dengan kode objek pajak', '21-100-01', null, null],
        ['Penghasilan Kotor', 'Gross', 'Diisi dengan penghasilan bruto', 10000000, null, null],
        ['Tarif', 'Rate', 'Diisi dengan tarif yang sesuai dengan referensi kode objek pajak', 2, 'Jika menggunakan fasilitas perpajakan lainnya, tarif dapat diisi tidak sesuai dengan referensi kode objek pajak', 'Jika tarif menggunakan koma, di export ke xml, excel secara otomatis akan merubah menjadi format desimal menggunakan titik'],
        ['ID TKU', 'IDPlaceOfBusinessActivity', 'Diisi dengan ID TKU pemotong', '1234567890123456789012', null, null],
        ['Tgl Pemotongan', 'WithholdingDate', 'Diisi dengan tanggal pemotongan', '2025-01-16', 'Tanggal pemotongan tidak boleh lebih rendah dari masa/tahun pajak bukti potong', 'Saat di export ke xml, excel secara otomatis akan merubah menjadi format YYYY-MM-DD'],
    ];

    private const REF_PTKP = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3', 'HB/0', 'HB/1', 'HB/2', 'HB/3'];

    private const REF_OBJEK = [
        ['21-100-01', 'Penghasilan yang diterima oleh Pegawai Tetap termasuk Pegawai Negeri Sipil, Anggota Tentara Nasional Indonesia, Anggota Polisi Republik Indonesia atau Pejabat Negara'],
        ['21-100-02', 'Penghasilan yang diterima oleh Penerima Pensiun secara  teratur'],
        ['21-100-32', 'Penghasilan yang diterima oleh Pegawai tetap yang menerima fasilitas di daerah tertentu'],
    ];

    /**
     * Pre-validasi ala sheet BPMP. Gagal → exception, file tidak dihasilkan.
     *
     * @return array{ok: bool, errors: array}
     */
    public function validasi(Pph21Periode $periode): array
    {
        $errors = [];

        if (! Pph21TerService::isNpwpPemotongValid($periode->npwp_pemotong)) {
            $errors[] = 'NPWP pemotong harus 16 digit angka (cek Pengaturan → NPWP CV TERMINAL BAN).';
        }

        $items = $periode->items()->get();
        if ($items->isEmpty()) {
            $errors[] = 'Periode '.$periode->label().' belum punya data gaji — import template gaji dulu.';
        }

        $awalPeriode = sprintf('%04d-%02d-01', $periode->tahun, $periode->masa);
        if ($periode->tgl_pemotongan->format('Y-m-d') < $awalPeriode) {
            $errors[] = 'Tanggal pemotongan tidak boleh lebih rendah dari masa/tahun pajak bukti potong.';
        }

        foreach ($items as $it) {
            if (! Pph21TerService::isNikValid($it->nik)) {
                $errors[] = 'NPWP/NIK wajib valid: "'.$it->nik.'" tidak 16 digit.';
            }
            if (! preg_match('/^\d{22}$/', (string) $it->id_tku)) {
                $errors[] = 'ID TKU "'.$it->nik.'" harus 22 digit angka.';
            }
        }

        return ['ok' => count($errors) === 0, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Export lengkap: pre-validasi → xlsx + xml → catat batch.
     *
     * @return array{ok: bool, files: array, errors: array, export_batch?: ExportBatch}
     */
    public function export(Pph21Periode $periode): array
    {
        $cek = $this->validasi($periode);
        if (! $cek['ok']) {
            return ['ok' => false, 'files' => [], 'errors' => $cek['errors']];
        }

        $stamp = sprintf('%04d-%02d', $periode->tahun, $periode->masa);
        $namaXlsx = 'pph21-'.$stamp.'.xlsx';
        $namaXml = 'pph21-'.$stamp.'.xml';

        $this->simpanXlsx($this->bangunSpreadsheet($periode), $namaXlsx);
        file_put_contents($this->pathFile($namaXml), $this->bangunXml($periode));

        $batch = ExportBatch::create([
            'format' => 'PPh21',
            'filter_mulai' => null,
            'filter_selesai' => null,
            'filter_bulan' => sprintf('%04d-%02d', $periode->tahun, $periode->masa),
            'jumlah_file' => 2,
            'file_names' => [$namaXlsx, $namaXml],
            'status_upload_manual' => false,
        ]);

        return ['ok' => true, 'files' => [$namaXlsx, $namaXml], 'errors' => [], 'export_batch' => $batch];
    }

    public function pathFile(string $nama): string
    {
        return storage_path('app/private/export/'.$nama);
    }

    // ------------------------------------------------------------------
    // Excel
    // ------------------------------------------------------------------

    public function bangunSpreadsheet(Pph21Periode $periode): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $data = $spreadsheet->getActiveSheet();
        $data->setTitle('DATA');

        foreach (self::LEBAR_DATA as $col => $lebar) {
            $data->getColumnDimension($col)->setWidth($lebar);
        }

        $data->setCellValue('A1', 'NPWP Pemotong');
        $data->setCellValueExplicit('B1', $periode->npwp_pemotong, DataType::TYPE_STRING);

        foreach (self::HEADER_DATA as $col => $judul) {
            $data->setCellValue($col.'4', $judul);
        }

        $items = $periode->items()->with('karyawan')->orderBy('id')->get();
        $row = 5;
        foreach ($items as $it) {
            $data->setCellValue('B'.$row, $periode->masa);
            $data->setCellValue('C'.$row, $periode->tahun);
            $data->setCellValue('D'.$row, $it->status_pegawai);
            $data->setCellValueExplicit('E'.$row, $it->nik, DataType::TYPE_STRING);
            $data->setCellValue('F'.$row, $it->passport ?? '');
            $data->setCellValue('G'.$row, $it->ptkp);
            $data->setCellValue('H'.$row, $it->posisi);
            $data->setCellValue('I'.$row, $it->sertifikat);
            $data->setCellValue('J'.$row, $it->kode_objek);
            $data->setCellValue('K'.$row, $it->gross);
            if ($it->is_rate_manual) {
                $data->setCellValue('L'.$row, (float) $it->rate);
            } else {
                $data->setCellValue('L'.$row, Pph21TerService::formulaTarif('G'.$row, 'P'.$row, 'Q'.$row, 'R'.$row));
            }
            $data->setCellValueExplicit('M'.$row, $it->id_tku, DataType::TYPE_STRING);
            $data->setCellValue('N'.$row, Date::dateTimeToExcel(new \DateTimeImmutable($periode->tgl_pemotongan->format('Y-m-d'))));
            $data->getStyle('N'.$row)->getNumberFormat()->setFormatCode('mm-dd-yy');
            $data->setCellValue('P'.$row, Pph21TerService::formulaTer('A', 'K'.$row));
            $data->setCellValue('Q'.$row, Pph21TerService::formulaTer('B', 'K'.$row));
            $data->setCellValue('R'.$row, Pph21TerService::formulaTer('C', 'K'.$row));
            $row++;
        }

        // Dropdown kode objek persis template (J5:J10, melebar ikut data).
        // Catatan: setDataValidation() menimpa sqref dgn koordinatnya,
        // jadi daftarkan dgn range penuh agar validasi mencakup semua baris.
        $rangeVal = 'J5:J'.max($row - 1, 10);
        $v = new DataValidation;
        $v->setSqref($rangeVal);
        $v->setType(DataValidation::TYPE_LIST);
        $v->setFormula1('"'.implode(',', Pph21TerService::KODE_OBJEK).'"');
        $v->setAllowBlank(true);
        $v->setShowDropDown(false);
        $data->setDataValidation($rangeVal, $v);

        $this->tulisBpmp($spreadsheet);
        $this->tulisRef($spreadsheet);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function tulisBpmp(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('BPMP');
        foreach (['A' => 17.5, 'B' => 24.5, 'C' => 67.83203125, 'D' => 23.5, 'E' => 100.6640625, 'F' => 105.33203125] as $col => $lebar) {
            $sheet->getColumnDimension($col)->setWidth($lebar);
        }

        $row = 1;
        foreach (self::BPMP as $baris) {
            $col = 'A';
            foreach ($baris as $nilai) {
                if ($nilai === null) {
                    $col++;

                    continue;
                }
                if (is_int($nilai)) {
                    $sheet->setCellValue($col.$row, $nilai);
                } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $nilai, $tgl)) {
                    $sheet->setCellValue($col.$row, Date::dateTimeToExcel(new \DateTimeImmutable($nilai)));
                    $sheet->getStyle($col.$row)->getNumberFormat()->setFormatCode('mm-dd-yy');
                } else {
                    $sheet->setCellValueExplicit($col.$row, (string) $nilai, DataType::TYPE_STRING);
                }
                $col++;
            }
            $row++;
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
    }

    private function tulisRef(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('REF');
        $sheet->getColumnDimension('F')->setWidth(16.5);
        $sheet->getColumnDimension('G')->setWidth(156.1640625);

        $sheet->setCellValue('F1', 'Kode Objek Pajak');
        $sheet->setCellValue('G1', 'Nama Objek Pajak');

        $sheet->setCellValue('B2', 'Resident');
        $sheet->setCellValue('B3', 'Foreign');
        $sheet->setCellValue('D2', 'N/A');
        $sheet->setCellValue('D3', 'DTP');
        $sheet->setCellValue('D4', 'ETC');

        $row = 2;
        foreach (self::REF_PTKP as $ptkp) {
            $sheet->setCellValue('C'.$row, $ptkp);
            $row++;
        }

        $row = 2;
        foreach (self::REF_OBJEK as [$kode, $nama]) {
            $sheet->setCellValue('F'.$row, $kode);
            $sheet->setCellValue('G'.$row, $nama);
            $row++;
        }
    }

    private function simpanXlsx(Spreadsheet $spreadsheet, string $nama): string
    {
        $dir = storage_path('app/private/export');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.$nama;
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    // ------------------------------------------------------------------
    // XML (meniru CONTOH-HASIL-XML-bpmp.xml)
    // ------------------------------------------------------------------

    public function bangunXml(Pph21Periode $periode): string
    {
        $items = $periode->items()->orderBy('id')->get();
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1, 'UTF-8');
        $rateFmt = function ($rate): string {
            $s = number_format((float) $rate, 2, '.', '');
            $s = rtrim(rtrim($s, '0'), '.');

            return $s === '' ? '0' : $s;
        };

        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\r\n";
        $out .= '<MmPayrollBulk xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'."\r\n";
        $out .= "\t<TIN>".$esc($periode->npwp_pemotong)."</TIN>\r\n";
        $out .= "\t<ListOfMmPayroll>\r\n";
        foreach ($items as $it) {
            $out .= "\t\t<MmPayroll>\r\n";
            $out .= "\t\t\t<TaxPeriodMonth>".$periode->masa."</TaxPeriodMonth>\r\n";
            $out .= "\t\t\t<TaxPeriodYear>".$periode->tahun."</TaxPeriodYear>\r\n";
            $out .= "\t\t\t<CounterpartOpt>".$esc($it->status_pegawai)."</CounterpartOpt>\r\n";
            if ($it->passport) {
                $out .= "\t\t\t<CounterpartPassport>".$esc($it->passport)."</CounterpartPassport>\r\n";
            } else {
                $out .= "\t\t\t<CounterpartPassport xsi:nil=\"true\"/>\r\n";
            }
            $out .= "\t\t\t<CounterpartTin>".$esc($it->nik)."</CounterpartTin>\r\n";
            $out .= "\t\t\t<StatusTaxExemption>".$esc($it->ptkp)."</StatusTaxExemption>\r\n";
            $out .= "\t\t\t<Position>".$esc($it->posisi)."</Position>\r\n";
            $out .= "\t\t\t<TaxCertificate>".$esc($it->sertifikat)."</TaxCertificate>\r\n";
            $out .= "\t\t\t<TaxObjectCode>".$esc($it->kode_objek)."</TaxObjectCode>\r\n";
            $out .= "\t\t\t<Gross>".$it->gross."</Gross>\r\n";
            $out .= "\t\t\t<Rate>".$rateFmt($it->rate)."</Rate>\r\n";
            $out .= "\t\t\t<IDPlaceOfBusinessActivity>".$esc($it->id_tku)."</IDPlaceOfBusinessActivity>\r\n";
            $out .= "\t\t\t<WithholdingDate>".$periode->tgl_pemotongan->format('Y-m-d')."</WithholdingDate>\r\n";
            $out .= "\t\t</MmPayroll>\r\n";
        }
        $out .= "\t</ListOfMmPayroll>\r\n";
        $out .= '</MmPayrollBulk>';

        return $out;
    }
}
