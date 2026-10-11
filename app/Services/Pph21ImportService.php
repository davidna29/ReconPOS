<?php

namespace App\Services;

use App\Exceptions\ImportFileException;
use App\Models\Karyawan;
use App\Models\Pph21Item;
use App\Models\Pph21Periode;
use App\Models\TokoSetting;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Template & import master karyawan + gaji bulanan PPh 21.
 * Aturan "all-or-nothing" mengikuti ExcelImportService: 1 baris gagal
 * validasi → seluruh file dibatalkan, tidak ada data berubah.
 */
class Pph21ImportService
{
    public const HEADER_KARYAWAN = [
        'NAMA', 'NIK', 'STATUS_PEGAWAI', 'PASSPORT', 'PTKP', 'POSISI', 'CABANG',
        'SERTIFIKAT', 'KODE_OBJEK', 'ID_TKU',
        'LINK_KTP', 'LINK_KK', 'LINK_BERKAS_1', 'LINK_BERKAS_2', 'LINK_BERKAS_3',
        'AKTIF',
    ];

    /** Kolom CABANG di template gaji hanya info (diabaikan saat import). */
    public const HEADER_GAJI = ['NIK', 'NAMA', 'CABANG', 'GROSS', 'RATE_MANUAL'];

    public function __construct(private ExcelImportService $excel) {}

