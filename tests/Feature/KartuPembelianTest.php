<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PembelianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KartuPembelianTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'kartu@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    /** 2 invoice hutang beda supplier & beda bulan (berjalan vs lalu). */
    private function seedDua(): array
    {
        $a = Supplier::create(['nama' => 'PT. Kartu A', 'kontak' => '0811']);
        $b = Supplier::create(['nama' => 'PT. Kartu B', 'kontak' => '0812']);
        $brg = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-KARTU', 'nama' => 'Ban Kartu',
            'sn_atau_non_sn' => 'NON_SN',
        ]);
        $svc = app(PembelianService::class);
        $svc->simpan([
            'no_invoice' => 'INV-BULAN-INI',
            'supplier_id' => $a->id, 'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'hutang', 'bayar_atau_dp' => 0, 'ppn' => 11000,
            'items' => [['barang_id' => $brg->id, 'harga_beli' => 50000, 'qty' => 2, 'diskon_item' => 0]],
        ]);
        $svc->simpan([
            'no_invoice' => 'INV-BULAN-LALU',
            'supplier_id' => $b->id,
            'tanggal_transaksi' => now()->copy()->subMonthNoOverflow()->startOfMonth()->addDay()->format('Y-m-d H:i'),
            'jenis' => 'hutang', 'bayar_atau_dp' => 50000, 'ppn' => 0,
            'items' => [['barang_id' => $brg->id, 'harga_beli' => 100000, 'qty' => 2, 'diskon_item' => 0]],
        ]);

        return [$a, $b];
    }

    public function test_dashboard_kartu_mengikuti_filter_omset(): void
    {
        $this->authUser();
        $this->seedDua();

        // Default dashboard = bulan berjalan: hanya INV-BULAN-INI (111000 incl PPN)
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Total Belanja', false)
            ->assertSee('Rp 111.000')
            ->assertSee('1 invoice');

        // Filter bulan lalu → hanya INV-BULAN-LALU (200000, sisa 150000)
        $lalu = now()->copy()->subMonthNoOverflow()->format('Y-m');
        $this->get(route('dashboard', ['bulan' => $lalu]))
            ->assertOk()->assertSee('Rp 200.000')->assertSee('Rp 150.000');

        // Filter tahun berjalan → keduanya (311000, 2 invoice, sisa 261000)
        $this->get(route('dashboard', ['tahun' => now()->year]))
            ->assertOk()->assertSee('Rp 311.000')->assertSee('2 invoice')->assertSee('Rp 261.000');

        // Publik ikut
        $this->get(route('publik.dashboard'))
            ->assertOk()->assertSee('Total Belanja', false)->assertSee('Rp 111.000');
    }

    public function test_hutang_kartu_mengikuti_periode_dan_supplier(): void
    {
        $this->authUser();
        [$a, $b] = $this->seedDua();

        // Tanpa filter = semua waktu (konsisten dgn tabel): 311000, 2 invoice
        $this->get(route('pembelian.invoice-hutang'))
            ->assertOk()
            ->assertSee('Total Belanja', false)
            ->assertSee('Semua Waktu')
            ->assertSee('Rp 311.000')
            ->assertSee('2 invoice');

        // Filter bulan berjalan → hanya INV-BULAN-INI
        $this->get(route('pembelian.invoice-hutang', ['bulan' => now()->format('Y-m')]))
            ->assertOk()->assertSee('Rp 111.000')->assertSee('1 invoice');

        // Filter supplier B → hanya INV-BULAN-LALU + label supplier
        $html = $this->get(route('pembelian.invoice-hutang', ['supplier' => $b->id]))->getContent();
        $this->assertStringContainsString('Rp 200.000', $html);
        $this->assertStringContainsString('PT. Kartu B', $html);

        // Kombinasi periode + supplier A bulan ini
        $this->get(route('pembelian.invoice-hutang', ['bulan' => now()->format('Y-m'), 'supplier' => $a->id]))
            ->assertOk()->assertSee('Rp 111.000');

        // Publik ikut (kartu + label)
        $this->get(route('publik.pembelian.invoice-hutang'))
            ->assertOk()->assertSee('Total Belanja', false)->assertSee('Semua Waktu');
        $this->get(route('publik.pembelian.invoice-hutang', ['supplier' => $b->id]))
            ->assertOk()->assertSee('Rp 200.000');
    }

    public function test_lunas_menampilkan_kartu(): void
    {
        $this->authUser();
        $this->seedDua();

        // Kartu status-agnostik: tab lunas menampilkan total yg sama (311000, 2 invoice)
        $this->get(route('pembelian.invoice-lunas'))
            ->assertOk()->assertSee('Total Belanja', false)->assertSee('Rp 311.000')->assertSee('2 invoice');
        $this->get(route('publik.pembelian.invoice-lunas'))
            ->assertOk()->assertSee('Total Belanja', false);
    }

    public function test_filter_invalid_diabaikan(): void
    {
        $this->authUser();
        $this->seedDua();

        // Bulan tak valid → seperti tanpa filter (semua waktu di tab hutang)
        $this->get(route('pembelian.invoice-hutang', ['bulan' => 'ngawur']))
            ->assertOk()->assertSee('Semua Waktu')->assertSee('Rp 311.000');
    }
}
