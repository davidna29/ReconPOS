<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\KartuStok;
use App\Models\Supplier;
use App\Models\TransaksiPenjualan;
use App\Models\User;
use App\Services\PembelianService;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StokTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'st@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    public function test_halaman_tracking_stok_muat(): void
    {
        $this->authUser();

        $this->get(route('stok-tracking.index'))
            ->assertOk()
            ->assertSee('Tracking Stok')
            ->assertSee('filter_alt', false); // tombol filter
    }

    public function test_tracking_stok_tampilkan_batch_tersisa(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'T-1', 'nama' => 'Tracked', 'sn_atau_non_sn' => 'NON_SN']);

        // beli 10 item
        $inv = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->subDays(30)->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 500000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 10, 'diskon_item' => 0]],
        ]);
        // jual 3 item
        app(PenjualanService::class)->simpanManual(
            now()->subDays(10),
            [['barang_id' => $b->id, 'qty' => 3, 'harga_jual' => 60000]],
        );

        $res = $this->get(route('stok-tracking.index'))
            ->assertOk();

        // batch harus ada di tabel dengan sisa = 7
        $res->assertSee('Tracked')
            ->assertSee('7', false) // qty sisa
            ->assertSee('10', false); // qty awal
    }

    public function test_tracking_stok_filter_bulan(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'T-2', 'nama' => 'Filtered', 'sn_atau_non_sn' => 'NON_SN']);

        // beli di bulan Juni
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => '2026-06-15 10:00:00',
            'jenis' => 'cash', 'bayar_atau_dp' => 250000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 5, 'diskon_item' => 0]],
        ]);

        // filter Juni -> ada data
        $this->get(route('stok-tracking.index', ['months' => ['2026-06']]))
            ->assertOk()
            ->assertSee('Filtered');

        // filter Desember -> kosong
        $this->get(route('stok-tracking.index', ['months' => ['2026-12']]))
            ->assertOk()
            ->assertDontSee('Filtered');
    }
}
