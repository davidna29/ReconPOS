<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\PembayaranHutang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PembelianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BayarBulkTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'bulk@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    private function barang(string $kode = 'B-BULK'): Barang
    {
        return Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => $kode, 'nama' => 'Barang ' . $kode,
            'sn_atau_non_sn' => 'NON_SN',
        ]);
    }

    /** Invoice hutang 1jt (2 × 500rb), tanggal bisa diatur utk uji urutan FIFO. */
    private function hutang1jt(Supplier $s, Barang $b, string $tgl, string $no): InvoicePembelian
    {
        return app(PembelianService::class)->simpan([
            'no_invoice' => $no,
            'supplier_id' => $s->id, 'tanggal_transaksi' => $tgl,
            'jenis' => 'hutang', 'bayar_atau_dp' => 0, 'ppn' => 0,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 500000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
    }

    public function test_bayar_bulk_fifo_contoh_user(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. Trijaya', 'kontak' => '0811']);
        $b = $this->barang('B-FIFO');
        // Dibuat acak urutannya — alokasi harus tetap tertua dulu (berdasar tanggal)
        $inv2 = $this->hutang1jt($s, $b, '2026-06-02 10:00', 'INV-2');
        $inv3 = $this->hutang1jt($s, $b, '2026-06-03 10:00', 'INV-3');
        $inv1 = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-1');

        $hasil = app(PembelianService::class)->bayarBulk($s->id, 1500000, now(), 'transfer');

        $this->assertSame(3000000, $hasil['total_hutang']);
        $this->assertSame(1500000, $hasil['dialokasi']);
        $this->assertSame(0, $hasil['kembalian']);
        $this->assertCount(2, $hasil['rinci']);
        $this->assertSame('INV-1', $hasil['rinci'][0]['invoice']->no_invoice);
        $this->assertSame('INV-2', $hasil['rinci'][1]['invoice']->no_invoice);

        $this->assertSame(0, (int) $inv1->fresh()->sisa_hutang);
        $this->assertSame('lunas', $inv1->fresh()->status);
        $this->assertSame(500000, (int) $inv2->fresh()->sisa_hutang);
        $this->assertSame('belum_lunas', $inv2->fresh()->status);
        $this->assertSame(1000000, (int) $inv3->fresh()->sisa_hutang);

        // 2 baris pembayaran satu grup + catatan suffix bulk
        $this->assertSame(2, PembayaranHutang::where('grup_bulk', $hasil['grup'])->count());
        $this->assertStringContainsString('transfer', PembayaranHutang::first()->catatan);
        $this->assertStringContainsString('bulk', PembayaranHutang::first()->catatan);
    }

    public function test_bayar_bulk_kembalian_tidak_dicatat(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. Kembali', 'kontak' => '0812']);
        $b = $this->barang('B-KEM');
        $inv = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-K');

        $hasil = app(PembelianService::class)->bayarBulk($s->id, 5000000, now());

        $this->assertSame(1000000, $hasil['dialokasi']);
        $this->assertSame(4000000, $hasil['kembalian']);
        $this->assertSame('lunas', $inv->fresh()->status);
        $this->assertSame(1000000, (int) PembayaranHutang::sum('nominal')); // hanya yg dialokasi
    }

    public function test_bayar_bulk_tanpa_hutang_ditolak(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. Kosong', 'kontak' => '0813']);

        try {
            app(PembelianService::class)->bayarBulk($s->id, 100000, now());
            $this->fail('Harus ditolak bila supplier tak punya hutang.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak punya hutang', $e->getMessage());
        }
        $this->assertSame(0, PembayaranHutang::count());
    }

    public function test_bayar_bulk_hanya_supplier_bersangkutan(): void
    {
        $this->authUser();
        $a = Supplier::create(['nama' => 'PT. A', 'kontak' => '0814']);
        $b = Supplier::create(['nama' => 'PT. B', 'kontak' => '0815']);
        $br = $this->barang('B-ISO');
        $this->hutang1jt($a, $br, '2026-06-01 10:00', 'INV-A');
        $invB = $this->hutang1jt($b, $br, '2026-06-01 10:00', 'INV-B');

        app(PembelianService::class)->bayarBulk($a->id, 1000000, now());

        $this->assertSame(1000000, (int) $invB->fresh()->sisa_hutang);
        $this->assertSame('belum_lunas', $invB->fresh()->status);
    }

    public function test_simulasi_tidak_menulis_db(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. Sim', 'kontak' => '0816']);
        $b = $this->barang('B-SIM');
        $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-S');

        $hasil = app(PembelianService::class)->simulasiBulk($s->id, 400000);

        $this->assertSame(400000, $hasil['rinci'][0]['dibayar']);
        $this->assertSame(600000, $hasil['rinci'][0]['sisa_baru']);
        $this->assertFalse($hasil['rinci'][0]['lunas_baru']);
        $this->assertSame(0, PembayaranHutang::count());
        $this->assertSame(1000000, (int) InvoicePembelian::first()->sisa_hutang);
    }

    public function test_hapus_pembayaran_satuan_revert(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. Rev', 'kontak' => '0817']);
        $b = $this->barang('B-REV');
        $inv = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-R');

        $svc = app(PembelianService::class);
        $svc->pelunasan($inv, 1000000, now(), 'lunasin');
        $this->assertSame('lunas', $inv->fresh()->status);

        // Salah input → hapus → lunas kembali terbuka
        $inv2 = $svc->hapusPembayaran(PembayaranHutang::firstOrFail());
        $this->assertSame(1000000, (int) $inv2->sisa_hutang);
        $this->assertSame('belum_lunas', $inv2->status);
        $this->assertSame(0, PembayaranHutang::count());
    }

    public function test_batalkan_bulk_revert_sekaligus(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. Batal', 'kontak' => '0818']);
        $b = $this->barang('B-BATAL');
        $inv1 = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-B1');
        $inv2 = $this->hutang1jt($s, $b, '2026-06-02 10:00', 'INV-B2');

        $hasil = app(PembelianService::class)->bayarBulk($s->id, 1500000, now());
        $batal = app(PembelianService::class)->batalkanBulk($hasil['grup']);

        $this->assertSame(1500000, $batal['nominal_kembali']);
        $this->assertSame(2, $batal['baris']);
        $this->assertSame(1000000, (int) $inv1->fresh()->sisa_hutang);
        $this->assertSame(1000000, (int) $inv2->fresh()->sisa_hutang);
        $this->assertSame('belum_lunas', $inv1->fresh()->status);
        $this->assertSame(0, PembayaranHutang::count());
    }

    public function test_batalkan_bulk_grup_tak_dikenal_ditolak(): void
    {
        $this->authUser();

        try {
            app(PembelianService::class)->batalkanBulk('grup-tidak-ada');
            $this->fail('Harus ditolak utk grup tak dikenal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak ditemukan', $e->getMessage());
        }
    }

    public function test_http_preview_confirm_destroy_cancel(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. HTTP', 'kontak' => '0819']);
        $b = $this->barang('B-HTTP');
        $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-H1');
        $this->hutang1jt($s, $b, '2026-06-02 10:00', 'INV-H2');

        // Pratinjau: tampil alokasi, tanpa menulis
        $this->post(route('pembelian.bayar-bulk.preview'), [
            'supplier_id' => $s->id, 'nominal' => 1500000,
            'tanggal_bayar' => now()->format('Y-m-d'), 'catatan' => 'tes',
        ])->assertOk()->assertSee('INV-H1')->assertSee('Pratinjau');
        $this->assertSame(0, PembayaranHutang::count());

        // Konfirmasi: tercatat
        $this->post(route('pembelian.bayar-bulk.confirm'), [
            'supplier_id' => $s->id, 'nominal' => 1500000,
            'tanggal_bayar' => now()->format('Y-m-d'), 'catatan' => 'tes',
        ])->assertRedirect(route('pembelian.invoice-hutang'))
            ->assertSessionHas('success');
        $this->assertSame(2, PembayaranHutang::count());
        $grup = PembayaranHutang::first()->grup_bulk;
        $this->assertNotNull($grup);

        // Detail invoice memuat tombol revert
        $inv1 = InvoicePembelian::where('no_invoice', 'INV-H1')->first();
        $this->get(route('pembelian.show', $inv1))
            ->assertOk()->assertSee('Histori Pembayaran')->assertSee('Batalkan');

        // Batalkan bulk via HTTP
        $this->delete(route('pembelian.bayar-bulk.cancel', $grup))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, PembayaranHutang::count());
        $this->assertSame(1000000, (int) $inv1->fresh()->sisa_hutang);
    }

    public function test_toolbar_bayar_bulk_hanya_admin(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. TB', 'kontak' => '0821']);
        $b = $this->barang('B-TB');
        $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-TB');

        $this->get(route('pembelian.invoice-hutang'))
            ->assertOk()->assertSee('Bayar Bulk', false)->assertSee('bayar-bulk-row', false);

        // Mode publik: read-only, tanpa tombol bulk
        $this->get(route('publik.pembelian.invoice-hutang'))
            ->assertOk()->assertDontSee('Bayar Bulk');
    }

    public function test_http_hapus_pembayaran_satuan(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. HS', 'kontak' => '0820']);
        $b = $this->barang('B-HS');
        $inv = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-HS');
        app(PembelianService::class)->pelunasan($inv, 400000, now());

        $this->delete(route('pembelian.bayar.destroy', PembayaranHutang::first()))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1000000, (int) $inv->fresh()->sisa_hutang);
        $this->assertSame(0, PembayaranHutang::count());
    }
}
