<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\ExportBatch;
use App\Models\Supplier;
use App\Models\TransaksiPenjualan;
use App\Models\User;
use App\Services\ExportPosService;
use App\Services\PembelianService;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'e@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    private function buatPenjualan(): void
    {
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'X-1', 'nama' => 'X', 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 250000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 5, 'diskon_item' => 0]],
        ]);
        app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 2, 'harga_jual' => 60000]]);
    }

    public function test_format_a_menghasilkan_xlsx(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        $svc = app(ExportPosService::class);
        $hasil = $svc->buildFormatA(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));
        $this->assertTrue($hasil['ok']);
        $this->assertNotEmpty($hasil['files']);
        $this->assertCount(0, $hasil['errors']);
        $path = $svc->pathFile($hasil['files'][0]);
        $this->assertFileExists($path);
        $this->assertDatabaseHas('export_batch', ['format' => 'A', 'status_upload_manual' => false]);
    }

    public function test_format_a_kolom_mengikuti_template_konfigurasi(): void
    {
        $this->authUser();
        $mapping = app(ExportPosService::class)->mappingAktif();
        $this->assertContains('REF_TRANSAKSI', array_column($mapping, 'nama_kolom'));
        $this->assertContains('KODE_BARANG', array_column($mapping, 'nama_kolom'));
        $this->assertContains('NO_SN', array_column($mapping, 'nama_kolom'));
    }

    public function test_format_b_menghasilkan_laporan(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        $svc = app(ExportPosService::class);
        $hasil = $svc->buildFormatB(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));
        $this->assertTrue($hasil['ok']);
        $path = $svc->pathFile($hasil['files'][0]);
        $this->assertFileExists($path);
    }

    public function test_format_b_memuat_identitas_toko_dan_warna_header(): void
    {
        $this->authUser();
        \App\Models\TokoSetting::utama()->update([
            'nama_toko' => 'Terminal Ban 1', 'alamat' => 'Jl. Raya No. 1',
            'telepon' => '0811', 'npwp' => '01.234.567.8-901.000',
        ]);
        $this->buatPenjualan();
        $svc = app(ExportPosService::class);
        $hasil = $svc->buildFormatB(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $sheet = $reader->load($svc->pathFile($hasil['files'][0]))->getActiveSheet();
        $teks = static function ($cell) use ($sheet) {
            $max = $sheet->getHighestRow();
            for ($r = 1; $r <= $max; $r++) {
                if (str_contains((string) $sheet->getCell($cell[0] . $r)->getValue(), $cell[1])) {
                    return $r;
                }
            }
            return 0;
        };

        // identitas toko + judul + NPWP tampil
        $this->assertGreaterThan(0, $teks(['A', 'Terminal Ban 1']));
        $this->assertGreaterThan(0, $teks(['A', 'Jl. Raya No. 1']));
        $this->assertGreaterThan(0, $teks(['A', '01.234.567.8-901.000']));
        $this->assertGreaterThan(0, $teks(['A', 'LAPORAN PENJUALAN']));

        // baris header ber-fill ungu brand + font putih bold
        $barisHeader = $teks(['A', 'Tanggal Transaksi']);
        $this->assertGreaterThan(0, $barisHeader);
        $style = $sheet->getStyle('A' . $barisHeader);
        $this->assertSame('FF6D28D9', $style->getFill()->getStartColor()->getARGB());
        $this->assertTrue($style->getFont()->getBold());
        $this->assertSame('FFFFFFFF', $style->getFont()->getColor()->getARGB());

        // baris TOTAL di kaki tabel
        $this->assertGreaterThan($barisHeader, $teks(['A', 'TOTAL']));
    }

    public function test_format_b_pdf_menghasilkan_pdf_dan_tercatat(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        $svc = app(ExportPosService::class);
        $hasil = $svc->buildFormatBPdf(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));

        $this->assertTrue($hasil['ok']);
        $path = $svc->pathFile($hasil['files'][0]);
        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
        $this->assertDatabaseHas('export_batch', ['format' => 'B-PDF', 'status_upload_manual' => false]);

        // unduh ulang via riwayat + hapus ikut menghapus file fisik
        $batch = \App\Models\ExportBatch::where('format', 'B-PDF')->firstOrFail();
        $this->get(route('export.unduh', [$batch, 0]))->assertOk();
        $this->delete(route('export.destroy', $batch))->assertSessionHas('success');
        $this->assertFileDoesNotExist($path);
    }

    public function test_format_b_pdf_dipegang_pecah_per_150_nota(): void
    {
        $this->authUser();
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'X-CHUNK', 'nama' => 'X', 'sn_atau_non_sn' => 'NON_SN']);
        // 160 nota langsung via model (tanpa gerakan stok — export hanya membaca).
        for ($i = 1; $i <= 160; $i++) {
            $trx = TransaksiPenjualan::create([
                'ref_transaksi' => 'INV-TEST-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'tanggal_waktu' => now()->format('Y-m-d H:i'),
                'customer_id' => 0, 'jenis_transaksi' => 'cash', 'tipe_pembayaran' => 'cash',
                'sumber' => 'manual', 'status' => 'tersimpan', 'total' => 60000,
            ]);
            $trx->items()->create(['barang_id' => $b->id, 'qty' => 1, 'harga_jual' => 60000, 'subtotal' => 60000]);
        }

        $svc = app(ExportPosService::class);
        $hasil = $svc->buildFormatBPdf(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));

        $this->assertTrue($hasil['ok']);
        $this->assertCount(2, $hasil['files']); // 100 + 60
        foreach ($hasil['files'] as $f) {
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($svc->pathFile($f)));
        }
        $this->assertDatabaseHas('export_batch', [
            'format' => 'B-PDF', 'jumlah_file' => 2,
        ]);
        $this->assertSame(2, count($hasil['export_batch']->file_names));
    }

    public function test_http_format_b_pdf_memakai_filter_yang_sama(): void
    {
        $this->authUser();
        $this->buatPenjualan();

        $this->post(route('export.format-b-pdf'), [
            'periode_mulai' => now()->startOfMonth()->format('Y-m-d'),
            'periode_selesai' => now()->endOfMonth()->format('Y-m-d'),
        ])->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('export_batch', ['format' => 'B-PDF']);
    }

    public function test_tandai_upload_manual(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        app(ExportPosService::class)->buildFormatA(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));
        $batch = ExportBatch::first();
        $this->post(route('export.tandai', $batch))->assertSessionHas('success');
        $this->assertTrue($batch->fresh()->status_upload_manual);
    }

    public function test_unduh_file_hanya_dari_export_batch_terdaftar(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        app(ExportPosService::class)->buildFormatA(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));
        $batch = ExportBatch::first();
        $this->get(route('export.unduh', [$batch, 0]))->assertOk();
        $this->get(route('export.unduh', [$batch, 99]))->assertNotFound();
    }

    public function test_hapus_batch_export_menghapus_baris_dan_file(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        $svc = app(ExportPosService::class);
        $svc->buildFormatA(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));
        $batch = ExportBatch::first();
        $path = $svc->pathFile($batch->file_names[0]);
        $this->assertFileExists($path);
        // batch yang sudah ditandai "sudah diupload" tetap boleh dihapus
        $batch->update(['status_upload_manual' => true]);

        $this->delete(route('export.destroy', $batch))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('export_batch', ['id' => $batch->id]);
        $this->assertFileDoesNotExist($path);
    }

    public function test_halaman_export_memuat_tombol_hapus(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        app(ExportPosService::class)->buildFormatA(now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d'));

        $this->get(route('export.index'))
            ->assertOk()
            ->assertSee('Aksi')
            ->assertSee('Hapus riwayat');
    }

    public function test_export_rentang_tanggal_menang_atas_bulan(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        // regresi bug: bulan selalu terisi default ikut terkirim — rentang tanggal harus menang
        app(ExportPosService::class)->buildFormatA('2026-07-01', '2026-07-31', '2026-09');
        $batch = ExportBatch::latest('id')->first();

        $this->assertSame('2026-07-01', $batch->filter_mulai?->format('Y-m-d'));
        $this->assertSame('2026-07-31', $batch->filter_selesai?->format('Y-m-d'));
    }

    public function test_export_shortcut_bulan_masih_berfungsi(): void
    {
        $this->authUser();
        $this->buatPenjualan();
        app(ExportPosService::class)->buildFormatA(null, null, '2026-08');
        $batch = ExportBatch::latest('id')->first();

        $this->assertSame('2026-08-01', $batch->filter_mulai?->format('Y-m-d'));
        $this->assertSame('2026-08-31', $batch->filter_selesai?->format('Y-m-d'));
    }

    public function test_halaman_export_tanpa_default_bulan(): void
    {
        $this->authUser();

        $this->get(route('export.index'))
            ->assertOk()
            ->assertDontSee('name="bulan" value=', false)
            ->assertSee('data-export-form', false);
    }

    public function test_export_gagal_prevalidasi_menampilkan_modal_rincian(): void
    {
        // Error pre-validasi Export kini masuk modal persisten (session import_errors),
        // bukan error-bag 10 contoh yang hilang dalam 6 detik.
        $this->authUser();

        $mock = \Mockery::mock(ExportPosService::class);
        $mock->shouldReceive('buildFormatA')->andReturn([
            'ok' => false,
            'files' => [],
            'errors' => [
                'KODE_BARANG 1 tidak ada di referensi barang (transaksi T-1).',
                'Barang ber-SN S-1 wajib QTY 1 dengan NO_SN terisi (transaksi T-2).',
            ],
        ]);
        $this->app->instance(ExportPosService::class, $mock);

        $this->post(route('export.format-a'), [])
            ->assertStatus(302)
            ->assertSessionHas('import_errors', function (array $v) {
                return ($v['judul'] ?? '') === 'Export Format A gagal'
                    && count($v['errors'] ?? []) === 2;
            });

        $this->followingRedirects()->post(route('export.format-a'), [])
            ->assertOk()
            ->assertSee('import-errors-backdrop', false)
            ->assertSee('Export Format A gagal')
            ->assertSee('Pre-validasi gagal')
            ->assertSee('KODE_BARANG 1 tidak ada di referensi barang');
    }
}
