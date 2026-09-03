<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\TokoSetting;
use App\Models\User;
use App\Services\HargaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HargaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // cache harga adalah static per proses — reset agar antar-test tidak bocor
        HargaService::resetCache();
    }

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

    public function test_harga_beli_beda_antar_batch_mode_otomatis(): void
    {
        TokoSetting::utama()->update(['margin_default' => 20]);
        $barang = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-BATCH', 'nama' => 'Batch',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis',
        ]);
        $harga = app(HargaService::class);

        $harga->catatPembelian($barang, 40000, Carbon::create(2026, 6, 5, 10, 0));
        $harga->catatPembelian($barang, 45000, Carbon::create(2026, 6, 20, 10, 0));

        $barang->refresh();
        // master: harga beli & harga jual mengikuti beli TERAKHIR (mode otomatis)
        $this->assertSame(45000, $barang->harga_beli_terakhir);
        $this->assertSame(54000, $barang->harga_jual);
        $this->assertSame(2, $barang->historiHarga()->count());

        // harga "berlaku per tanggal": transaksi di antara kedua batch memakai batch pertama
        $this->assertSame(48000, $harga->hargaJualEfektifPada($barang, Carbon::create(2026, 6, 10)));
        $this->assertSame(54000, $harga->hargaJualEfektifPada($barang, Carbon::create(2026, 6, 25)));
    }

    public function test_manual_terkunci_histori_memakai_harga_jual_terkunci(): void
    {
        TokoSetting::utama()->update(['margin_default' => 20]);
        $barang = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-LOCK', 'nama' => 'Lock',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'manual_terkunci', 'harga_jual' => 55000,
        ]);
        $harga = app(HargaService::class);
        $harga->catatPembelian($barang, 40000, now());

        $barang->refresh();
        $this->assertSame(40000, $barang->harga_beli_terakhir);
        $this->assertSame(55000, $barang->harga_jual); // master tidak berubah

        $hist = $barang->historiHarga()->first();
        $this->assertSame(55000, (int) $hist->harga_jual_efektif); // BUKAN 48000 (hasil margin)
        $this->assertSame(20, (int) $hist->margin_dipakai);        // margin tetap dicatat
        $this->assertSame(55000, $harga->hargaJualEfektifPada($barang, now()));
    }

    public function test_manual_terkunci_tanpa_harga_jual_fallback_margin(): void
    {
        TokoSetting::utama()->update(['margin_default' => 20]);
        $barang = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-LOCK0', 'nama' => 'Lock0',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'manual_terkunci',
        ]);
        $harga = app(HargaService::class);
        $harga->catatPembelian($barang, 40000, now());

        $this->assertSame(48000, (int) $barang->historiHarga()->first()->harga_jual_efektif);
        $this->assertSame(0, (int) $barang->fresh()->harga_jual); // master tetap 0 hingga diisi user
    }

    public function test_terapkan_margin_default_ke_semua_sku_otomatis(): void
    {
        $harga = app(HargaService::class);
        // SKU otomatis tanpa override — HARUS ikut margin baru
        $a = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-M1', 'nama' => 'Auto',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis',
            'harga_beli_terakhir' => 100000, 'harga_jual' => 120000, // dari margin 20%
        ]);
        // SKU otomatis dgn override — TIDAK boleh berubah
        $b = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-M2', 'nama' => 'Override',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis', 'margin_override' => 10,
            'harga_beli_terakhir' => 100000, 'harga_jual' => 110000,
        ]);
        // SKU manual_terkunci — TIDAK boleh berubah
        $c = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-M3', 'nama' => 'Manual',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'manual_terkunci',
            'harga_beli_terakhir' => 100000, 'harga_jual' => 999999,
        ]);

        $diupdate = $harga->terapkanMarginDefaultKeSemua(5);
        $this->assertSame(1, $diupdate);
        $this->assertSame(105000, (int) $a->fresh()->harga_jual);
        $this->assertSame(110000, (int) $b->fresh()->harga_jual);
        $this->assertSame(999999, (int) $c->fresh()->harga_jual);
    }

    public function test_web_terapkan_margin_default_ke_semua(): void
    {
        $this->actingAs(User::create(['name' => 'A', 'email' => 'm@a.id', 'password' => 'x']));
        TokoSetting::utama()->update(['margin_default' => 5]);
        Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-W1', 'nama' => 'Web',
            'sn_atau_non_sn' => 'NON_SN', 'mode_harga_jual' => 'otomatis',
            'harga_beli_terakhir' => 100000, 'harga_jual' => 160000,
        ]);

        $this->post(route('pengaturan.terapkan-margin'))
            ->assertSessionHas('success');
        $this->assertSame(105000, (int) Barang::where('kode_barang', 'B-W1')->first()->harga_jual);
    }
}
