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

    private function buatFaktur(string $no, int $total, int $sisa, string $status, ?string $cabang = null, string $jenis = 'hutang', ?string $tanggal = null, int $ppn = 0): InvoicePembelian
    {
        return InvoicePembelian::create([
            'no_invoice' => $no,
            'supplier_id' => $this->supplier()->id,
            'tanggal_transaksi' => $tanggal ?? now()->format('Y-m-d H:i'),
            'jenis' => $jenis,
            'subtotal' => $total,
            'total_setelah_diskon' => $total,
            'ppn' => $ppn,
            'bayar_atau_dp' => $total + $ppn - $sisa,
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

    public function test_tetapkan_menolak_invoice_cash(): void
    {
        $this->authUser();
        $cash = $this->buatFaktur('F-C', 100000, 0, 'lunas', null, 'cash');

        $this->post(route('beban-hutang.tetapkan'), ['cabang' => 'TERMINAL BAN 1', 'ids' => [$cash->id]])
            ->assertSessionHas('error');
        $this->assertNull($cash->fresh()->cabang);
    }

    public function test_tetapkan_bisa_mengosongkan_kembali(): void
    {
        $this->authUser();
        $a = $this->buatFaktur('F-K1', 100000, 100000, 'belum_lunas', 'TERMINAL BAN 1');
        $b = $this->buatFaktur('F-K2', 200000, 200000, 'belum_lunas', 'TERMINAL BAN 2');

        // per-baris: pilih opsi kosong → kembali NULL
        $this->post(route('beban-hutang.tetapkan'), ['cabang' => '', 'ids' => [$a->id]])
            ->assertSessionHas('success');
        $this->assertNull($a->fresh()->cabang);

        // bulk: cabang spasi (di-trim) → dikosongkan juga
        $this->post(route('beban-hutang.tetapkan'), ['cabang' => '  ', 'ids' => [$b->id]])
            ->assertSessionHas('success');
        $this->assertNull($b->fresh()->cabang);

        // keduanya kembali muncul di daftar belum berbeban
        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        $this->assertStringContainsString('F-K1', $html);
        $this->assertStringContainsString('F-K2', $html);
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

    public function test_daftar_tetapkan_paginasi_10_per_halaman(): void
    {
        $this->authUser();
        for ($i = 1; $i <= 12; $i++) {
            $this->buatFaktur(
                'F-PRE-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                100000, 100000, 'belum_lunas', null, 'hutang',
                now()->subDays(30 - $i)->format('Y-m-d H:i')
            );
        }

        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        // halaman 1: 10 terbaru + pager ke halaman 2
        $this->assertStringNotContainsString('aria-label="Pilih F-PRE-01"', $html);
        $this->assertStringNotContainsString('aria-label="Pilih F-PRE-02"', $html);
        $this->assertStringContainsString('aria-label="Pilih F-PRE-12"', $html);
        $this->assertStringContainsString('halaman_belum=2', $html);
        $this->assertStringContainsString('(12 faktur belum berbeban)', $html);
    }

    public function test_rincian_kartu_dibatasi_dan_bisa_dibuka_penuh(): void
    {
        $this->authUser();
        for ($i = 1; $i <= 12; $i++) {
            $this->buatFaktur(
                'F-RIN-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                100000, 100000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang',
                now()->subDays(30 - $i)->format('Y-m-d H:i')
            );
        }

        // default: 10 tertua + catatan "+2 lainnya" + link buka penuh
        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        $this->assertStringContainsString('+2 lainnya', $html);
        $this->assertStringContainsString('Tampilkan semua', $html);
        $this->assertStringContainsString('F-RIN-01', $html);
        $this->assertStringNotContainsString('F-RIN-12', $html);

        // mode penuh: semua tampil + link ringkas kembali
        $htmlPenuh = $this->get(route('beban-hutang.index', ['rinci_semua' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('F-RIN-01', $htmlPenuh);
        $this->assertStringContainsString('Ringkas kembali', $htmlPenuh);
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

    public function test_rekap_menampilkan_dibayar_per_cabang(): void
    {
        $this->authUser();
        $tb1 = $this->buatFaktur('F-D1', 1000000, 600000, 'belum_lunas', 'TERMINAL BAN 1');
        app(\App\Services\PembelianService::class)->pelunasan($tb1, 400000, now());
        $tb2 = $this->buatFaktur('F-D2', 500000, 500000, 'belum_lunas', 'TERMINAL BAN 2');

        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        // dibayar TB1 = DP 400rb + cicilan 400rb (uang muka ikut dihitung)
        $this->assertStringContainsString('Rp 800.000', $html);
        $this->assertStringContainsString('Rp 0', $html); // dibayar TB2 (belum ada pembayaran)
    }

    public function test_rekap_dibayar_tetap_dihitung_walau_cicilan_beda_periode(): void
    {
        // Makna kohort + kini: filter Juni memilih faktur Juni; cicilan Agustus
        // atas faktur tsb TETAP dihitung + faktur tampil Lunas bila sudah lunas.
        $this->authUser();
        $tb1 = $this->buatFaktur('F-P1', 1000000, 1000000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang', now()->startOfMonth()->format('Y-m-d H:i'));
        app(\App\Services\PembelianService::class)->pelunasan($tb1, 1000000, now()->subMonths(2));

        $html = $this->get(route('beban-hutang.index', ['bulan' => now()->format('Y-m')]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Rp 1.000.000', $html); // dibayar penuh
        $this->assertStringContainsString('Lunas', $html);
    }

    public function test_rekap_kohort_balance_hutang_sama_dengan_sisa_plus_dibayar(): void
    {
        $this->authUser();
        // murni (tanpa DP/bayar) + cicilan sebagian + lunas + ber-DP tanpa cicilan
        $this->buatFaktur('F-B1', 1000000, 1110000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang', null, 110000);
        $cicil = $this->buatFaktur('F-B2', 2000000, 2220000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang', null, 220000);
        app(\App\Services\PembelianService::class)->pelunasan($cicil, 500000, now());
        $lunas = $this->buatFaktur('F-B3', 500000, 555000, 'belum_lunas', 'TERMINAL BAN 2', 'hutang', null, 55000);
        app(\App\Services\PembelianService::class)->pelunasan($lunas, 555000, now());
        $this->buatFaktur('F-B4', 1000000, 900000, 'belum_lunas', 'TERMINAL BAN 2', 'hutang', null, 110000); // DP 200rb

        $response = $this->get(route('beban-hutang.index'))->assertOk();
        $html = $response->getContent();

        // kartu TB1: hutang 3.330.000 = sisa 2.830.000 + dibayar 500.000
        $this->assertStringContainsString('Rp 3.330.000', $html);
        $this->assertStringContainsString('Rp 2.830.000', $html);
        $this->assertStringContainsString('Rp 500.000', $html);
        // kartu TB2: hutang 1.665.000 = sisa 900.000 + dibayar 765.000 (DP 210rb + lunas 555rb)
        $this->assertStringContainsString('Rp 1.665.000', $html);
        $this->assertStringContainsString('Rp 900.000', $html);
        $this->assertStringContainsString('Rp 765.000', $html);
    }

    public function test_invarian_sisa_sama_dengan_total_ppn_dp_bayar(): void
    {
        // Guard regresi insiden 2026-10-02 ( curiga revert bulk merusak sisa ):
        // sisa HARUS selalu = total + ppn − dp − Σ pembayaran, apa pun alurnya.
        $this->authUser();
        $svc = app(\App\Services\PembelianService::class);
        $murni = $this->buatFaktur('F-INV-1', 1000000, 1110000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang', null, 110000);
        $cicil = $this->buatFaktur('F-INV-2', 2000000, 2220000, 'belum_lunas', 'TERMINAL BAN 2', 'hutang', null, 220000);
        $svc->pelunasan($cicil, 500000, now());
        $lunas = $this->buatFaktur('F-INV-3', 500000, 555000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang', null, 55000);
        $svc->pelunasan($lunas, 555000, now());

        // bulk bayar lalu batalkan (skenario user) → sisa harus kembali persis
        $bulk = $svc->bayarBulk($murni->supplier_id, 300000, now(), null, null, 'TERMINAL BAN 1');
        $svc->batalkanBulk($bulk['grup']);

        $salah = \App\Models\InvoicePembelian::where('jenis', 'hutang')->get()->filter(
            fn ($inv) => (int) $inv->sisa_hutang !== (int) $inv->total_setelah_diskon + (int) $inv->ppn - (int) $inv->bayar_atau_dp - (int) $inv->pembayaranHutang()->sum('nominal')
        );
        $this->assertCount(0, $salah, 'Sisa menyimpang: ' . $salah->pluck('no_invoice')->implode(', '));

        // definisi tampilan rekap: hutang (incl PPN) selalu ≥ sisa
        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('(111%)', $html);
    }
}
