<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\ReferensiSn;
use App\Models\Supplier;
use App\Models\TokoSetting;
use App\Models\TransaksiPenjualan;
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

    private function beliDanJual(string $kode, int $qtyBeli, int $hargaBeli, int $qtyJual, int $hargaJual): Barang
    {
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => $kode, 'nama' => 'Item ' . $kode, 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => $hargaBeli * $qtyBeli,
            'items' => [['barang_id' => $b->id, 'harga_beli' => $hargaBeli, 'qty' => $qtyBeli, 'diskon_item' => 0]],
        ]);
        app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => $qtyJual, 'harga_jual' => $hargaJual]]);

        return $b;
    }

    public function test_edit_penjualan_manual_ubah_qty_stok_disesuaikan(): void
    {
        $this->authUser();
        $b = $this->beliDanJual('P-EDIT1', 10, 50000, 2, 60000);
        $trx = \App\Models\TransaksiPenjualan::where('sumber', 'manual')->first();
        $this->assertSame(8, $b->fresh()->stock);

        $this->get(route('penjualan.edit', $trx))->assertOk()->assertSee('Edit Nota');

        $this->put(route('penjualan.update', $trx), [
            'tanggal_waktu' => now()->format('Y-m-d H:i'),
            'items' => [['barang_id' => $b->id, 'qty' => 5, 'harga_jual' => 60000]],
        ])->assertSessionHas('success');

        $this->assertSame(5, $b->fresh()->stock);          // 10 - 5
        $this->assertSame(300000, $trx->fresh()->total);   // 5 × 60000
        $this->assertNotNull($trx->fresh()->diedit_at);
    }

    public function test_edit_penjualan_qty_melebihi_stok_ditolak(): void
    {
        $this->authUser();
        $b = $this->beliDanJual('P-EDIT2', 10, 50000, 2, 60000);
        $trx = \App\Models\TransaksiPenjualan::where('sumber', 'manual')->first();

        $this->put(route('penjualan.update', $trx), [
            'tanggal_waktu' => now()->format('Y-m-d H:i'),
            'items' => [['barang_id' => $b->id, 'qty' => 20, 'harga_jual' => 60000]],
        ])->assertSessionHas('error');

        $this->assertSame(8, $b->fresh()->stock);      // tidak berubah
        $this->assertSame(120000, $trx->fresh()->total);
    }

    public function test_cetak_struk_penjualan(): void
    {
        $this->authUser();
        $this->beliDanJual('P-STRUK', 10, 50000, 2, 60000);
        $trx = \App\Models\TransaksiPenjualan::where('sumber', 'manual')->first();
        \App\Models\TokoSetting::utama()->update(['nama_toko' => 'Bengkel Struk Test', 'npwp' => '01.234.567.8-901.000']);

        $this->get(route('penjualan.print', $trx))
            ->assertOk()
            ->assertSee('Bengkel Struk Test')
            ->assertSee('NPWP: 01.234.567.8-901.000')
            ->assertSee($trx->ref_transaksi)
            ->assertSee('Customer Umum')
            ->assertSee('Harga sudah termasuk PPN.');
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

    public function test_cetak_batch_penjualan_terpilih(): void
    {
        $this->authUser();
        $this->beliDanJual('P-BATCH1', 10, 50000, 2, 60000);
        $this->beliDanJual('P-BATCH2', 10, 50000, 3, 70000);
        $trxs = TransaksiPenjualan::where('sumber', 'manual')->orderBy('id')->get();
        $this->assertCount(2, $trxs);
        TokoSetting::utama()->update(['nama_toko' => 'Toko Batch Jual', 'npwp' => '12.345.678.9-012.000']);

        // id 999999 tidak ada → dibuang diam-diam; hanya 2 nota valid yang dicetak
        $res = $this->post(route('penjualan.print-batch'), ['ids' => [$trxs[0]->id, 999999, $trxs[1]->id]]);
        $res->assertOk()
            ->assertSee('Toko Batch Jual')
            ->assertSee('NPWP: 12.345.678.9-012.000')
            ->assertSee($trxs[0]->ref_transaksi)
            ->assertSee($trxs[1]->ref_transaksi)
            ->assertSee('pb-toggle')
            ->assertSee('bulk-controls', false)
            ->assertSee('Halaman baru per nota')
            ->assertSee('print-page { padding: 24px 0; }', false);
        $html = $res->getContent();
        $this->assertSame(2, substr_count($html, '<div class="print-page">'));
        $this->assertSame(2, substr_count($html, '*Harga sudah termasuk PPN.'));
        $this->assertSame(2, substr_count($html, 'Customer Umum (ID 0)'));
    }

    public function test_cetak_batch_penjualan_tanpa_id_valid_flash_error(): void
    {
        $this->authUser();
        $this->post(route('penjualan.print-batch'), ['ids' => [999999]])
            ->assertSessionHas('error');
    }

    public function test_cetak_batch_penjualan_wajib_ada_id(): void
    {
        $this->authUser();
        $this->post(route('penjualan.print-batch'), [])
            ->assertSessionHasErrors('ids');
    }

    public function test_halaman_penjualan_memuat_bulk_print(): void
    {
        $this->authUser();
        $this->beliDanJual('P-BULK', 10, 50000, 1, 60000);
        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('Cetak Terpilih')
            ->assertSee('bulk-check', false)
            ->assertSee('100 entri')
            ->assertSee('name="entries"', false);
    }
}
