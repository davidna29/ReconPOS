<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\Supplier;
use App\Models\TransaksiPenjualan;
use App\Models\User;
use App\Services\PembelianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mode Publik (tanpa login, read-only):
 * - Halaman publik terbuka tanpa autentikasi dan hanya berisi data (tanpa aksi mutasi).
 * - Menu tertutup (Supplier / Generate / Export / Pengaturan) -> 404.
 * - Route mutasi admin tetap butuh login.
 * - Halaman admin TIDAK berubah (regression guard untuk guard @if(! $publik)).
 */
class PublikTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'pub@a.id', 'password' => 'pass1234']));
    }

    private function barang(string $kode, string $nama = 'Oli Mesin'): Barang
    {
        return Barang::create([
            'tipe_item' => 'barang',
            'kode_barang' => $kode,
            'nama' => $nama,
            'sn_atau_non_sn' => 'NON_SN',
            'harga_jual' => 50000,
        ]);
    }

    private function transaksi(string $ref, string $sumber, Barang $b, int $qty = 1, int $harga = 60000): TransaksiPenjualan
    {
        $total = $qty * $harga;
        $trx = TransaksiPenjualan::create([
            'ref_transaksi' => $ref,
            'tanggal_waktu' => now()->subDay()->format('Y-m-d H:i:s'),
            'customer_id' => 0,
            'sumber' => $sumber,
            'status' => 'tersimpan',
            'total' => $total,
        ]);
        $trx->items()->create([
            'barang_id' => $b->id,
            'qty' => $qty,
            'harga_jual' => $harga,
            'subtotal' => $total,
        ]);

        return $trx;
    }

    private function beliHutang(Barang $b, int $qty = 4, int $hargaBeli = 25000): InvoicePembelian
    {
        $s = Supplier::create(['nama' => 'PT Suplai', 'kontak' => '08123']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->subDays(3)->format('Y-m-d H:i'),
            'jenis' => 'hutang',
            'bayar_atau_dp' => 50000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => $hargaBeli, 'qty' => $qty, 'diskon_item' => 0]],
        ]);

        return InvoicePembelian::where('status', 'belum_lunas')->firstOrFail();
    }

    // ----------------------------------------------------------------------
    // Halaman publik terbuka tanpa login & read-only
    // ----------------------------------------------------------------------

    public function test_publik_dashboard_terbuka_tanpa_login(): void
    {
        $this->barang('PB-1', 'Oli Mesin');

        $this->get('/publik')
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Mode Publik')
            ->assertSee('Login')
            ->assertSee('noindex, nofollow')
            ->assertDontSee('Aksi Cepat')
            ->assertDontSee('Order Sekarang')
            ->assertDontSee('Sumber Transaksi');
    }

    public function test_publik_sidebar_menu_menunjuk_route_publik(): void
    {
        // Guard bug: sidebar publik harus menunjuk route publik.* (bukan route admin yg redirect ke login).
        $html = $this->get(route('publik.dashboard'))->assertOk()->getContent();

        foreach (['publik.dashboard', 'publik.barang.index', 'publik.stok-tracking.index', 'publik.pembelian.invoice-hutang', 'publik.penjualan.index'] as $name) {
            $this->assertStringContainsString(route($name), $html, "Sidebar publik harus memuat link ke route {$name}");
        }

        // Sidebar publik TIDAK boleh memuat link ke route admin utk menu publik.
        $this->assertStringNotContainsString('href="' . route('barang.index') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('penjualan.index') . '"', $html);
    }

    public function test_publik_barang_index_readonly(): void
    {
        $b = $this->barang('PB-IDX', 'Busi NGK');

        $this->get(route('publik.barang.index'))
            ->assertOk()
            ->assertSee('Busi NGK')
            ->assertDontSee('Tambah Data')
            ->assertDontSee('Template Excel')
            ->assertDontSee('Update Harga Excel')
            ->assertDontSee('Upload Template Excel')
            ->assertDontSee('title="Edit"', false)
            ->assertDontSee('title="Hapus"', false)
            ->assertSee('title="Lihat Detail"', false);
    }

    public function test_publik_barang_show_readonly(): void
    {
        $b = $this->barang('PB-SHOW', 'Filter Oli');

        $this->get(route('publik.barang.show', $b))
            ->assertOk()
            ->assertSee('Filter Oli')
            ->assertSee('Buka Kartu Stok')
            ->assertSee('Cetak Label Barcode')
            ->assertDontSee('title="Edit"', false);
    }

    public function test_publik_barang_kartu_stok_tanpa_opname(): void
    {
        $b = $this->barang('PB-KS', 'Kampas Rem');

        $this->get(route('publik.barang.kartuStok', $b))
            ->assertOk()
            ->assertSee('Kartu Stok: Kampas Rem')
            ->assertDontSee('Stok Opname')
            ->assertDontSee('Stok Awal');
    }

    public function test_publik_stok_tracking_muat(): void
    {
        $b = $this->barang('PB-ST', 'Tracking A');
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->subDays(10)->format('Y-m-d H:i'),
            'jenis' => 'cash',
            'bayar_atau_dp' => 250000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 5, 'diskon_item' => 0]],
        ]);

        $this->get(route('publik.stok-tracking.index'))
            ->assertOk()
            ->assertSee('Total Stok Keseluruhan')
            ->assertSee('Tracking A');
    }

    public function test_publik_penjualan_hanya_daftar_tanpa_sumber_dan_edit(): void
    {
        $b = $this->barang('PB-PJ');
        $this->transaksi('PUB-MAN-1', 'manual', $b);
        $this->transaksi('PUB-REK-1', 'rekonstruksi', $b);

        $this->get(route('publik.penjualan.index'))
            ->assertOk()
            ->assertSee('PUB-MAN-1')
            ->assertSee('PUB-REK-1')
            ->assertSee('title="Detail"', false)
            ->assertSee('title="Cetak Struk"', false)
            ->assertDontSee('Manual')
            ->assertDontSee('Rekonstruksi')
            ->assertDontSee('diedit')
            ->assertDontSee('Total Omset')
            ->assertDontSee('Transaksi Penjualan Baru')
            ->assertDontSee('Generate Transaksi')
            ->assertDontSee('Hapus Terpilih')
            ->assertDontSee('Cetak Terpilih')
            ->assertDontSee('title="Edit nota"', false)
            ->assertDontSee('title="Hapus"', false);
    }

    public function test_publik_pembelian_hutang_readonly(): void
    {
        $inv = $this->beliHutang($this->barang('PB-HT'));

        $this->get(route('publik.pembelian.invoice-hutang'))
            ->assertOk()
            ->assertSee($inv->no_invoice)
            ->assertDontSee('Transaksi Pembelian')
            ->assertDontSee('Cetak Terpilih')
            ->assertDontSee('Hapus Terpilih')
            ->assertDontSee('title="Bayar/Lunasi"', false)
            ->assertDontSee('title="Edit"', false)
            ->assertDontSee('title="Hapus"', false)
            ->assertSee('title="Detail"', false);
    }

    public function test_publik_pembelian_lunas_readonly(): void
    {
        $b = $this->barang('PB-LN');
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->subDays(2)->format('Y-m-d H:i'),
            'jenis' => 'cash',
            'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 25000, 'qty' => 4, 'diskon_item' => 0]],
        ]);
        $inv = InvoicePembelian::where('status', 'lunas')->firstOrFail();

        $this->get(route('publik.pembelian.invoice-lunas'))
            ->assertOk()
            ->assertSee($inv->no_invoice)
            ->assertDontSee('Transaksi Pembelian')
            ->assertDontSee('title="Edit"', false)
            ->assertDontSee('title="Hapus"', false)
            ->assertDontSee('Cetak Terpilih');
    }

    public function test_publik_pembelian_show_readonly(): void
    {
        $inv = $this->beliHutang($this->barang('PB-SH'), 4, 25000);

        $this->get(route('publik.pembelian.show', $inv))
            ->assertOk()
            ->assertSee($inv->no_invoice)
            ->assertSee('Data barang Pembelian')
            ->assertDontSee('Bayar / Lunasi')
            ->assertDontSee('title="Edit"', false);
    }

    public function test_publik_print_struk_dan_invoice(): void
    {
        $b = $this->barang('PB-PR');
        $trx = $this->transaksi('PUB-PRINT-1', 'manual', $b);
        $inv = $this->beliHutang($this->barang('PB-PRI'), 4, 25000);

        $this->get(route('publik.penjualan.print', $trx))->assertOk()->assertSee('PUB-PRINT-1');
        $this->get(route('publik.pembelian.print', $inv))->assertOk()->assertSee($inv->no_invoice);
    }

    // ----------------------------------------------------------------------
    // Menu tertutup & keamanan
    // ----------------------------------------------------------------------

    public function test_publik_menu_tertutup_404(): void
    {
        foreach (['/publik/supplier', '/publik/generate', '/publik/export', '/publik/pengaturan'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_route_mutasi_admin_masih_butuh_login(): void
    {
        $this->get('/barang')->assertRedirect('/login');
        $this->get('/pengaturan')->assertRedirect('/login');
        $this->get('/generate')->assertRedirect('/login');
        $this->get('/export')->assertRedirect('/login');
        $this->post('/penjualan', [])->assertRedirect('/login');
        $this->post('/pembelian', [])->assertRedirect('/login');
    }

    // ----------------------------------------------------------------------
    // Regression: halaman admin tetap utuh
    // ----------------------------------------------------------------------

    public function test_admin_penjualan_masih_menampilkan_sumber_dan_aksi(): void
    {
        $this->admin();
        $b = $this->barang('PB-ADM');
        $this->transaksi('ADM-1', 'manual', $b);
        $this->transaksi('ADM-2', 'rekonstruksi', $b);

        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee('Manual')
            ->assertSee('Rekonstruksi')
            ->assertSee('Total Omset')
            ->assertSee('Transaksi Penjualan Baru')
            ->assertSee('title="Edit nota"', false)
            ->assertSee('Hapus Terpilih');
    }

    public function test_admin_dashboard_masih_lengkap(): void
    {
        $this->admin();
        $this->barang('PB-DA');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Aksi Cepat')
            ->assertSee('Order Sekarang')
            ->assertSee('Generate Transaksi')
            ->assertSee('Logout')
            ->assertSee('Sumber Transaksi');
    }

    public function test_admin_barang_index_masih_punya_aksi(): void
    {
        $this->admin();
        $this->barang('PB-BA');

        $this->get(route('barang.index'))
            ->assertOk()
            ->assertSee('Tambah Data')
            ->assertSee('Template Excel')
            ->assertSee('Upload Template Excel')
            ->assertSee('title="Edit"', false)
            ->assertSee('title="Hapus"', false);
    }
}
