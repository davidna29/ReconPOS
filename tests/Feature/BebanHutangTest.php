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

    public function test_komposisi_mengikuti_filter_basis_nominal_tanpa_belum_dibebani(): void
    {
        $this->authUser();
        // nominal 50/30/20 → porsi 50/30/20
        $this->buatFaktur('F-K1', 500000, 500000, 'belum_lunas', 'TERMINAL BAN 1');
        $this->buatFaktur('F-K2', 300000, 300000, 'belum_lunas', 'TERMINAL BAN 2');
        $this->buatFaktur('F-K3', 200000, 200000, 'belum_lunas', 'TERMINAL BAN 3');
        $this->buatFaktur('F-K0', 999000000, 999000000, 'belum_lunas', null); // tak ikut 100%

        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Komposisi Beban', $html);
        $this->assertStringContainsString('50%', $html);
        $this->assertStringContainsString('30%', $html);
        $this->assertStringContainsString('20%', $html);

        // filter satu cabang → 100%, tanpa cabang lain
        $htmlSatu = $this->get(route('beban-hutang.index', ['cabang' => 'TERMINAL BAN 2']))->assertOk()->getContent();
        $this->assertStringContainsString('100%', $htmlSatu);
        $this->assertStringContainsString('Komposisi Beban', $htmlSatu);
        $this->assertStringNotContainsString('TERMINAL BAN 1</span>', $htmlSatu);
    }

    public function test_rincian_kartu_dibatasi_dan_bisa_dibuka_penuh(): void
    {
        $this->authUser();
        // Tanggal sebar dalam bulan BERJALAN (default filter) agar selalu masuk scope.
        $awalBulan = now()->copy()->startOfMonth();
        for ($i = 1; $i <= 12; $i++) {
            $this->buatFaktur(
                'F-RIN-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                100000, 100000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang',
                $awalBulan->copy()->addDays($i)->format('Y-m-d H:i')
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

    public function test_rekap_dibayar_memakai_snapshot_bukan_cabang_kini(): void
    {
        $this->authUser();
        $inv = $this->buatFaktur('F-SNAP', 1000000, 1000000, 'belum_lunas', 'TERMINAL BAN 1');
        app(\App\Services\PembelianService::class)->pelunasan($inv, 400000, now());
        // cabang faktur diubah sesudah dibayar → riwayat tetap milik TB1
        $inv->update(['cabang' => 'TERMINAL BAN 2']);

        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        // kartu TB1 (0 lembar) tetap menampilkan dibayar 400rb dari snapshot
        $this->assertStringContainsString('Rp 400.000', $html);
    }

    public function test_tabel_hutang_dan_lunas_tampil_kolom_serta_filter_cabang(): void
    {
        $this->authUser();
        $this->buatFaktur('F-KOL-1', 1000000, 1000000, 'belum_lunas', 'TERMINAL BAN 1');
        $this->buatFaktur('F-KOL-2', 500000, 0, 'lunas', 'TERMINAL BAN 2');

        $this->get(route('pembelian.invoice-hutang'))
            ->assertOk()
            ->assertSee('Cabang', false)
            ->assertSee('TERMINAL BAN 1');
        $this->get(route('pembelian.invoice-hutang', ['cabang' => 'TERMINAL BAN 2']))
            ->assertOk()
            ->assertDontSee('F-KOL-1', false);
        $this->get(route('pembelian.invoice-lunas'))
            ->assertOk()
            ->assertSee('Cabang', false)
            ->assertSee('TERMINAL BAN 2');
        $this->get(route('pembelian.invoice-lunas', ['cabang' => 'TERMINAL BAN 1']))
            ->assertOk()
            ->assertDontSee('F-KOL-2', false);
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

    public function test_export_xlsx_dua_sheet_angka_sama_layar(): void
    {
        $this->authUser();
        $this->seedRekap();
        $a = InvoicePembelian::where('no_invoice', 'F-TB1-1')->first();
        \App\Models\PembayaranHutang::create([
            'invoice_pembelian_id' => $a->id,
            'tanggal_bayar' => now()->format('Y-m-d'),
            'nominal' => 600000,
            'cabang' => 'TERMINAL BAN 1',
        ]);

        $res = $this->get(route('beban-hutang.export.xlsx', ['bulan' => now()->format('Y-m')]));
        $res->assertOk();
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $files = glob(storage_path('app/private/export/beban-hutang-*.xlsx')) ?: [];
        $this->assertNotEmpty($files);
        $path = collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
        try {
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $this->assertSame(['Ringkasan', 'Rincian'], $book->getSheetNames());

            $grand = null;
            foreach ($book->getSheetByName('Ringkasan')->toArray(null, true, false) as $row) {
                if (($row[0] ?? '') === 'GRAND TOTAL') {
                    $grand = (int) $row[2];
                }
            }
            // hutang incl PPN semua faktur hutang bulan ini (cash dikecualikan)
            $this->assertSame(1000000 + 500000 + 2000000 + 300000, $grand);

            $rinci = $book->getSheetByName('Rincian')->toArray(null, true, false);
            $teks = json_encode($rinci);
            $this->assertStringContainsString('F-TB1-1', $teks);
            $this->assertStringContainsString('F-TB2-1', $teks);
            $this->assertStringNotContainsString('F-CASH-1', $teks);
            // kolom Cicilan memuat pembayaran 600rb
            $cicilan = null;
            foreach ($rinci as $row) {
                if (($row[1] ?? '') === 'F-TB1-1') {
                    $cicilan = (int) $row[6];
                }
            }
            $this->assertSame(600000, $cicilan);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_pdf_header_dan_isi(): void
    {
        $this->authUser();
        $this->seedRekap();

        $res = $this->get(route('beban-hutang.export.pdf', ['bulan' => now()->format('Y-m')]));
        $res->assertOk();
        $this->assertStringStartsWith('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('beban-hutang-' . now()->format('Y-m') . '.pdf', $res->headers->get('content-disposition'));

        $files = glob(storage_path('app/private/export/beban-hutang-*.pdf')) ?: [];
        $this->assertNotEmpty($files);
        $path = collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
        try {
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_export_menolak_mode_tahun(): void
    {
        $this->authUser();

        $this->from(route('beban-hutang.index'))
            ->get(route('beban-hutang.export.xlsx', ['tahun' => now()->year]))
            ->assertRedirect(route('beban-hutang.index'))
            ->assertSessionHas('error');
    }

    public function test_export_menolak_rentang_lebih_31_hari(): void
    {
        $this->authUser();

        $this->from(route('beban-hutang.index'))
            ->get(route('beban-hutang.export.pdf', ['from' => '2026-01-01', 'to' => '2026-02-15']))
            ->assertRedirect(route('beban-hutang.index'))
            ->assertSessionHas('error', 'Export dibatasi 1 bulan — rentang Dari–Sampai maksimal 31 hari.');
    }

    public function test_export_menolak_tanpa_filter(): void
    {
        $this->authUser();

        $this->from(route('beban-hutang.index'))
            ->get(route('beban-hutang.export.xlsx'))
            ->assertRedirect(route('beban-hutang.index'))
            ->assertSessionHas('error');
    }

    public function test_export_tanpa_login_ditolak(): void
    {
        $this->get(route('beban-hutang.export.xlsx', ['bulan' => now()->format('Y-m')]))
            ->assertRedirect(route('login'));
        $this->get(route('beban-hutang.export.pdf', ['bulan' => now()->format('Y-m')]))
            ->assertRedirect(route('login'));
    }

    public function test_default_hanya_bulan_berjalan(): void
    {
        $this->authUser();
        $this->buatFaktur('F-LAMA-DF', 100000, 100000, 'belum_lunas', 'TERMINAL BAN 1', 'hutang', now()->subMonths(2)->format('Y-m-d H:i'));
        $this->buatFaktur('F-BARU-DF', 100000, 100000, 'belum_lunas', 'TERMINAL BAN 1');

        // default (tanpa filter) = bulan berjalan saja
        $this->get(route('beban-hutang.index'))
            ->assertOk()->assertSee('F-BARU-DF')->assertDontSee('F-LAMA-DF');

        // tahun eksplisit tetap tak terbatas
        $this->get(route('beban-hutang.index', ['tahun' => now()->year]))
            ->assertOk()->assertSee('F-BARU-DF')->assertSee('F-LAMA-DF');
    }

    public function test_rinci_semua_dibatasi_100_dengan_pesan(): void
    {
        $this->authUser();
        $sup = $this->supplier();
        $tgl = now()->format('Y-m-d H:i');
        for ($i = 1; $i <= 105; $i++) {
            InvoicePembelian::create([
                'no_invoice' => 'F-CAP-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'supplier_id' => $sup->id,
                'tanggal_transaksi' => $tgl,
                'jenis' => 'hutang',
                'subtotal' => 10000,
                'total_setelah_diskon' => 10000,
                'ppn' => 0,
                'bayar_atau_dp' => 0,
                'sisa_hutang' => 10000,
                'status' => 'belum_lunas',
                'cabang' => 'TERMINAL BAN 1',
            ]);
        }

        $html = $this->get(route('beban-hutang.index', ['rinci_semua' => 1]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Menampilkan 100 dari 105', $html);
        $this->assertStringContainsString('export 1 bulan', $html);
        $this->assertStringNotContainsString('F-CAP-105', $html);
    }

    public function test_rincian_mobile_kompak_dan_select_menyusut(): void
    {
        $this->authUser();
        $this->seedRekap();

        $html = $this->get(route('beban-hutang.index'))->assertOk()->getContent();
        $this->assertStringContainsString('tbl-hutang-kompak', $html);
        $this->assertStringContainsString('hutang-cabang-select', $html);
        // badge status inline mobile + desktop tak berubah
        $this->assertStringContainsString('Belum', $html);
    }

    public function test_export_menolak_di_atas_ambang(): void
    {
        $this->authUser();
        $this->seedRekap();

        $ctrl = new \App\Http\Controllers\BebanHutangController(app(\App\Services\ExcelImportService::class));
        $ref = new \ReflectionMethod($ctrl, 'jagaAmbang');
        $req = \Illuminate\Http\Request::create(
            route('beban-hutang.export.xlsx', ['bulan' => now()->format('Y-m')]),
            'GET',
            ['bulan' => now()->format('Y-m')]
        );

        $tolak = $ref->invoke(null, $req, 1, 'Excel');
        $this->assertNotNull($tolak);

        $lolos = $ref->invoke(null, $req, 2000, 'Excel');
        $this->assertNull($lolos);
    }

    public function test_index_jenis_punya_index_komposit(): void
    {
        $nama = collect(\Illuminate\Support\Facades\Schema::getIndexes('invoice_pembelian'))
            ->pluck('name')->all();
        $this->assertContains('inv_jenis_tanggal_idx', $nama);
        $this->assertContains('inv_jenis_cabang_idx', $nama);
    }

    public function test_export_xlsx_cantik_header_ungu(): void
    {
        $this->authUser();
        $this->seedRekap();

        $this->get(route('beban-hutang.export.xlsx', ['bulan' => now()->format('Y-m')]))->assertOk();

        $files = glob(storage_path('app/private/export/beban-hutang-*.xlsx')) ?: [];
        $path = collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
        try {
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $ringkas = $book->getSheetByName('Ringkasan');
            $this->assertSame('6D28D9', $ringkas->getStyle('A4')->getFill()->getStartColor()->getRGB());
            $this->assertArrayHasKey('A1:G1', $ringkas->getMergeCells());
        } finally {
            @unlink($path);
        }
    }

    public function test_blade_pdf_memuat_kop_garis_dan_halaman(): void
    {
        $this->authUser();
        $toko = \App\Models\TokoSetting::utama();

        $html = (string) $this->view('beban-hutang.export-pdf', [
            'toko' => $toko,
            'cards' => collect([[
                'cabang' => 'TERMINAL BAN 1', 'lembar' => 1, 'hutang' => 100000,
                'dibayar' => 0, 'sisa' => 100000, 'lunas' => 0, 'belum_lunas' => 1,
            ]]),
            'grand' => ['lembar' => 1, 'hutang' => 100000, 'dibayar' => 0, 'sisa' => 100000, 'lunas' => 0],
            'baris' => collect(),
            'periode' => '01 Jan 2026 – 31 Jan 2026',
        ]);

        $this->assertStringContainsString('kop-garis', $html);
        $this->assertStringContainsString('Halaman', $html);
        $this->assertStringContainsString('GRAND TOTAL', $html);
    }
}
