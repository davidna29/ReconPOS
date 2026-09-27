<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\ExportBatch;
use App\Models\Supplier;
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