    private function pastikanEkstensiExcel(): void
    {
        $kurang = $this->excel->cekEkstensiExcel();
        if (count($kurang) > 0) {
            $daftar = collect($kurang)
                ->map(fn ($ket, $ext) => $ext.' ('.$ket.')')
                ->implode(', ');

            throw new ImportFileException(
                [],
                'Server belum mengaktifkan ekstensi PHP yang dibutuhkan utk Excel: '.$daftar.'.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Template master karyawan
    // ------------------------------------------------------------------

    public function templateKaryawan(): string
    {
        $this->pastikanEkstensiExcel();

        $karyawans = Karyawan::orderBy('nama')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('KARYAWAN');
        foreach (self::HEADER_KARYAWAN as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue($col.'1', $h);
        }
        $lastCol = chr(ord('A') + count(self::HEADER_KARYAWAN) - 1);
        $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true);
        $kol = fn (string $h) => chr(ord('A') + array_search($h, self::HEADER_KARYAWAN, true));

        $row = 2;
        foreach ($karyawans as $k) {
            $sheet->setCellValue($kol('NAMA').$row, $k->nama);
            $sheet->setCellValueExplicit($kol('NIK').$row, $k->nik, DataType::TYPE_STRING);
            $sheet->setCellValue($kol('STATUS_PEGAWAI').$row, $k->status_pegawai);
            $sheet->setCellValue($kol('PASSPORT').$row, $k->passport ?? '');
            $sheet->setCellValue($kol('PTKP').$row, $k->ptkp);
            $sheet->setCellValue($kol('POSISI').$row, $k->posisi);
            $sheet->setCellValue($kol('CABANG').$row, $k->cabang ?? '');
            $sheet->setCellValue($kol('SERTIFIKAT').$row, $k->sertifikat);
            $sheet->setCellValue($kol('KODE_OBJEK').$row, $k->kode_objek);
            $sheet->setCellValueExplicit($kol('ID_TKU').$row, $k->id_tku ?? '', DataType::TYPE_STRING);
            $sheet->setCellValue($kol('LINK_KTP').$row, $k->link_ktp ?? '');
            $sheet->setCellValue($kol('LINK_KK').$row, $k->link_kk ?? '');
            $sheet->setCellValue($kol('LINK_BERKAS_1').$row, $k->link_berkas_1 ?? '');
            $sheet->setCellValue($kol('LINK_BERKAS_2').$row, $k->link_berkas_2 ?? '');
            $sheet->setCellValue($kol('LINK_BERKAS_3').$row, $k->link_berkas_3 ?? '');
            $sheet->setCellValue($kol('AKTIF').$row, $k->is_aktif ? 'YA' : 'TIDAK');
            $row++;
        }

        foreach (range('A', $lastCol) as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $this->terapkanDropdown($sheet, [
            $kol('STATUS_PEGAWAI') => '"Resident,Foreign"',
            $kol('PTKP') => '"'.implode(',', Pph21TerService::PTKP).'"',
            $kol('SERTIFIKAT') => '"'.implode(',', Pph21TerService::SERTIFIKAT).'"',
            $kol('KODE_OBJEK') => '"'.implode(',', Pph21TerService::KODE_OBJEK).'"',
            $kol('AKTIF') => '"YA,TIDAK"',
        ]);
        $this->terapkanDropdownCabang($spreadsheet, $sheet, $kol('CABANG'));

        $petunjuk = $spreadsheet->createSheet();
        $petunjuk->setTitle('PETUNJUK');
        $petunjuk->setCellValue('A1', 'Template sudah berisi SEMUA karyawan — NIK yang sudah ada akan DIUPDATE (bukan duplikat).');
        $petunjuk->setCellValue('A2', 'NIK = 16 digit angka (kunci unik). NAMA wajib. ID_TKU kosong = diisi otomatis NPWP toko + 000000.');
        $petunjuk->setCellValue('A3', 'LINK_KTP / LINK_KK / LINK_BERKAS_1..3 = teks URL (mis. link Google Drive). Maks 3 berkas tambahan.');
        $petunjuk->setCellValue('A4', 'CABANG = kategori internal teks bebas (mis. TERMINAL BAN 1) — hanya keterangan, TIDAK ikut export Coretax.');
        $petunjuk->setCellValue('A5', 'Kolom AKTIF = YA/TIDAK. Karyawan TIDAK AKTIF tidak ikut export bulanan.');
        $petunjuk->setCellValue('A6', 'Jangan ubah urutan/nama header. Baris contoh tidak ada — tambah baris di bawah data.');
        $petunjuk->getColumnDimension('A')->setWidth(120);
        $spreadsheet->setActiveSheetIndex(0);

        return $this->simpan($spreadsheet, 'template-karyawan');
    }

    private function terapkanDropdown(Worksheet $sheet, array $kolomFormula): void
    {
        foreach ($kolomFormula as $cell => $formula) {
            // setDataValidation() menimpa sqref dgn koordinatnya — daftarkan
            // dgn range penuh agar dropdown mencakup semua baris.
            $range = $cell.'2:'.$cell.'1000';
            $v = new DataValidation;
            $v->setSqref($range);
            $v->setType(DataValidation::TYPE_LIST);
            $v->setFormula1($formula);
            $v->setAllowBlank(true);
            $v->setShowDropDown(false);
            $sheet->setDataValidation($range, $v);
        }
    }

    /** Dropdown CABANG via sheet hidden (nilai teks bebas, boleh mengandung koma). */
    private function terapkanDropdownCabang(Spreadsheet $spreadsheet, Worksheet $sheet, string $kolom): void
    {
        $daftar = Karyawan::whereNotNull('cabang')->where('cabang', '<>', '')
            ->distinct()->orderBy('cabang')->pluck('cabang')->values();
        $saran = collect(Karyawan::CABANG_SARAN)->merge($daftar)->unique()->values();

        $daftarSheet = $spreadsheet->getSheetByName('Daftar');
        if (! $daftarSheet) {
            $daftarSheet = $spreadsheet->createSheet();
            $daftarSheet->setTitle('Daftar');
        }
        foreach ($saran as $i => $nama) {
            $daftarSheet->setCellValue('A'.($i + 1), $nama);
        }
        $daftarSheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        $range = $kolom.'2:'.$kolom.'1000';
        $v = new DataValidation;
        $v->setSqref($range);
        $v->setType(DataValidation::TYPE_LIST);
        $v->setFormula1("'Daftar'!\$A\$1:\$A\$".max($saran->count(), 1));
        $v->setAllowBlank(true);
        $v->setShowDropDown(false);
        $sheet->setDataValidation($range, $v);
    }

    private function simpan(Spreadsheet $spreadsheet, string $nama): string
    {
        $dir = storage_path('app/private/export');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.$nama.'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return $path;
    }

    private function bacaSheet(string $path): array
    {
        if (! file_exists($path)) {
            throw new ImportFileException([], 'File tidak ditemukan.');
        }
        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $rows = $reader->load($path)->getActiveSheet()->toArray(null, true, true, true);

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
        $ada = array_keys($dataRows[0] ?? []);
        $kurang = array_values(array_diff($wajib, $ada));
        if (count($kurang) > 0) {
            throw new ImportFileException(
                [],
                'Template '.$konteks.' tidak valid. Kolom wajib yang tidak ditemukan: '.implode(', ', $kurang)
                .'. Unduh template terbaru lalu isi ulang.'
            );
        }
    }

    private function validasiLink(?string $nilai, string $label, string $no, array &$errors): ?string
    {
        $nilai = trim((string) $nilai);
        if ($nilai === '') {
            return null;
        }
        if (mb_strlen($nilai) > 500) {
            $errors[] = 'Baris '.$no.': '.$label.' maksimal 500 karakter.';

            return null;
        }
        if (filter_var($nilai, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Baris '.$no.': '.$label.' harus berupa URL valid (mis. https://...).';

            return null;
        }

        return $nilai;
    }

    /**
     * Import master karyawan. NIK existing = update, NIK baru = create.
     *
     * @return array{dibuat: int, diupdate: int}
     */
    public function importKaryawan(string $path): array
    {
        $this->pastikanEkstensiExcel();

        $rows = $this->bacaSheet($path);
        if (count($rows) === 0) {
            throw new ImportFileException([], 'File kosong — tidak ada baris data.');
        }
        $this->cekHeader($rows, ['NAMA', 'NIK'], 'karyawan');

        $defaultIdTku = $this->defaultIdTku();
        $errors = [];
        $barisOk = [];
        $seenNik = [];
        foreach ($rows as $i => $r) {
            $no = $i + 2;
            $nama = trim((string) ($r['NAMA'] ?? ''));
            $nik = trim((string) ($r['NIK'] ?? ''));

            if ($nama === '') {
                $errors[] = 'Baris '.$no.': NAMA wajib diisi.';

                continue;
            }
            if (mb_strlen($nama) > 100) {
                $errors[] = 'Baris '.$no.': NAMA maksimal 100 karakter.';

                continue;
            }
            if (! Pph21TerService::isNikValid($nik)) {
                $errors[] = 'Baris '.$no.': NIK harus 16 digit angka.';

                continue;
            }
            if (isset($seenNik[$nik])) {
                $errors[] = 'Baris '.$no.': NIK "'.$nik.'" duplikat di dalam file.';

                continue;
            }
            $seenNik[$nik] = true;

            $statusPegawai = strtoupper(trim((string) ($r['STATUS_PEGAWAI'] ?? '')));
            $statusPegawai = $statusPegawai === '' ? 'Resident' : ucfirst(strtolower($statusPegawai));
            if (! in_array($statusPegawai, Pph21TerService::STATUS_PEGAWAI, true)) {
                $errors[] = 'Baris '.$no.': STATUS_PEGAWAI harus Resident atau Foreign.';

                continue;
            }
            $ptkp = strtoupper(trim((string) ($r['PTKP'] ?? ''))) ?: 'TK/0';
            if (! in_array($ptkp, Pph21TerService::PTKP, true)) {
                $errors[] = 'Baris '.$no.': PTKP tidak dikenal ('.$ptkp.').';

                continue;
            }
            $sertifikat = strtoupper(trim((string) ($r['SERTIFIKAT'] ?? ''))) ?: 'N/A';
            if (! in_array($sertifikat, Pph21TerService::SERTIFIKAT, true)) {
                $errors[] = 'Baris '.$no.': SERTIFIKAT harus N/A, DTP, atau ETC.';

                continue;
            }
            $kodeObjek = trim((string) ($r['KODE_OBJEK'] ?? '')) ?: '21-100-01';
            if (! in_array($kodeObjek, Pph21TerService::KODE_OBJEK, true)) {
                $errors[] = 'Baris '.$no.': KODE_OBJEK harus 21-100-01, 21-100-02, atau 21-100-32.';

                continue;
            }
            $posisi = trim((string) ($r['POSISI'] ?? '')) ?: 'Staff';
            if (mb_strlen($posisi) > 60) {
                $errors[] = 'Baris '.$no.': POSISI maksimal 60 karakter.';

                continue;
            }
            // CABANG: kategori internal teks bebas (tidak ikut export Coretax).
            $cabang = trim((string) ($r['CABANG'] ?? '')) ?: null;
            if ($cabang !== null && mb_strlen($cabang) > 50) {
                $errors[] = 'Baris '.$no.': CABANG maksimal 50 karakter.';

                continue;
            }
            $idTku = trim((string) ($r['ID_TKU'] ?? '')) ?: $defaultIdTku;
            if (! preg_match('/^\d{22}$/', $idTku)) {
                $errors[] = 'Baris '.$no.': ID_TKU harus 22 digit angka (atau kosongkan utk otomatis).';

                continue;
            }
            $aktif = strtoupper(trim((string) ($r['AKTIF'] ?? 'YA')));
            if (! in_array($aktif, ['YA', 'TIDAK'], true)) {
                $errors[] = 'Baris '.$no.': AKTIF harus YA atau TIDAK.';

                continue;
            }

            $linkKtp = $this->validasiLink($r['LINK_KTP'] ?? null, 'LINK_KTP', (string) $no, $errors);
            $linkKk = $this->validasiLink($r['LINK_KK'] ?? null, 'LINK_KK', (string) $no, $errors);
            $link1 = $this->validasiLink($r['LINK_BERKAS_1'] ?? null, 'LINK_BERKAS_1', (string) $no, $errors);
            $link2 = $this->validasiLink($r['LINK_BERKAS_2'] ?? null, 'LINK_BERKAS_2', (string) $no, $errors);
            $link3 = $this->validasiLink($r['LINK_BERKAS_3'] ?? null, 'LINK_BERKAS_3', (string) $no, $errors);

            $barisOk[] = [
                'nama' => $nama,
                'nik' => $nik,
                'status_pegawai' => $statusPegawai,
                'passport' => trim((string) ($r['PASSPORT'] ?? '')) ?: null,
                'ptkp' => $ptkp,
                'posisi' => $posisi,
                'cabang' => $cabang,
                'sertifikat' => $sertifikat,
                'kode_objek' => $kodeObjek,
                'id_tku' => $idTku,
                'link_ktp' => $linkKtp,
                'link_kk' => $linkKk,
                'link_berkas_1' => $link1,
                'link_berkas_2' => $link2,
                'link_berkas_3' => $link3,
                'is_aktif' => $aktif === 'YA',
            ];
        }

        if (count($errors) > 0) {
            throw new ImportFileException(array_slice($errors, 0, 50));
        }

        $dibuat = 0;
        $diupdate = 0;
        DB::transaction(function () use ($barisOk, &$dibuat, &$diupdate) {
            foreach ($barisOk as $r) {
                $k = Karyawan::where('nik', $r['nik'])->first();
                if ($k) {
                    $k->update($r);
                    $diupdate++;
                } else {
                    Karyawan::create($r);
                    $dibuat++;
                }
            }
        });

        return ['dibuat' => $dibuat, 'diupdate' => $diupdate];
    }

    private function defaultIdTku(): string
    {
        $npwp = preg_replace('/\D/', '', (string) (TokoSetting::utama()->npwp ?? ''));

        return strlen($npwp) === 16 ? $npwp.'000000' : '';
    }

    // ------------------------------------------------------------------
    // Template + import gaji bulanan
    // ------------------------------------------------------------------

    public function templateGaji(Pph21Periode $periode): string
    {
        $this->pastikanEkstensiExcel();

        $items = $periode->items()->with('karyawan')->get()->keyBy('karyawan_id');
        $karyawans = Karyawan::aktif()->orderBy('nama')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('GAJI');
        foreach (self::HEADER_GAJI as $i => $h) {
            $sheet->setCellValue(chr(ord('A') + $i).'1', $h);
        }
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);

        $row = 2;
        foreach ($karyawans as $k) {
            $item = $items->get($k->id);
            $sheet->setCellValueExplicit('A'.$row, $k->nik, DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$row, $k->nama);
            $sheet->setCellValue('C'.$row, $k->cabang ?? '');
            $sheet->setCellValue('D'.$row, $item ? $item->gross : '');
            $sheet->setCellValue('E'.$row, $item && $item->is_rate_manual ? $item->rate : '');
            $row++;
        }
        foreach (['A', 'B', 'C', 'D', 'E'] as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $petunjuk = $spreadsheet->createSheet();
        $petunjuk->setTitle('PETUNJUK');
        $petunjuk->setCellValue('A1', 'Periode '.$periode->label().' — tgl pemotongan default '.$periode->tgl_pemotongan->format('Y-m-d').'.');
        $petunjuk->setCellValue('A2', 'GROSS = penghasilan kotor bulan ini (angka, tanpa titik/koma). Baris tanpa GROSS = dilewati (tidak ikut export).');
        $petunjuk->setCellValue('A3', 'RATE_MANUAL kosong = tarif otomatis TER dari template. Isi bila pakai fasilitas (mis. DTP/ETC) — desimal pakai titik.');
        $petunjuk->setCellValue('A4', 'NIK harus sudah terdaftar di master karyawan + status AKTIF. NIK asing ditolak.');
        $petunjuk->setCellValue('A5', 'Kolom CABANG hanya keterangan (diabaikan saat upload) — ubah cabang lewat master karyawan.');
        $petunjuk->getColumnDimension('A')->setWidth(120);
        $spreadsheet->setActiveSheetIndex(0);

        return $this->simpan($spreadsheet, 'template-gaji-'.$periode->tahun.'-'.str_pad((string) $periode->masa, 2, '0', STR_PAD_LEFT));
    }

    /**
     * Import gaji: NIK harus ada di master + aktif. Membuat/memperbarui
     * pph21_items periode ini (snapshot master + tarif TER / manual).
     *
     * @return array{dibuat: int, diupdate: int, dilewati: int}
     */
    public function importGaji(Pph21Periode $periode, string $path): array
    {
        $this->pastikanEkstensiExcel();

        $rows = $this->bacaSheet($path);
        if (count($rows) === 0) {
            throw new ImportFileException([], 'File kosong — tidak ada baris data.');
        }
        $this->cekHeader($rows, ['NIK', 'GROSS'], 'gaji');

        $errors = [];
        $barisOk = [];
        $seenNik = [];
        foreach ($rows as $i => $r) {
            $no = $i + 2;
            $nik = trim((string) ($r['NIK'] ?? ''));

            if ($nik === '' && trim((string) ($r['GROSS'] ?? '')) === '' && trim((string) ($r['RATE_MANUAL'] ?? '')) === '') {
                continue;
            }
            if (! Pph21TerService::isNikValid($nik)) {
                $errors[] = 'Baris '.$no.': NIK harus 16 digit angka.';

                continue;
            }
            if (isset($seenNik[$nik])) {
                $errors[] = 'Baris '.$no.': NIK "'.$nik.'" duplikat di dalam file.';

                continue;
            }
            $seenNik[$nik] = true;

            $karyawan = Karyawan::where('nik', $nik)->first();
            if (! $karyawan) {
                $errors[] = 'Baris '.$no.': NIK "'.$nik.'" tidak terdaftar di master karyawan.';

                continue;
            }
            if (! $karyawan->is_aktif) {
                $errors[] = 'Baris '.$no.': "'.$karyawan->nama.'" berstatus TIDAK AKTIF.';

                continue;
            }

            $grossRaw = trim((string) ($r['GROSS'] ?? ''));
            if ($grossRaw === '') {
                continue; // tanpa gross = dilewati
            }
            if (! is_numeric($grossRaw) || (float) $grossRaw < 0) {
                $errors[] = 'Baris '.$no.': GROSS harus angka ≥ 0.';

                continue;
            }
            $gross = (int) round((float) $grossRaw);

            $rateManualRaw = trim((string) ($r['RATE_MANUAL'] ?? ''));
            $rate = null;
            $isManual = false;
            if ($rateManualRaw !== '') {
                if (! is_numeric($rateManualRaw) || (float) $rateManualRaw < 0) {
                    $errors[] = 'Baris '.$no.': RATE_MANUAL harus angka ≥ 0 (desimal pakai titik).';

                    continue;
                }
                $rate = round((float) $rateManualRaw, 2);
                $isManual = true;
            } else {
                $rate = Pph21TerService::hitung($karyawan->ptkp, $gross);
            }

            $barisOk[] = [
                'karyawan' => $karyawan,
                'status_pegawai' => $karyawan->status_pegawai,
                'nik' => $karyawan->nik,
                'passport' => $karyawan->passport,
                'ptkp' => $karyawan->ptkp,
                'posisi' => $karyawan->posisi,
                'sertifikat' => $karyawan->sertifikat,
                'kode_objek' => $karyawan->kode_objek,
                'id_tku' => $karyawan->id_tku ?: $this->defaultIdTku(),
                'gross' => $gross,
                'rate' => $rate,
                'is_rate_manual' => $isManual,
            ];
        }

        if (count($errors) > 0) {
            throw new ImportFileException(array_slice($errors, 0, 50));
        }

        $dibuat = 0;
        $diupdate = 0;
        DB::transaction(function () use ($periode, $barisOk, &$dibuat, &$diupdate) {
            foreach ($barisOk as $r) {
                /** @var Karyawan $karyawan */
                $karyawan = $r['karyawan'];
                unset($r['karyawan']);
                $item = Pph21Item::where('periode_id', $periode->id)
                    ->where('karyawan_id', $karyawan->id)
                    ->first();
                if ($item) {
                    $item->update($r);
                    $diupdate++;
                } else {
                    Pph21Item::create(array_merge($r, [
                        'periode_id' => $periode->id,
                        'karyawan_id' => $karyawan->id,
                    ]));
                    $dibuat++;
                }
            }
        });

        return ['dibuat' => $dibuat, 'diupdate' => $diupdate, 'dilewati' => 0];
    }
}
