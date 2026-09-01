<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PembelianService;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'd@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    /** Buat stok (pembelian) di tanggal tertentu lalu 1 penjualan manual. */
    private function buatPenjualan(Carbon $tanggalBeli, Carbon $tanggalJual, int $hargaBeli, int $qtyBeli, int $hargaJual, int $qtyJual, string $kode): void
    {
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => $kode, 'nama' => 'Item ' . $kode, 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => $tanggalBeli->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => $hargaBeli * $qtyBeli,
            'items' => [['barang_id' => $b->id, 'harga_beli' => $hargaBeli, 'qty' => $qtyBeli, 'diskon_item' => 0]],
        ]);
        app(PenjualanService::class)->simpanManual($tanggalJual, [['barang_id' => $b->id, 'qty' => $qtyJual, 'harga_jual' => $hargaJual]]);
    }

    public function test_dashboard_default_dan_tanpa_print(): void
    {
        $this->authUser();
        $this->buatPenjualan(Carbon::create(2026, 6, 1, 8, 0), Carbon::create(2026, 6, 15, 10, 0), 50000, 10, 60000, 2, 'D-1');
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Total Omset')
            ->assertDontSee('window.print');
    }

    public function test_dashboard_periode_bulan(): void
    {
        $this->authUser();
        $this->buatPenjualan(Carbon::create(2026, 6, 1, 8, 0), Carbon::create(2026, 6, 15, 10, 0), 50000, 10, 60000, 2, 'D-JUN');
        $this->buatPenjualan(Carbon::create(2026, 7, 1, 8, 0), Carbon::create(2026, 7, 10, 10, 0), 50000, 10, 100000, 1, 'D-JUL');

        $this->get(route('dashboard', ['bulan' => '2026-06']))
            ->assertOk()
            ->assertSee('Rp 120.000');
        $this->get(route('dashboard', ['bulan' => '2026-07']))
            ->assertOk()
            ->assertSee('Rp 100.000');
    }

    public function test_dashboard_periode_tahun_dan_rentang(): void
    {
        $this->authUser();
        $this->buatPenjualan(Carbon::create(2026, 6, 1, 8, 0), Carbon::create(2026, 6, 15, 10, 0), 50000, 10, 60000, 2, 'D-Y1');
        $this->buatPenjualan(Carbon::create(2026, 7, 1, 8, 0), Carbon::create(2026, 7, 10, 10, 0), 50000, 10, 100000, 1, 'D-Y2');

        $this->get(route('dashboard', ['tahun' => '2026']))
            ->assertOk()
            ->assertSee('Rp 220.000'); // 120.000 + 100.000
        $this->get(route('dashboard', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()
            ->assertSee('Rp 120.000');
        // rentang tidak valid (dari > sampai) -> fallback bulan berjalan, tidak error
        $this->get(route('dashboard', ['from' => '2026-07-01', 'to' => '2026-06-01']))
            ->assertOk();
    }

    public function test_dashboard_pisah_penjualan_dan_pembelian(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'PT. Pemasok', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'D-PISAH', 'nama' => 'Pisah', 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 100000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        app(PenjualanService::class)->simpanManual(now(), [['barang_id' => $b->id, 'qty' => 1, 'harga_jual' => 60000]]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Penjualan Terakhir')
            ->assertSee('Pembelian Terakhir');
    }

    public function test_dashboard_barang_terbaru(): void
    {
        $this->authUser();
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'D-B1', 'nama' => 'Baru Satu', 'sn_atau_non_sn' => 'NON_SN', 'stock' => 5]);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'D-B2', 'nama' => 'Baru Dua', 'sn_atau_non_sn' => 'NON_SN', 'stock' => 0]);
        Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'D-B3', 'nama' => 'Baru Tiga', 'sn_atau_non_sn' => 'NON_SN', 'stock' => 1]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Barang Terbaru')
            ->assertSee('D-B1')
            ->assertSee('D-B2')
            ->assertSee('D-B3');
    }

    public function test_sidebar_mobile_tanpa_blur_dan_zorder(): void
    {
        $this->authUser();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('backdrop-blur')
            ->assertSee('id="app-sidebar"', false)
            ->assertSee('z-40', false)                  // sidebar di atas overlay mobile
            ->assertSee('md:z-20', false)
            ->assertSee('sidebar-brand-text', false)
            ->assertSee('sidebar-brand', false)
            ->assertSee('nav-label', false);
    }

    public function test_sidebar_toggle_tanpa_md_hidden(): void
    {
        $this->authUser();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="sidebar-toggle"', false)
            ->assertDontSee('id="sidebar-toggle" class="rounded p-2 text-on-surface-variant transition-transform hover:bg-surface-container active:scale-95 md:hidden"', false);
    }
}
