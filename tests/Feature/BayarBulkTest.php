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

        // Konfirmasi: tercatat + kembali ke laman bulk
        $this->post(route('pembelian.bayar-bulk.confirm'), [
            'supplier_id' => $s->id, 'nominal' => 1500000,
            'tanggal_bayar' => now()->format('Y-m-d'), 'catatan' => 'tes',
        ])->assertRedirect(route('pembelian.bayar-bulk.index'))
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

    public function test_daftar_hutang_menampilkan_sudah_bayar(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. SB', 'kontak' => '0822']);
        $b = $this->barang('B-SB');
        // DP 200rb + 1 cicilan 300rb dari total 1jt → Sudah Bayar 500rb, sisa 500rb
        $inv = app(PembelianService::class)->simpan([
            'no_invoice' => 'INV-SB',
            'supplier_id' => $s->id, 'tanggal_transaksi' => '2026-06-01 10:00',
            'jenis' => 'hutang', 'bayar_atau_dp' => 200000, 'ppn' => 0,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 500000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        app(PembelianService::class)->pelunasan($inv, 300000, now());

        $html = $this->get(route('pembelian.invoice-hutang'))->getContent();
        $this->assertStringContainsString('Sudah Bayar', $html);
        $this->assertStringContainsString('500.000', $html); // 200rb DP + 300rb cicilan
        $this->assertStringContainsString('(1×)', $html);

        // Publik (view bersama) ikut tampil
        $this->get(route('publik.pembelian.invoice-hutang'))
            ->assertOk()->assertSee('Sudah Bayar', false);
    }

    public function test_toolbar_bayar_bulk_hanya_admin(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. TB', 'kontak' => '0821']);
        $b = $this->barang('B-TB');
        $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-TB');

        $this->get(route('pembelian.invoice-hutang'))
            ->assertOk()->assertSee('Bayar Bulk', false)
            ->assertSee(route('pembelian.bayar-bulk.index'), false);

        // Mode publik: read-only, tanpa tombol/tab bulk
        $this->get(route('publik.pembelian.invoice-hutang'))
            ->assertOk()->assertDontSee('Bayar Bulk');
    }

    public function test_laman_bulk_memuat_form_dan_riwayat(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. LB', 'kontak' => '0822']);
        $b = $this->barang('B-LB');
        $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-LB1');
        $this->hutang1jt($s, $b, '2026-06-02 10:00', 'INV-LB2');

        // Kosong: form + riwayat kosong
        $this->get(route('pembelian.bayar-bulk.index'))
            ->assertOk()
            ->assertSee('Pratinjau Alokasi', false)
            ->assertSee('Link Bukti Transfer', false)
            ->assertSee('Belum ada pembayaran bulk.');

        // Setelah bulk: riwayat tampil dgn bukti + hitungan invoice
        app(PembelianService::class)->bayarBulk($s->id, 1500000, now(), 'tes', 'https://drive.google.com/file/d/abc');

        $this->get(route('pembelian.bayar-bulk.index'))
            ->assertOk()
            ->assertSee('PT. LB')
            ->assertSee('1.500.000')
            ->assertSee('https://drive.google.com/file/d/abc', false)
            ->assertSee('2 invoice')
            ->assertSee('Batalkan', false);

        // Publik tidak punya laman bulk
        $this->get('/publik/pembelian/bayar-bulk')->assertNotFound();
    }

    public function test_bukti_url_opsional_tapi_harus_url_valid(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. BU', 'kontak' => '0823']);
        $b = $this->barang('B-BU');
        $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-BU');

        // Tanpa bukti: lolos, header bukti null
        $hasil = app(PembelianService::class)->bayarBulk($s->id, 500000, now());
        $this->assertNull($hasil['header']->fresh()->bukti_url);

        // URL ngawur via HTTP: ditolak validasi
        $this->post(route('pembelian.bayar-bulk.preview'), [
            'supplier_id' => $s->id, 'nominal' => 100000,
            'tanggal_bayar' => now()->format('Y-m-d'), 'bukti_url' => 'bukan-url',
        ])->assertSessionHasErrors('bukti_url');
        $this->post(route('pembelian.bayar-bulk.confirm'), [
            'supplier_id' => $s->id, 'nominal' => 100000,
            'tanggal_bayar' => now()->format('Y-m-d'), 'bukti_url' => 'bukan-url',
        ])->assertSessionHasErrors('bukti_url');
    }

    public function test_header_tercatat_dan_hilang_saat_dibatalkan(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. HH', 'kontak' => '0824']);
        $b = $this->barang('B-HH');
        $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-HH');

        $hasil = app(PembelianService::class)->bayarBulk($s->id, 2000000, now(), null, 'https://drive.google.com/x');
        $header = $hasil['header']->fresh();
        $this->assertSame(1000000, (int) $header->dialokasi);
        $this->assertSame(1000000, (int) $header->kembalian);
        $this->assertSame('https://drive.google.com/x', $header->bukti_url);
        $this->assertSame($hasil['grup'], $header->uuid);
        $this->assertSame(1, \App\Models\BulkBayarHutang::count());

        app(PembelianService::class)->batalkanBulk($hasil['grup']);
        $this->assertSame(0, \App\Models\BulkBayarHutang::count()); // hilang dari riwayat
    }

    public function test_hapus_satuan_menyinkronkan_header(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. SH', 'kontak' => '0825']);
        $b = $this->barang('B-SH');
        $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-SH1');
        $this->hutang1jt($s, $b, '2026-06-02 10:00', 'INV-SH2');

        $hasil = app(PembelianService::class)->bayarBulk($s->id, 1500000, now());
        $grup = $hasil['grup'];

        // Hapus 1 dari 2 baris → header dihitung ulang (500rb tersisa)
        $satu = PembayaranHutang::where('grup_bulk', $grup)->orderBy('id')->firstOrFail();
        app(PembelianService::class)->hapusPembayaran($satu);
        $header = \App\Models\BulkBayarHutang::where('uuid', $grup)->firstOrFail();
        $this->assertSame(500000, (int) $header->dialokasi);
        $this->assertSame(1000000, (int) $header->kembalian); // nominal 1,5jt − sisa 500rb

        // Hapus baris terakhir → header ikut hilang
        app(PembelianService::class)->hapusPembayaran(PembayaranHutang::where('grup_bulk', $grup)->firstOrFail());
        $this->assertSame(0, \App\Models\BulkBayarHutang::where('uuid', $grup)->count());
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

    public function test_bayar_bulk_scope_cabang_hanya_alokasi_cabang_itu(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. CB', 'kontak' => '0830']);
        $b = $this->barang('B-CB');
        $tb1 = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-CB1');
        $tb1->update(['cabang' => 'TERMINAL BAN 1']);
        $tb2 = $this->hutang1jt($s, $b, '2026-06-02 10:00', 'INV-CB2');
        $tb2->update(['cabang' => 'TERMINAL BAN 2']);

        $hasil = app(PembelianService::class)->bayarBulk($s->id, 1500000, now(), null, null, 'TERMINAL BAN 1');

        $this->assertSame(1000000, $hasil['total_hutang']); // hanya TB1
        $this->assertSame(1000000, $hasil['dialokasi']);
        $this->assertSame(500000, $hasil['kembalian']);
        $this->assertCount(1, $hasil['rinci']);
        $this->assertSame('TERMINAL BAN 1', $hasil['header']->cabang);
        // sisa TB2 utuh — uang TB1 tidak memotong cabang lain
        $this->assertSame(1000000, (int) $tb2->fresh()->sisa_hutang);
        $this->assertSame(0, (int) $tb1->fresh()->sisa_hutang);
    }

    public function test_bayar_bulk_mode_semua_mencakup_tanpa_cabang(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. CA', 'kontak' => '0831']);
        $b = $this->barang('B-CA');
        $tb1 = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-CA1');
        $tb1->update(['cabang' => 'TERMINAL BAN 1']);
        $nol = $this->hutang1jt($s, $b, '2026-06-02 10:00', 'INV-CA2'); // tanpa cabang

        $hasil = app(PembelianService::class)->bayarBulk($s->id, 2000000, now());

        $this->assertSame(2000000, $hasil['total_hutang']);
        $this->assertCount(2, $hasil['rinci']);
        $this->assertNull($hasil['header']->cabang);
        $this->assertSame(0, (int) $nol->fresh()->sisa_hutang);
    }

    public function test_simulasi_bulk_cabang_kosong_error_menyebut_cabang(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. CK', 'kontak' => '0832']);
        $b = $this->barang('B-CK');
        $inv = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-CK');
        $inv->update(['cabang' => 'TERMINAL BAN 2']);

        try {
            app(PembelianService::class)->simulasiBulk($s->id, 500000, 'TERMINAL BAN 1');
            $this->fail('Harus dilempar karena cabang TB1 tidak punya hutang.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('TERMINAL BAN 1', $e->getMessage());
        }
    }

    public function test_http_bayar_bulk_cabang_tersimpan_di_header_dan_tampil(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. CH', 'kontak' => '0833']);
        $b = $this->barang('B-CH');
        $inv = $this->hutang1jt($s, $b, '2026-06-01 10:00', 'INV-CH');
        $inv->update(['cabang' => 'TERMINAL BAN 3']);

        // pratinjau menampilkan scope cabang
        $this->post(route('pembelian.bayar-bulk.preview'), [
            'supplier_id' => $s->id, 'nominal' => 400000,
            'tanggal_bayar' => now()->format('Y-m-d'), 'cabang' => 'TERMINAL BAN 3',
        ])->assertOk()->assertSee('TERMINAL BAN 3');

        // konfirmasi menyimpan cabang di header
        $this->post(route('pembelian.bayar-bulk.confirm'), [
            'supplier_id' => $s->id, 'nominal' => 400000,
            'tanggal_bayar' => now()->format('Y-m-d'), 'cabang' => 'TERMINAL BAN 3',
        ])->assertRedirect(route('pembelian.bayar-bulk.index'))->assertSessionHas('success');
        $this->assertSame('TERMINAL BAN 3', \App\Models\BulkBayarHutang::first()->cabang);

        // riwayat menampilkan cabang; header lama (NULL) tampil '-'
        $this->get(route('pembelian.bayar-bulk.index'))->assertOk()->assertSee('TERMINAL BAN 3');
    }
}
