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

    public function test_hapus_penjualan_ajax(): void
    {
        $this->authUser();
        $this->beliDanJual('P-AJAX', 10, 50000, 2, 60000);
        $trx = TransaksiPenjualan::where('sumber', 'manual')->first();

        $this->deleteJson(route('penjualan.destroy', $trx))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('transaksi_penjualan', ['id' => $trx->id]);
    }

    public function test_hapus_penjualan_ajax_rekonstruksi_berhasil(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'P-REK', 'nama' => 'Rek', 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 500000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 10, 'diskon_item' => 0]],
        ]);
        $trx = app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 1, 'harga_jual' => 60000]]);
        $trx->update(['sumber' => 'rekonstruksi']);

        // rekonstruksi sekarang bisa dihapus langsung (guard dihapus)
        $this->deleteJson(route('penjualan.destroy', $trx))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_bulk_hapus_penjualan_ajax(): void
    {
        $this->authUser();
        $this->beliDanJual('P-B1', 10, 50000, 1, 60000);
        $this->beliDanJual('P-B2', 10, 50000, 1, 70000);
        $trxs = TransaksiPenjualan::where('sumber', 'manual')->pluck('id')->all();
        $this->assertCount(2, $trxs);

        $this->postJson(route('penjualan.bulk-delete'), ['ids' => $trxs])
            ->assertOk()
            ->assertJsonPath('deleted', fn ($d) => count($d) === 2);

        $this->assertDatabaseMissing('transaksi_penjualan', ['id' => $trxs[0]]);
        $this->assertDatabaseMissing('transaksi_penjualan', ['id' => $trxs[1]]);
    }

    public function test_bulk_hapus_penjualan_partial_error(): void
    {
        $this->authUser();
        $this->beliDanJual('P-P1', 10, 50000, 1, 60000);
        $trx = TransaksiPenjualan::where('sumber', 'manual')->first();

        $this->postJson(route('penjualan.bulk-delete'), ['ids' => [$trx->id, 99999]])
            ->assertOk()
            ->assertJsonPath('deleted', fn ($d) => count($d) === 1)
            ->assertJsonPath('errors', fn ($e) => isset($e['99999']));
    }

    public function test_halaman_penjualan_memuat_tombol_bulk_hapus(): void
    {
        $this->authUser();
        $this->beliDanJual('P-BH', 10, 50000, 1, 60000);
        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('Hapus Terpilih')
            ->assertSee('bulk-delete-btn', false)
            ->assertSee('data-delete-ajax', false);
    }

    public function test_penjualan_index_omset_periode_default(): void
    {
        $this->authUser();
        $this->beliDanJual('P-OM1', 10, 50000, 2, 60000); // total 120.000
        $this->beliDanJual('P-OM2', 10, 50000, 3, 70000); // total 210.000

        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('Total Omset')
            ->assertSee(rupiah(330000)) // 120.000 + 210.000
            ->assertSee('Bulan Berjalan')
            ->assertSee('filter_alt', false); // tombol filter
    }

    public function test_penjualan_index_filter_periode_bulan(): void
    {
        $this->authUser();
        // buat penjualan di bulan Juni
        $this->beliDanJual('P-FIL', 10, 50000, 1, 60000);
        $trx = TransaksiPenjualan::where('sumber', 'manual')->first();
        $trx->update(['tanggal_waktu' => '2026-06-15 10:00:00']);

        $this->get(route('penjualan.index', ['bulan' => '2026-06']))
            ->assertOk()
            ->assertSee('Jun 2026')
            ->assertSee(rupiah(60000));

        // Desember kosong (tidak ada transaksi)
        $this->get(route('penjualan.index', ['bulan' => '2026-12']))
            ->assertOk()
            ->assertSee('Des 2026')
            ->assertSee(rupiah(0));
    }

    public function test_penjualan_index_sisa_target(): void
    {
        $this->authUser();
        $this->beliDanJual('P-TGT', 10, 50000, 5, 60000); // total 300.000

        \App\Models\BatchGenerate::create([
            'periode_mulai' => now()->startOfMonth()->toDateString(),
            'periode_selesai' => now()->endOfMonth()->toDateString(),
            'target_omset' => 500000,
            'status' => 'tersimpan',
            'total_generate' => 300000,
        ]);

        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('Target: ' . rupiah(500000))
            ->assertSee('Sisa: ' . rupiah(200000));
    }

    public function test_hapus_rekonstruksi_sudah_diedit_berhasil(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'P-REK2', 'nama' => 'Rek2', 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id, 'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 500000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 10, 'diskon_item' => 0]],
        ]);
        $trx = app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 1, 'harga_jual' => 60000]]);
        $trx->update(['sumber' => 'rekonstruksi', 'diedit_at' => now()]);

        $this->deleteJson(route('penjualan.destroy', $trx))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('transaksi_penjualan', ['id' => $trx->id]);
    }

    public function test_tombol_hapus_muncul_untuk_rekonstruksi_diedit(): void
    {
        $this->authUser();
        $this->beliDanJual('P-RH', 10, 50000, 1, 60000);
        $trx = TransaksiPenjualan::where('sumber', 'manual')->first();
        $trx->update(['sumber' => 'rekonstruksi', 'diedit_at' => now()]);

        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('data-delete-ajax', false);
    }

    public function test_hapus_rekonstruksi_belum_diedit_berhasil(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'P-REK3', 'nama' => 'Rek3', 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id, 'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 500000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 10, 'diskon_item' => 0]],
        ]);
        $trx = app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 1, 'harga_jual' => 60000]]);
        $trx->update(['sumber' => 'rekonstruksi']); // belum diedit

        $this->deleteJson(route('penjualan.destroy', $trx))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('transaksi_penjualan', ['id' => $trx->id]);
    }

    public function test_tombol_hapus_untuk_semua_rekonstruksi(): void
    {
        $this->authUser();
        $this->beliDanJual('P-RA', 10, 50000, 1, 60000);
        $trx = TransaksiPenjualan::where('sumber', 'manual')->first();
        $trx->update(['sumber' => 'rekonstruksi']); // belum diedit, tetap ada tombol hapus

        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('data-delete-ajax', false);
    }
}
