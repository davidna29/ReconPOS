<?php

namespace Tests\Feature;

use App\Models\InvoicePembelian;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BebanHutangTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'beban@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['nama' => 'PT Beban', 'kontak' => '081']);
    }

    private function buatFaktur(string $no, int $total, int $sisa, string $status, ?string $cabang = null, string $jenis = 'hutang', ?string $tanggal = null): InvoicePembelian
    {
        return InvoicePembelian::create([
            'no_invoice' => $no,
            'supplier_id' => $this->supplier()->id,
            'tanggal_transaksi' => $tanggal ?? now()->format('Y-m-d H:i'),
            'jenis' => $jenis,
            'subtotal' => $total,
            'total_setelah_diskon' => $total,
            'bayar_atau_dp' => $total - $sisa,
            'sisa_hutang' => $sisa,
            'status' => $status,
            'cabang' => $cabang,
        ]);
    }

    private function seedRekap(): void
    {
        $this->buatFaktur('F-TB1-1', 1000000, 400000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang', now()->format('Y-m-d H:i'));
        $this->buatFaktur('F-TB1-2', 500000, 0, 'lunas', 'TERMINAL BAN 1');
        $this->buatFaktur('F-TB2-1', 2000000, 2000000, 'belum_lunas', 'TERMINAL BAN 2');
        $this->buatFaktur('F-NONE-1', 300000, 300000, 'belum_lunas', null);
        $this->buatFaktur('F-CASH-1', 100000, 0, 'lunas', 'TERMINAL BAN 1', 'cash');
    }

    public function test_tetapkan_beban_bulk(): void
    {
        $this->authUser();
        $a = $this->buatFaktur('F-A', 100000, 100000, 'belum_lunas');
        $b = $this->buatFaktur('F-B', 200000, 50000, 'belum_lunas');

        $this->post(route('beban-hutang.tetapkan'), [
            'cabang' => 'TERMINAL BAN 2',
            'ids' => [$a->id, $b->id],
        ])->assertSessionHas('success');

        $this->assertSame('TERMINAL BAN 2', $a->fresh()->cabang);
        $this->assertSame('TERMINAL BAN 2', $b->fresh()->cabang);
        // sisa & status tak tersentuh (beban hanya label)
        $this->assertSame(100000, (int) $a->fresh()->sisa_hutang);
    }

    public function test_tetapkan_menolak_invoice_cash_dan_cabang_kosong(): void
    {
        $this->authUser();
        $cash = $this->buatFaktur('F-C', 100000, 0, 'lunas', null, 'cash');

        $this->post(route('beban-hutang.tetapkan'), ['cabang' => 'TERMINAL BAN 1', 'ids' => [$cash->id]])
            ->assertSessionHas('error');
        $this->assertNull($cash->fresh()->cabang);

        $hutang = $this->buatFaktur('F-H', 100000, 100000, 'belum_lunas');
        $this->post(route('beban-hutang.tetapkan'), ['cabang' => '  ', 'ids' => [$hutang->id]])
            ->assertSessionHasErrors('cabang'); // ditolak validasi required (spasi di-trim)
        $this->assertNull($hutang->fresh()->cabang);
    }

    public function test_rekap_angka_benar_dan_terfilter(): void
    {
        $this->authUser();
        $this->seedRekap();

        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        // TB1: 2 lembar (cash dikecualikan — hanya hutang), hutang 1,5jt, sisa 400rb, 1 lunas
        $this->assertStringContainsString('TERMINAL BAN 1', $html);
        $this->assertStringContainsString('TERMINAL BAN 2', $html);
        $this->assertStringContainsString('Belum Dibebani', $html);
        // grand: 4 lembar hutang (F-CASH-1 dikecualikan)
        $this->get(route('beban-hutang.index', ['cabang' => 'TERMINAL BAN 1']))
            ->assertOk()->assertSee('F-TB1-1')->assertSee('F-TB1-2')
            ->assertDontSee('F-TB2-1');
    }

    public function test_rekap_filter_bulan(): void
    {
        $this->authUser();
        $this->buatFaktur('F-LAMA', 100000, 100000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang', now()->subMonths(2)->format('Y-m-d H:i'));
        $this->buatFaktur('F-BARU', 100000, 100000, 'belum_lunas', 'TERMINAL BAN 1');

        $bulanIni = now()->format('Y-m');
        $this->get(route('beban-hutang.index', ['bulan' => $bulanIni]))
            ->assertOk()->assertSee('F-BARU')->assertDontSee('F-LAMA');
    }

    public function test_rekap_butuh_login_untuk_admin(): void
    {
        $this->get(route('beban-hutang.index'))->assertRedirect(route('login'));
        $this->post(route('beban-hutang.tetapkan'), [])->assertRedirect(route('login'));
    }

    public function test_rekap_publik_terbuka_tanpa_login_readonly(): void
    {
        $this->seedRekap();

        $this->get(route('publik.beban-hutang.index'))
            ->assertOk()
            ->assertSee('TERMINAL BAN 1')
            ->assertSee('F-TB1-1')
            ->assertSee('Mode Publik')
            ->assertDontSee('Tetapkan Beban')
            ->assertDontSee('Template Beban');
    }

    public function test_publik_tidak_punya_route_mutasi_beban(): void
    {
        $this->post('/publik/beban-hutang/tetapkan', [])->assertNotFound();
    }

    public function test_sidebar_memuat_link_beban_hutang(): void
    {
        $this->authUser();
        $this->get(route('dashboard'))->assertOk()->assertSee(route('beban-hutang.index'));

        $html = $this->get(route('publik.landing'))->assertOk()->getContent();
        $this->assertStringContainsString(route('publik.beban-hutang.index'), $html);
    }
}
