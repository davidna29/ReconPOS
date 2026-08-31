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
}
