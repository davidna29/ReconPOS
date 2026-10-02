<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RekapStokTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'rekap@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    private function setupBan(): void
    {
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'BL-26570R17-SAV-MTP', 'nama' => 'BAN LUAR 265/70 R17 SAVERO M/T PRO', 'sn_atau_non_sn' => 'NON_SN', 'merk' => 'Gajah Tunggal (GT)', 'varian' => 'SAVERO M/T PRO', 'ukuran_ban' => '265/70 R17', 'stock' => 21]);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'BL-26565R17-SAV-MTP', 'nama' => 'BAN LUAR 265/65 R17 SAVERO M/T PRO', 'sn_atau_non_sn' => 'NON_SN', 'merk' => 'Gajah Tunggal (GT)', 'varian' => 'SAVERO M/T PRO', 'ukuran_ban' => '265/65 R17', 'stock' => 0]);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'BL-18570R14-CHA-ECO', 'nama' => 'BAN LUAR 185/70 R14 CHAMPIRO ECOTEC', 'sn_atau_non_sn' => 'NON_SN', 'merk' => 'Gajah Tunggal (GT)', 'varian' => 'CHAMPIRO ECOTEC', 'ukuran_ban' => '185/70 R14', 'stock' => 5]);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'HK-195R15-VAN', 'nama' => 'BAN 195 R15 VANTRA', 'sn_atau_non_sn' => 'NON_SN', 'merk' => 'HANKOOK', 'varian' => 'VANTRA', 'ukuran_ban' => '195 R15', 'stock' => 3]);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'OLI-001', 'nama' => 'Oli Mesin', 'sn_atau_non_sn' => 'NON_SN', 'stock' => 7]);
    }

    public function test_rekap_mengelompokkan_merk_varian_dan_total(): void
    {
        $this->authUser();
        $this->setupBan();

        $this->get(route('rekap-stok.index'))
            ->assertOk()
            ->assertSee('Rekap Stok per Merk')
            ->assertSee('Gajah Tunggal (GT)')
            ->assertSee('HANKOOK')
            ->assertSee('Tanpa Merk')
            ->assertSee('SAVERO M/T PRO')
            ->assertSee('265/70 R17')
            ->assertSee('BL-26570R17-SAV-MTP');
    }

    public function test_rekap_tanpa_merk_selalu_paling_bawah(): void
    {
        $this->authUser();
        $this->setupBan();

        $html = $this->get(route('rekap-stok.index'))->assertOk()->getContent();
        $this->assertLessThan(
            strpos($html, 'Tanpa Merk'),
            strpos($html, 'Gajah Tunggal (GT)'),
            'Grup Tanpa Merk harus tampil setelah grup bermerk.'
        );
    }

    public function test_rekap_filter_merk_dan_cari(): void
    {
        $this->authUser();
        $this->setupBan();

        $this->get(route('rekap-stok.index', ['merk' => 'HANKOOK']))
            ->assertOk()->assertSee('HK-195R15-VAN')->assertDontSee('BL-26570R17-SAV-MTP');
        $this->get(route('rekap-stok.index', ['q' => '265/70']))
            ->assertOk()->assertSee('BL-26570R17-SAV-MTP')->assertDontSee('HK-195R15-VAN');
    }

    public function test_rekap_sembunyikan_kosong(): void
    {
        $this->authUser();
        $this->setupBan();

        // default: stok 0 tetap tampil
        $this->get(route('rekap-stok.index'))->assertOk()->assertSee('BL-26565R17-SAV-MTP');
        // dengan toggle: disembunyikan
        $this->get(route('rekap-stok.index', ['sembunyikan_kosong' => 1]))
            ->assertOk()->assertSee('BL-26570R17-SAV-MTP')->assertDontSee('BL-26565R17-SAV-MTP');
    }

    public function test_rekap_butuh_login_untuk_admin(): void
    {
        $this->get(route('rekap-stok.index'))->assertRedirect(route('login'));
    }

    public function test_rekap_publik_terbuka_tanpa_login_readonly(): void
    {
        $this->setupBan();

        $this->get(route('publik.rekap-stok.index'))
            ->assertOk()
            ->assertSee('Gajah Tunggal (GT)')
            ->assertSee('BL-26570R17-SAV-MTP')
            ->assertSee('Mode Publik')
            ->assertDontSee('Tambah Data');
    }

    public function test_sidebar_memuat_link_rekap(): void
    {
        $this->authUser();
        $this->get(route('dashboard'))->assertOk()->assertSee(route('rekap-stok.index'));

        $html = $this->get(route('publik.landing'))->assertOk()->getContent();
        $this->assertStringContainsString(route('publik.rekap-stok.index'), $html);
    }

    public function test_export_xlsx_dua_sheet_angka_sama_layar(): void
    {
        $this->authUser();
        $this->setupBan();

        $res = $this->get(route('rekap-stok.export.xlsx'));
        $res->assertOk();
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $files = glob(storage_path('app/private/export/rekap-stok-*.xlsx')) ?: [];
        $this->assertNotEmpty($files);
        $path = collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
        try {
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $this->assertSame(['Ringkasan', 'Rincian'], $book->getSheetNames());

            $grand = null;
            foreach ($book->getSheetByName('Ringkasan')->toArray(null, true, false) as $row) {
                if (($row[0] ?? '') === 'GRAND TOTAL') {
                    $grand = [(int) $row[1], (int) $row[2]];
                }
            }
            // 5 SKU, total stok 21+0+5+3+7
            $this->assertSame([5, 36], $grand);

            $teks = json_encode($book->getSheetByName('Rincian')->toArray(null, true, false));
            $this->assertStringContainsString('BL-26570R17-SAV-MTP', $teks);
            $this->assertStringContainsString('HANKOOK', $teks);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_pdf_header_dan_isi(): void
    {
        $this->authUser();
        $this->setupBan();

        $res = $this->get(route('rekap-stok.export.pdf'));
        $res->assertOk();
        $this->assertStringStartsWith('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('rekap-stok-' . now()->format('Ymd') . '.pdf', $res->headers->get('content-disposition'));

        $files = glob(storage_path('app/private/export/rekap-stok-*.pdf')) ?: [];
        $this->assertNotEmpty($files);
        $path = collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
        try {
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_export_menghormati_filter_merk(): void
    {
        $this->authUser();
        $this->setupBan();

        $res = $this->get(route('rekap-stok.export.xlsx', ['merk' => 'HANKOOK']));
        $res->assertOk();

        $files = glob(storage_path('app/private/export/rekap-stok-*.xlsx')) ?: [];
        $path = collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
        try {
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $grand = null;
            foreach ($book->getSheetByName('Ringkasan')->toArray(null, true, false) as $row) {
                if (($row[0] ?? '') === 'GRAND TOTAL') {
                    $grand = [(int) $row[1], (int) $row[2]];
                }
            }
            $this->assertSame([1, 3], $grand);
            $teks = json_encode($book->getSheetByName('Rincian')->toArray(null, true, false));
            $this->assertStringNotContainsString('SAVERO', $teks);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_tanpa_login_ditolak(): void
    {
        $this->get(route('rekap-stok.export.xlsx'))->assertRedirect(route('login'));
        $this->get(route('rekap-stok.export.pdf'))->assertRedirect(route('login'));
    }
}
