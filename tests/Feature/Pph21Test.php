<?php

namespace Tests\Feature;

use App\Exceptions\ImportFileException;
use App\Models\Karyawan;
use App\Models\Pph21Periode;
use App\Models\TokoSetting;
use App\Models\User;
use App\Services\Pph21ExportService;
use App\Services\Pph21ImportService;
use App\Services\Pph21TerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Pph21Test extends TestCase
{
    use RefreshDatabase;

    // ---- TER: vektor dari baris contoh template (L/P/Q/R cache Excel) ----

    public static function vektorTer(): array
    {
        return [
            // ptkp, gross, expL, expP, expQ, expR
            ['TK/0', 6300001, 1.0, 1.0, 0.25, 0.0],
            ['K/2', 14000000, 5.0, 6.0, 5.0, 4.0],
            ['K/3', 15000000, 5.0, 6.0, 6.0, 5.0],
            ['TK/1', 5700000, 0.5, 0.5, 0.0, 0.0],
            ['TK/3', 6900000, 0.75, 1.25, 0.75, 0.25],
            ['K/3', 40000000, 15.0, 16.0, 15.0, 15.0],
        ];
    }

    #[DataProvider('vektorTer')]
    public function test_tarif_ter_cocok_excel(string $ptkp, int $gross, float $expL, float $expP, float $expQ, float $expR): void
    {
        $this->assertSame($expL, Pph21TerService::hitung($ptkp, $gross));
        $this->assertSame($expP, Pph21TerService::hitung('TK/0', $gross));
        $this->assertSame($expQ, Pph21TerService::hitung('TK/2', $gross));
        $this->assertSame($expR, Pph21TerService::hitung('K/3', $gross));
    }

    public function test_rumus_excel_memuat_semua_ptkp_dan_bracket(): void
    {
        $l = Pph21TerService::formulaTarif('G5', 'P5', 'Q5', 'R5');
        foreach (['TK/0', 'TK/1', 'K/0', 'HB/0', 'HB/1', 'TK/2', 'TK/3', 'K/1', 'K/2', 'HB/2', 'HB/3'] as $ptkp) {
            $this->assertStringContainsString('G5="'.$ptkp.'"', $l);
        }

        $a = Pph21TerService::formulaTer('A', 'K5');
        $this->assertStringStartsWith('=IF(K5<=5400000,0,', $a);
        $this->assertStringContainsString('K5<=1400000000),33,34', $a);
    }

    // ---- Import karyawan ----

    private function tulisXlsx(array $headers, array $rows): string
    {
        $s = new Spreadsheet;
        $sh = $s->getActiveSheet();
        foreach ($headers as $i => $h) {
            $sh->setCellValue(chr(ord('A') + $i).'1', $h);
        }
        $r = 2;
        foreach ($rows as $baris) {
            foreach ($baris as $i => $v) {
                $sh->setCellValueExplicit(chr(ord('A') + $i).$r, (string) $v, DataType::TYPE_STRING);
            }
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'kryw').'.xlsx';
        (new XlsxWriter($s))->save($path);

        return $path;
    }

    public function test_import_karyawan_upsert_by_nik(): void
    {
        TokoSetting::utama()->update(['npwp' => '0029482015507000']);
        $svc = app(Pph21ImportService::class);
        $path = $this->tulisXlsx(Pph21ImportService::HEADER_KARYAWAN, [
            ['Budi', '3275010905770021', 'Resident', '', 'TK/0', 'Sifu', 'TERMINAL BAN 1', 'N/A', '21-100-01', '', 'https://drive.test/ktp', '', '', '', '', 'YA'],
            ['Ani', '3273062212790005', 'Resident', '', 'K/2', 'Sifu', 'TERMINAL BAN 3', 'DTP', '21-100-02', '', '', 'https://drive.test/kk', '', '', '', 'YA'],
        ]);

        $hasil = $svc->importKaryawan($path);
        $this->assertSame(['dibuat' => 2, 'diupdate' => 0], $hasil);
        $this->assertSame('TERMINAL BAN 1', Karyawan::where('nik', '3275010905770021')->first()->cabang);

        $path2 = $this->tulisXlsx(Pph21ImportService::HEADER_KARYAWAN, [
            ['Budi Baru', '3275010905770021', 'Resident', '', 'K/0', 'Sifu', '', 'ETC', '21-100-32', '', '', '', '', '', '', 'TIDAK'],
        ]);
        $hasil2 = $svc->importKaryawan($path2);
        $this->assertSame(['dibuat' => 0, 'diupdate' => 1], $hasil2);
        $this->assertSame('Budi Baru', Karyawan::where('nik', '3275010905770021')->first()->nama);
        $this->assertFalse(Karyawan::where('nik', '3275010905770021')->first()->is_aktif);
    }

    public function test_import_karyawan_all_or_nothing(): void
    {
        TokoSetting::utama()->update(['npwp' => '0029482015507000']);
        $svc = app(Pph21ImportService::class);
        $path = $this->tulisXlsx(Pph21ImportService::HEADER_KARYAWAN, [
            ['Budi', '3275010905770021', 'Resident', '', 'TK/0', 'Sifu', '', 'N/A', '21-100-01', '', '', '', '', '', '', 'YA'],
            ['Salah', '123', 'Resident', '', 'TK/0', 'Sifu', '', 'N/A', '21-100-01', '', '', '', '', '', '', 'YA'],
        ]);

        try {
            $svc->importKaryawan($path);
            $this->fail('Harus lempar ImportFileException');
        } catch (ImportFileException $e) {
            $this->assertStringContainsString('16 digit', implode(' ', $e->getErrors()));
        }
        $this->assertSame(0, Karyawan::count());
    }

    // ---- Gaji + export persis ----

    private function siapkanPeriodeDenganGaji(): Pph21Periode
    {
        Karyawan::create([
            'nama' => 'Budi', 'nik' => '3275010905770021', 'status_pegawai' => 'Resident',
            'ptkp' => 'TK/0', 'posisi' => 'Sifu', 'sertifikat' => 'N/A',
            'kode_objek' => '21-100-01', 'id_tku' => '0029482015507000000000', 'is_aktif' => true,
        ]);
        Karyawan::create([
            'nama' => 'Ani', 'nik' => '3273062212790005', 'status_pegawai' => 'Resident',
            'passport' => 'X123', 'ptkp' => 'K/2', 'posisi' => 'Sifu', 'sertifikat' => 'DTP',
            'kode_objek' => '21-100-02', 'id_tku' => '0029482015507000000000', 'is_aktif' => true,
        ]);

        $periode = Pph21Periode::create([
            'masa' => 10, 'tahun' => 2024, 'tgl_pemotongan' => '2024-10-21',
            'npwp_pemotong' => '0029482015507000',
        ]);

        $svc = app(Pph21ImportService::class);
        $path = $this->tulisXlsx(Pph21ImportService::HEADER_GAJI, [
            ['3275010905770021', 'Budi', 'TERMINAL BAN 1', '40000000', ''],
            ['3273062212790005', 'Ani', 'TERMINAL BAN 3', '30000000', '12'],
        ]);
        $hasil = $svc->importGaji($periode, $path);
        $this->assertSame(2, $hasil['dibuat']);

        return $periode;
    }

    public function test_export_xml_persis_contoh_coretax(): void
    {
        $periode = $this->siapkanPeriodeDenganGaji();
        $xml = app(Pph21ExportService::class)->bangunXml($periode);

        // Well-formed + struktur persis contoh (urutan tag, indent tab, nil passport).
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('<MmPayrollBulk xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">', $xml);
        $this->assertStringContainsString('<CounterpartPassport xsi:nil="true"/>', $xml);
        $this->assertStringContainsString('<CounterpartPassport>X123</CounterpartPassport>', $xml);
        $this->assertStringContainsString('<Gross>40000000</Gross>', $xml);
        // TER auto TK/0 @40jt = 16 ; manual Ani = 12
        $this->assertStringContainsString('<Rate>16</Rate>', $xml);
        $this->assertStringContainsString('<Rate>12</Rate>', $xml);
        $this->assertStringContainsString('<WithholdingDate>2024-10-21</WithholdingDate>', $xml);
    }

    public function test_export_excel_data_mirip_template(): void
    {
        $periode = $this->siapkanPeriodeDenganGaji();
        $svc = app(Pph21ExportService::class);
        $spreadsheet = $svc->bangunSpreadsheet($periode);

        $this->assertSame(['DATA', 'BPMP', 'REF'], $spreadsheet->getSheetNames());

        $data = $spreadsheet->getSheetByName('DATA');
        $this->assertSame('NPWP Pemotong', $data->getCell('A1')->getValue());
        $this->assertSame('0029482015507000', $data->getCell('B1')->getValue());
        $this->assertSame('Masa Pajak', $data->getCell('B4')->getValue());
        $this->assertSame('TER A', $data->getCell('P4')->getValue());

        // Baris Budi (auto TER → rumus), baris Ani (manual → angka).
        $this->assertSame(10, $data->getCell('B5')->getValue());
        $this->assertSame('3275010905770021', $data->getCell('E5')->getValue());
        $this->assertStringStartsWith('=IF(OR(G5=', $data->getCell('L5')->getValue());
        $this->assertStringStartsWith('=IF(K5<=5400000,0,', $data->getCell('P5')->getValue());
        $this->assertSame(12.0, $data->getCell('L6')->getValue());

        $ref = $spreadsheet->getSheetByName('REF');
        $this->assertSame('Kode Objek Pajak', $ref->getCell('F1')->getValue());
        $this->assertSame('21-100-32', $ref->getCell('F4')->getValue());
    }

    public function test_export_mencatat_batch_dua_file(): void
    {
        $periode = $this->siapkanPeriodeDenganGaji();
        $hasil = app(Pph21ExportService::class)->export($periode);

        $this->assertTrue($hasil['ok']);
        $this->assertSame(['pph21-2024-10.xlsx', 'pph21-2024-10.xml'], $hasil['files']);
        $this->assertSame('PPh21', $hasil['export_batch']->format);
        $this->assertSame('2024-10', $hasil['export_batch']->filter_bulan);
        foreach ($hasil['files'] as $f) {
            $this->assertFileExists(storage_path('app/private/export/'.$f));
        }
    }

    public function test_export_gagal_tanpa_data_gaji(): void
    {
        $periode = Pph21Periode::create([
            'masa' => 11, 'tahun' => 2024, 'tgl_pemotongan' => '2024-11-30',
            'npwp_pemotong' => '0029482015507000',
        ]);
        $hasil = app(Pph21ExportService::class)->export($periode);

        $this->assertFalse($hasil['ok']);
        $this->assertSame([], $hasil['files']);
    }

    // ---- Cabang (kategori internal, tidak ikut export) ----

    public function test_cabang_teks_bebas_dan_validasi_panjang(): void
    {
        \App\Models\TokoSetting::utama()->update(['npwp' => '0029482015507000']);
        $svc = app(Pph21ImportService::class);

        $path = $this->tulisXlsx(Pph21ImportService::HEADER_KARYAWAN, [
            ['Budi', '3275010905770021', 'Resident', '', 'TK/0', 'Sifu', 'Cabang Baru 9', 'N/A', '21-100-01', '', '', '', '', '', '', 'YA'],
        ]);
        $svc->importKaryawan($path);
        $this->assertSame('Cabang Baru 9', Karyawan::where('nik', '3275010905770021')->first()->cabang);

        $panjang = $this->tulisXlsx(Pph21ImportService::HEADER_KARYAWAN, [
            ['Budi', '3275010905770021', 'Resident', '', 'TK/0', 'Sifu', str_repeat('X', 51), 'N/A', '21-100-01', '', '', '', '', '', '', 'YA'],
        ]);
        try {
            $svc->importKaryawan($panjang);
            $this->fail('Harus lempar ImportFileException');
        } catch (\App\Exceptions\ImportFileException $e) {
            $this->assertStringContainsString('CABANG maksimal 50', implode(' ', $e->getErrors()));
        }
    }

    public function test_filter_cabang_di_indeks(): void
    {
        $this->authUser();
        Karyawan::create(['nama' => 'A', 'nik' => '3275010905770021', 'cabang' => 'TERMINAL BAN 1', 'is_aktif' => true]);
        Karyawan::create(['nama' => 'B', 'nik' => '3273062212790005', 'cabang' => 'TERMINAL BAN 3', 'is_aktif' => true]);

        $html = $this->get(route('karyawan.index', ['cabang' => 'TERMINAL BAN 1']))->assertOk()->getContent();
        $this->assertStringContainsString('>A<', $html);
        $this->assertStringNotContainsString('>B<', $html);
    }

    public function test_export_steril_dari_cabang(): void
    {
        $periode = $this->siapkanPeriodeDenganGaji();
        Karyawan::where('nik', '3275010905770021')->update(['cabang' => 'CABANG-RAHASIA-XYZ']);

        $svc = app(Pph21ExportService::class);
        $xml = $svc->bangunXml($periode);
        $this->assertStringNotContainsString('CABANG-RAHASIA-XYZ', $xml);
        $this->assertStringNotContainsStringIgnoringCase('cabang', $xml);

        $spreadsheet = $svc->bangunSpreadsheet($periode);
        $ditemukan = [];
        foreach ($spreadsheet->getSheetNames() as $nama) {
            $sheet = $spreadsheet->getSheetByName($nama);
            foreach ($sheet->toArray(null, true, true, true) as $row) {
                foreach ($row as $v) {
                    if (is_string($v) && str_contains($v, 'CABANG-RAHASIA-XYZ')) {
                        $ditemukan[] = $nama;
                    }
                }
            }
        }
        $this->assertSame([], $ditemukan);
    }

    public function test_template_memuat_kolom_cabang(): void
    {
        $this->authUser();
        $svc = app(Pph21ImportService::class);

        $reader = new XlsxReader;
        $sheet = $reader->load($svc->templateKaryawan())->getActiveSheet();
        $header = array_values($sheet->toArray(null, true, true, true)[1]);
        $this->assertContains('CABANG', $header);

        $periode = Pph21Periode::create([
            'masa' => 1, 'tahun' => 2025, 'tgl_pemotongan' => '2025-01-31',
            'npwp_pemotong' => '0029482015507000',
        ]);
        $gaji = $reader->load($svc->templateGaji($periode))->getActiveSheet();
        $headerGaji = array_values($gaji->toArray(null, true, true, true)[1]);
        $this->assertSame(['NIK', 'NAMA', 'CABANG', 'GROSS', 'RATE_MANUAL'], $headerGaji);
    }

    // ---- HTTP ----

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'a@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    public function test_halaman_karyawan_dan_pph21_butuh_login(): void
    {
        $this->get(route('karyawan.index'))->assertRedirect(route('login'));
        $this->get(route('pph21.index'))->assertRedirect(route('login'));
    }

    public function test_crud_karyawan_via_http(): void
    {
        $this->authUser();

        $this->get(route('karyawan.index'))->assertOk()->assertSee('Data Karyawan');

        $this->post(route('karyawan.store'), [
            'nama' => 'Budi', 'nik' => '3275010905770021', 'status_pegawai' => 'Resident',
            'ptkp' => 'TK/0', 'posisi' => 'Sifu', 'sertifikat' => 'N/A',
            'kode_objek' => '21-100-01', 'is_aktif' => '1',
        ])->assertRedirect(route('karyawan.index'));
        $this->assertSame(1, Karyawan::count());

        $this->post(route('karyawan.store'), [
            'nama' => 'Salah', 'nik' => '123', 'status_pegawai' => 'Resident',
            'ptkp' => 'TK/0', 'posisi' => 'Sifu', 'sertifikat' => 'N/A', 'kode_objek' => '21-100-01',
        ])->assertSessionHasErrors('nik');
    }

    public function test_alur_periode_gaji_export_via_http(): void
    {
        $this->authUser();
        TokoSetting::utama()->update(['npwp' => '0029482015507000']);
        Karyawan::create([
            'nama' => 'Budi', 'nik' => '3275010905770021', 'status_pegawai' => 'Resident',
            'ptkp' => 'TK/0', 'posisi' => 'Sifu', 'sertifikat' => 'N/A',
            'kode_objek' => '21-100-01', 'id_tku' => '0029482015507000000000', 'is_aktif' => true,
        ]);

        $this->post(route('pph21.periode.store'), ['masa' => 10, 'tahun' => 2024])
            ->assertRedirect();
        $periode = Pph21Periode::where('masa', 10)->where('tahun', 2024)->firstOrFail();
        // Default tgl pemotongan = akhir bulan.
        $this->assertSame('2024-10-31', $periode->tgl_pemotongan->format('Y-m-d'));

        $this->get(route('pph21.show', $periode))->assertOk();

        $xlsx = $this->tulisXlsx(Pph21ImportService::HEADER_GAJI, [
            ['3275010905770021', 'Budi', '', '40000000', ''],
        ]);
        $this->post(route('pph21.gaji.import', $periode), [
            'file' => new UploadedFile($xlsx, 'gaji.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertRedirect(route('pph21.show', $periode));
        $this->assertSame(1, $periode->items()->count());

        $this->post(route('pph21.export', $periode))->assertRedirect(route('pph21.index'));
        $this->assertFileExists(storage_path('app/private/export/pph21-2024-10.xlsx'));
        $this->assertFileExists(storage_path('app/private/export/pph21-2024-10.xml'));
    }
}
