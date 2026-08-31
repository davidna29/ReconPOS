<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\ReferensiSn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PembelianService;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PenjualanTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'j@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    public function test_penjualan_manual_potong_stok_dan_customer_0(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'J-1', 'nama' => 'Jual', 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 125000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 25000, 'qty' => 5, 'diskon_item' => 0]],
        ]);
        $this->post(route('penjualan.store'), [
            'tanggal_waktu' => now()->format('Y-m-d H:i'),
            'items' => [['barang_id' => $b->id, 'qty' => 2, 'harga_jual' => 30000]],
        ])->assertSessionHas('success');
        $this->assertSame(3, $b->fresh()->stock);
        $this->assertDatabaseHas('transaksi_penjualan', ['customer_id' => 0, 'sumber' => 'manual', 'status' => 'tersimpan']);
    }

    public function test_penjualan_melebihi_stok_ditolak(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'J-2', 'nama' => 'Jual2', 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 50000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 25000, 'qty' => 2, 'diskon_item' => 0]], // 2x25000=50000 pas

        ]);
        $this->post(route('penjualan.store'), [
            'tanggal_waktu' => now()->format('Y-m-d H:i'),
            'items' => [['barang_id' => $b->id, 'qty' => 5, 'harga_jual' => 30000]],
        ])->assertSessionHas('error');
        $this->assertSame(2, $b->fresh()->stock);
    }

    public function test_barang_sn_wajib_qty_1_dan_pakai_no_sn(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'J-SN', 'nama' => 'JualSN', 'sn_atau_non_sn' => 'SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 500000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 100000, 'qty' => 2, 'diskon_item' => 0, 'no_sn' => 'SN-X1 SN-X2']],
        ]);
        // qty 2 utk barang SN ditolak
        $this->post(route('penjualan.store'), [
            'tanggal_waktu' => now()->format('Y-m-d H:i'),
            'items' => [['barang_id' => $b->id, 'qty' => 2, 'harga_jual' => 120000]],
        ])->assertSessionHas('error');
        // qty 1 memakai NO_SN tersedia
        $this->post(route('penjualan.store'), [
            'tanggal_waktu' => now()->format('Y-m-d H:i'),
            'items' => [['barang_id' => $b->id, 'qty' => 1, 'harga_jual' => 120000]],
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('referensi_sn', ['barang_id' => $b->id, 'status' => 'terjual']);
        $this->assertSame(1, ReferensiSn::where('barang_id', $b->id)->where('status', 'tersedia')->count());
    }
}
