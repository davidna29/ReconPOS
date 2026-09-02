<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\Supplier;
use App\Models\TokoSetting;
use App\Models\User;
use App\Services\PembelianService;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PembelianTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'p@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    private function barang(string $kode = 'B-1', string $sn = 'NON_SN'): Barang
    {
        return Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => $kode, 'nama' => 'Barang ' . $kode,
            'sn_atau_non_sn' => $sn,
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['nama' => 'PT. Test', 'kontak' => '0811']);
    }

    public function test_simpan_cash_tambah_stok_dan_histori(): void
    {
        $this->authUser();
        $b = $this->barang('B-CASH');
        $s = $this->supplier();
        $response = $this->post(route('pembelian.store'), [
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash',
            'diskon_total' => 0,
            'bayar_atau_dp' => 100000,
            'items' => [
                ['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0],
            ],
        ]);
        $response->assertSessionHas('success');
        $this->assertSame(2, $b->fresh()->stock);
        $this->assertSame(50000, $b->fresh()->harga_beli_terakhir);
        $this->assertSame(1, InvoicePembelian::count());
        $this->assertDatabaseHas('kartu_stok', ['barang_id' => $b->id, 'jenis' => 'masuk_pembelian', 'qty' => 2]);
    }

    public function test_cash_bayar_kurang_ditolak(): void
    {
        $this->authUser();
        $b = $this->barang('B-KURANG');
        $s = $this->supplier();
        $this->post(route('pembelian.store'), [
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash',
            'bayar_atau_dp' => 1,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ])->assertSessionHas('error');
        $this->assertSame(0, $b->fresh()->stock);
    }

    public function test_hutang_dp_sisa_dan_jatuh_tempo_otomatis(): void
    {
        $this->authUser();
        $b = $this->barang('B-HUT');
        $s = $this->supplier();
        $this->post(route('pembelian.store'), [
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'hutang',
            'bayar_atau_dp' => 20000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ])->assertSessionHas('success');
        $inv = InvoicePembelian::first();
        $this->assertSame(80000, $inv->sisa_hutang);
        $this->assertSame('belum_lunas', $inv->status);
        $this->assertNotNull($inv->jatuh_tempo);
        $this->assertEquals(
            now()->addMonths(3)->endOfMonth()->format('Y-m-d'),
            Carbon::parse($inv->jatuh_tempo)->format('Y-m-d')
        );
    }

    public function test_pelunasan_cicilan_pindah_ke_lunas(): void
    {
        $this->authUser();
        $b = $this->barang('B-LUNASI');
        $s = $this->supplier();
        $this->post(route('pembelian.store'), [
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'hutang',
            'bayar_atau_dp' => 0,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        $inv = InvoicePembelian::first();
        $this->post(route('pembelian.bayar', $inv), ['nominal' => 50000, 'tanggal_bayar' => now()->format('Y-m-d')]);
        $this->assertSame(50000, $inv->fresh()->sisa_hutang);
        $this->post(route('pembelian.bayar', $inv), ['nominal' => 50000, 'tanggal_bayar' => now()->format('Y-m-d')]);
        $this->assertSame(0, $inv->fresh()->sisa_hutang);
        $this->assertSame('lunas', $inv->fresh()->status);
    }

    public function test_hapus_invoice_dengan_pembayaran_diblokir(): void
    {
        $this->authUser();
        $b = $this->barang('B-BLOK');
        $s = $this->supplier();
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'hutang',
            'bayar_atau_dp' => 0,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        $inv = InvoicePembelian::first();
        app(PembelianService::class)->pelunasan($inv, 50000, now());
        $this->delete(route('pembelian.destroy', $inv))->assertSessionHas('error');
        $this->assertDatabaseHas('invoice_pembelian', ['id' => $inv->id]);
    }

    public function test_edit_qty_kurang_dari_terjual_ditolak(): void
    {
        $this->authUser();
        $b = $this->barang('B-EDIT');
        $s = $this->supplier();
        $svc = app(PembelianService::class);
        $inv = $svc->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash',
            'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        // jual 2 unit agar "terjual sejak invoice" = 2 → edit qty 1 ditolak
        app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 2, 'harga_jual' => 60000]]);
        $this->assertSame(0, $b->fresh()->stock);
        $this->put(route('pembelian.update', $inv), [
            'supplier_id' => $s->id,
            'tanggal_transaksi' => $inv->tanggal_transaksi->format('Y-m-d H:i'),
            'jenis' => 'cash',
            'bayar_atau_dp' => 50000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 1, 'diskon_item' => 0]],
        ])->assertSessionHas('error');
        // edit ditolak: stok tetap 0 & qty invoice masih 2 (tidak diubah)
        $this->assertSame(0, $b->fresh()->stock);
        $this->assertSame(2, $inv->fresh()->items()->first()->qty);
    }

    public function test_halaman_pembelian_memuat_nav_tabs(): void
    {
        $this->authUser();
        $b = $this->barang('B-TAB');
        $s = $this->supplier();
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);

        foreach ([route('pembelian.create'), route('pembelian.invoice-hutang'), route('pembelian.invoice-lunas')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Transaksi Pembelian')
                ->assertSee('Data Hutang Belum Lunas')
                ->assertSee('Data Pembelian (Lunas/Cash)');
        }
    }

    public function test_sidebar_tidak_memuat_submenu_pembelian(): void
    {
        $this->authUser();
        // keputusan produk: navigasi pembelian hanya via tab bar di dalam laman pembelian, bukan sidebar
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('pembelian.invoice-lunas'));
    }

    public function test_cetak_invoice_footer_ppn(): void
    {
        $this->authUser();
        $b = $this->barang('B-PPN');
        $s = $this->supplier();
        $inv = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        \App\Models\TokoSetting::utama()->update(['nama_toko' => 'Toko PPN', 'npwp' => '99.888.777.6-555.000']);

        $this->get(route('pembelian.print', $inv))
            ->assertOk()
            ->assertSee('Harga sudah termasuk PPN.')
            ->assertSee('NPWP: 99.888.777.6-555.000');
    }

    public function test_hapus_invoice_yang_membuat_stok_minus_warning(): void
    {
        $this->authUser();
        $b = $this->barang('B-MINUS');
        $s = $this->supplier();
        $svc = app(PembelianService::class);
        $inv = $svc->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash',
            'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 2, 'harga_jual' => 60000]]);
        // stok sekarang 0 — hapus invoice (qty 2) akan minus:
        $this->delete(route('pembelian.destroy', $inv))->assertSessionHas('stok_warning');
        // dengan force:
        $this->delete(route('pembelian.destroy', $inv) . '?force=1')->assertSessionHas('success');
        $this->assertDatabaseMissing('invoice_pembelian', ['id' => $inv->id]);
    }

    public function test_hapus_invoice_stok_minus_agregat_multibaris(): void
    {
        // SKU sama di 2 baris (6+6=12) — cek warning TIDAK boleh lolos diam-diam (bug agregasi).
        $this->authUser();
        $b = $this->barang('B-AGG');
        $s = $this->supplier();
        $inv = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 600000,
            'items' => [
                ['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 6, 'diskon_item' => 0],
                ['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 6, 'diskon_item' => 0],
            ],
        ]);
        // jual 2 dari 12 -> stok 10; hapus invoice (total 12) -> akan minus 2
        app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 2, 'harga_jual' => 60000]]);
        $this->assertSame(10, (int) $b->fresh()->stock);

        // tanpa force: HARUS warning (sebelumnya lolos diam-diam karena cek per-baris)
        $this->delete(route('pembelian.destroy', $inv))->assertSessionHas('stok_warning');
        $this->assertDatabaseHas('invoice_pembelian', ['id' => $inv->id]);
        $this->assertSame(10, (int) $b->fresh()->stock);

        // dengan force: invoice terhapus & stok = 10 - 12 = -2
        $this->delete(route('pembelian.destroy', $inv) . '?force=1')->assertSessionHas('success');
        $this->assertDatabaseMissing('invoice_pembelian', ['id' => $inv->id]);
        $this->assertSame(-2, (int) $b->fresh()->stock);
    }

    public function test_cetak_batch_pembelian_terpilih(): void
    {
        $this->authUser();
        $b = $this->barang('B-BATCH');
        $s = $this->supplier();
        $cash = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        $hutang = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'hutang', 'bayar_atau_dp' => 10000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        TokoSetting::utama()->update(['nama_toko' => 'Toko Batch Beli']);

        $res = $this->post(route('pembelian.print-batch'), ['ids' => [$cash->id, $hutang->id]]);
        $res->assertOk()
            ->assertSee($cash->no_invoice)
            ->assertSee($hutang->no_invoice)
            ->assertSee('pb-toggle')
            ->assertSee('bulk-controls', false)
            ->assertSee('Halaman baru per nota')
            ->assertSee('print-page { padding: 24px 0; }', false);
        $html = $res->getContent();
        $this->assertSame(2, substr_count($html, '<div class="print-page">'));
        $this->assertSame(2, substr_count($html, '*Harga sudah termasuk PPN.'));
        $this->assertSame(1, substr_count($html, '>CASH<'));
        $this->assertSame(1, substr_count($html, '>HUTANG<'));
    }

    public function test_cetak_batch_pembelian_tanpa_id_valid_flash_error(): void
    {
        $this->authUser();
        $this->post(route('pembelian.print-batch'), ['ids' => [999999]])
            ->assertSessionHas('error');
    }

    public function test_cetak_batch_pembelian_wajib_ada_id(): void
    {
        $this->authUser();
        $this->post(route('pembelian.print-batch'), [])
            ->assertSessionHasErrors('ids');
    }

    public function test_halaman_hutang_memuat_bulk_print(): void
    {
        $this->authUser();
        $b = $this->barang('B-HUT');
        $s = $this->supplier();
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'hutang', 'bayar_atau_dp' => 10000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        $this->get(route('pembelian.invoice-hutang'))
            ->assertOk()
            ->assertSee('Cetak Terpilih')
            ->assertSee('bulk-check', false)
            ->assertSee('100 entri')
            ->assertSee('name="entries"', false);
    }

    public function test_halaman_lunas_memuat_bulk_print(): void
    {
        $this->authUser();
        $b = $this->barang('B-LUN');
        $s = $this->supplier();
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        $this->get(route('pembelian.invoice-lunas'))
            ->assertOk()
            ->assertSee('Cetak Terpilih')
            ->assertSee('bulk-check', false)
            ->assertSee('100 entri')
            ->assertSee('name="entries"', false);
    }

    public function test_hapus_invoice_ajax(): void
    {
        $this->authUser();
        $b = $this->barang('B-AJAX');
        $s = $this->supplier();
        $inv = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);

        $this->deleteJson(route('pembelian.destroy', $inv))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('invoice_pembelian', ['id' => $inv->id]);
    }

    public function test_hapus_invoice_ajax_stok_warning(): void
    {
        $this->authUser();
        $b = $this->barang('B-AJW');
        $s = $this->supplier();
        $inv = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 2, 'harga_jual' => 60000]]);

        $this->deleteJson(route('pembelian.destroy', $inv))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'stok_warning' => true]);
        // Tanpa force: invoice & stok TIDAK berubah (warning hanya informasi)
        $this->assertDatabaseHas('invoice_pembelian', ['id' => $inv->id]);
        $this->assertSame(0, (int) $b->fresh()->stock);
    }

    public function test_hapus_invoice_ajax_stok_warning_force(): void
    {
        $this->authUser();
        $b = $this->barang('B-AJF');
        $s = $this->supplier();
        $inv = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 2, 'harga_jual' => 60000]]);

        // Dengan force (simulasi klik "Lanjutkan" di JS): invoice terhapus & stok jadi minus
        $this->deleteJson(route('pembelian.destroy', $inv), ['force' => true])
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('invoice_pembelian', ['id' => $inv->id]);
        $this->assertSame(-2, (int) $b->fresh()->stock);
    }

    public function test_bulk_hapus_invoice_ajax(): void
    {
        $this->authUser();
        $b = $this->barang('B-DEL');
        $s = $this->supplier();
        $a = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id, 'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        $b2 = $this->barang('B-DEL2');
        $c = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id, 'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 200000,
            'items' => [['barang_id' => $b2->id, 'harga_beli' => 50000, 'qty' => 4, 'diskon_item' => 0]],
        ]);

        $this->postJson(route('pembelian.bulk-delete'), ['ids' => [$a->id, $c->id]])
            ->assertOk()
            ->assertJsonPath('deleted', fn ($d) => count($d) === 2);

        $this->assertDatabaseMissing('invoice_pembelian', ['id' => $a->id]);
        $this->assertDatabaseMissing('invoice_pembelian', ['id' => $c->id]);
    }
}
