<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\TokoSetting;
use App\Services\HargaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HargaTest extends TestCase
{
    use RefreshDatabase;

    public function test_margin_global_hitung_harga_jual(): void
    {
        TokoSetting::utama()->update(['margin_default' => 20]);
        $harga = app(HargaService::class);
        $this->assertSame(120000, $harga->hitungHargaJual(100000, 20));
        $this->assertSame(200000, $harga->hitungHargaJual(100000, 100));
    }

    public function test_margin_override_dipakai(): void
    {
        $settings = TokoSetting::utama();
        $settings->update(['margin_default' => 20]);
        $barang = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-001', 'nama' => 'Test',
            'sn_atau_non_sn' => 'NON_SN', 'margin_override' => 50,
        ]);
        $harga = app(HargaService::class);
        $this->assertSame(50, $harga->marginEfektif($barang));
    }

    public function test_toggle_mode_otomatis_hitung_ulang_seketika(): void
    {
        $settings = TokoSetting::utama();
        $settings->update(['margin_default' => 20]);
        $barang = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-002', 'nama' => 'Test2',
            'sn_atau_non_sn' => 'NON_SN', 'harga_beli_terakhir' => 50000,
            'mode_harga_jual' => 'manual_terkunci', 'harga_jual' => 99999,
        ]);
        app(HargaService::class)->toggleKeOtomatis($barang);
        $this->assertSame('otomatis', $barang->fresh()->mode_harga_jual);
        $this->assertSame(60000, $barang->fresh()->harga_jual);
    }

    public function test_catat_pembelian_update_histori_dan_harga_jual(): void
    {
        $settings = TokoSetting::utama();
        $settings->update(['margin_default' => 10]);
        $barang = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-003', 'nama' => 'Test3',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis',
        ]);
        app(HargaService::class)->catatPembelian($barang, 30000, now());
        $barang->refresh();
        $this->assertSame(30000, $barang->harga_beli_terakhir);
        $this->assertSame(33000, $barang->harga_jual);
        $this->assertSame(1, $barang->historiHarga()->count());
    }
}
