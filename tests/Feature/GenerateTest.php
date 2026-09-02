<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\BatchGenerate;
use App\Models\Supplier;
use App\Models\TokoSetting;
use App\Models\TransaksiPenjualan;
use App\Models\User;
use App\Services\GenerateService;
use App\Services\PembelianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GenerateTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'g@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    /** Setup: bulan sekarang dengan stok masuk beberapa hari (non-SN + 1 SN). */
    private function setupStok(Carbon $bulan): array
    {
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $a = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'G-A', 'nama' => 'Alpha', 'sn_atau_non_sn' => 'NON_SN']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'G-B', 'nama' => 'Beta', 'sn_atau_non_sn' => 'NON_SN']);
        $sn = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'G-SN', 'nama' => 'SN Item', 'sn_atau_non_sn' => 'SN']);

        $svc = app(PembelianService::class);
        $hari1 = $bulan->copy()->setDay(2)->setTime(9, 0);
        $svc->simpan([
            'supplier_id' => $s->id, 'tanggal_transaksi' => $hari1->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 600000,
            'items' => [
                ['barang_id' => $a->id, 'harga_beli' => 40000, 'qty' => 10, 'diskon_item' => 0],
                ['barang_id' => $b->id, 'harga_beli' => 20000, 'qty' => 10, 'diskon_item' => 0],
            ],
        ]);
        $hari8 = $bulan->copy()->setDay(8)->setTime(9, 0);
        $svc->simpan([
            'supplier_id' => $s->id, 'tanggal_transaksi' => $hari8->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 2000000,
            'items' => [
                ['barang_id' => $a->id, 'harga_beli' => 40000, 'qty' => 8, 'diskon_item' => 0],
                ['barang_id' => $sn->id, 'harga_beli' => 100000, 'qty' => 3, 'diskon_item' => 0, 'no_sn' => 'SN-A SN-B SN-C'],
            ],
        ]);

        return [$a, $b, $sn];
    }

    public function test_generate_draft_tanpa_efek_stok(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        [$a] = $this->setupStok($bulan);
        $stokSebelum = $a->fresh()->stock;

        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $this->assertSame('ok', $res['status']);
        $batch = $res['batch'];

        // draft: stok belum berubah, status draft
        $this->assertSame($stokSebelum, $a->fresh()->stock);
        $this->assertSame('draft', $batch->status);
        $this->assertDatabaseHas('transaksi_penjualan', ['batch_generate_id' => $batch->id, 'status' => 'draft']);
    }

    public function test_generate_tidak_pernah_stok_minus_dan_total_dalam_toleransi(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $sisaAwal = (int) TransaksiPenjualan::where('status', 'tersimpan')->sum('total');
        $target = 800000;

        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), $target, [], false);
        $this->assertSame('ok', $res['status']);
        $batch = $res['batch'];
        $svc->simpanDraft($batch->id); // berlaku: stok terpotong + SN ditandai

        // total existing + hasil generate ≈ target (±5%)
        $totalMenyeluruh = $sisaAwal + $batch->total_generate;
        $delta = abs($totalMenyeluruh - $target) / $target * 100;
        $this->assertLessThan(5.5, $delta, 'delta ' . round($delta, 2) . '% utk target ' . $target);

        // tidak ada stok minus
        foreach (Barang::all() as $b) {
            $this->assertGreaterThanOrEqual(0, $b->fresh()->stock);
        }

        // batch tersimpan + stok berkurang aktual
        $this->assertSame('tersimpan', $batch->fresh()->status);
        $this->assertLessThan(18, Barang::where('kode_barang', 'G-A')->first()->fresh()->stock);
    }

    public function test_generate_sn_qty_1_dan_unik(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $this->assertSame('ok', $res['status']);
        $batch = $res['batch'];

        $trxs = $batch->transaksiPenjualan()->with('items.barang')->get();
        $snTerpakai = [];
        foreach ($trxs as $t) {
            foreach ($t->items as $it) {
                if ($it->barang->isSn()) {
                    $this->assertSame(1, $it->qty, 'SN wajib qty 1');
                    $this->assertNotNull($it->no_sn);
                    $this->assertNotContains($it->no_sn, $snTerpakai, 'NO_SN tidak boleh dobel');
                    $snTerpakai[] = $it->no_sn;
                } else {
                    $this->assertNull($it->no_sn);
                }
            }
        }
    }

    public function test_generate_hormati_hari_libur_dan_jam_operasional(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        TokoSetting::utama()->update([
            'hari_libur' => ['dates' => [], 'weekly' => ['Sun']],
            'jam_buka' => '09:00', 'jam_tutup' => '16:00',
        ]);
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $batch = $res['batch'];
        foreach ($batch->transaksiPenjualan()->get() as $t) {
            $this->assertNotSame('Sun', $t->tanggal_waktu->format('D'));
            $jam = (int) $t->tanggal_waktu->format('H');
            $this->assertGreaterThanOrEqual(9, $jam);
            $this->assertLessThan(16, $jam);
        }
    }

    public function test_generate_kapasitas_kurang_memberi_warning(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        // target sangat besar → kapasitas kurang → status kapasitas_kurang
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 999999999, [], false);
        $this->assertSame('kapasitas_kurang', $res['status']);
        $this->assertArrayHasKey('kapasitas', $res);

        // force → generate semaksimal stok
        $res2 = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 999999999, [], true);
        $this->assertSame('ok', $res2['status']);
        foreach (Barang::all() as $b) {
            $this->assertGreaterThanOrEqual(0, $b->fresh()->stock);
        }
    }

    public function test_generate_ulang_menimpa_draft_sebelumnya(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $res1 = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $id1 = $res1['batch']->id;
        $res2 = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $id2 = $res2['batch']->id;
        $this->assertNotSame($id1, $id2);
        $this->assertDatabaseMissing('batch_generate', ['id' => $id1]); // draft lama dihapus
        $this->assertDatabaseHas('batch_generate', ['id' => $id2, 'status' => 'draft']);
    }

    public function test_web_form_generate_menuju_review(): void
    {
        $this->authUser();
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $this->post(route('generate.run'), [
            'periode_mulai' => $bulan->format('Y-m-d'),
            'periode_selesai' => $bulan->copy()->endOfMonth()->format('Y-m-d'),
            'target_omset' => 1000000,
            'nota_min' => 1, 'nota_max' => 5, 'toleransi_persen' => 2,
            'variasi_min' => -10, 'variasi_max' => 0,
        ])->assertRedirect(); // ke review
        $draft = BatchGenerate::where('status', 'draft')->first();
        $this->assertNotNull($draft);
        $this->get(route('generate.review', $draft))->assertOk();
    }

    public function test_web_generate_item_dikecualikan_tidak_muncul_di_draft(): void
    {
        $this->authUser();
        $bulan = Carbon::now()->startOfMonth();
        [$a, $b] = $this->setupStok($bulan);

        $this->post(route('generate.run'), [
            'periode_mulai' => $bulan->format('Y-m-d'),
            'periode_selesai' => $bulan->copy()->endOfMonth()->format('Y-m-d'),
            'target_omset' => 800000,
            'nota_min' => 1, 'nota_max' => 5, 'toleransi_persen' => 3,
            'variasi_min' => -10, 'variasi_max' => 0,
            'excluded_item_ids' => [(string) $b->id],
        ])->assertRedirect();

        $draft = BatchGenerate::where('status', 'draft')->first();
        $this->assertNotNull($draft);
        $this->get(route('generate.review', $draft))->assertOk();

        $idsTerpakai = [];
        foreach ($draft->transaksiPenjualan as $trx) {
            foreach ($trx->items as $it) {
                $idsTerpakai[] = $it->barang_id;
            }
        }
        $this->assertNotContains($b->id, $idsTerpakai, 'Barang dikecualikan tidak boleh muncul di draft');
        $this->assertNotEmpty($idsTerpakai, 'Draft tetap berisi barang lain');
    }

    public function test_undo_batch_tersimpan_reversibel(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        [$a] = $this->setupStok($bulan);
        $svc = app(GenerateService::class);

        $stokSebelumSimpan = $a->fresh()->stock;
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $batch = $res['batch'];
        $svc->simpanDraft($batch->id);

        $stokSesudahSimpan = $a->fresh()->stock;
        $this->assertLessThan($stokSebelumSimpan, $stokSesudahSimpan); // stok terpotong

        $snTerjual = \App\Models\ReferensiSn::where('status', 'terjual')->count();

        // UNDO
        $svc->batalBatchTersimpan($batch->id);

        $this->assertSame($stokSebelumSimpan, $a->fresh()->stock);                     // stok kembali
        $this->assertSame(0, (int) TransaksiPenjualan::where('status', 'tersimpan')->count()); // tidak ada rekonstruksi aktif
        $this->assertSame(
            \App\Models\ReferensiSn::count(),
            \App\Models\ReferensiSn::where('status', 'tersedia')->count(),           // semua SN kembali tersedia
        );
        $this->assertGreaterThanOrEqual($snTerjual, \App\Models\ReferensiSn::where('status', 'tersedia')->count());
        $this->assertSame('dibatalkan', $batch->fresh()->status);
        $this->assertSame(
            $batch->transaksiPenjualan()->count(),
            $batch->transaksiPenjualan()->where('status', 'dibatalkan')->count(),       // baris dipertahankan utk audit
        );
    }

    public function test_undo_ganda_ditolak(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $batch = $res['batch'];
        $svc->simpanDraft($batch->id);
        $svc->batalBatchTersimpan($batch->id);

        $this->expectException(\InvalidArgumentException::class);
        $svc->batalBatchTersimpan($batch->id); // kedua kalinya ditolak
    }

    public function test_regenerate_setelah_undo_tidak_bentrok_ref(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);

        $res1 = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $svc->simpanDraft($res1['batch']->id);
        $svc->batalBatchTersimpan($res1['batch']->id);

        // generate ulang periode sama: ref tidak boleh bentrok dgn ref 'dibatalkan' yang masih tersimpan
        $res2 = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $this->assertSame('ok', $res2['status']);
        $this->assertGreaterThan(0, $res2['batch']->transaksiPenjualan()->count());
    }

    public function test_riwayat_menampilkan_detail_nota(): void
    {
        $this->authUser();
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $svc->simpanDraft($res['batch']->id);

        $this->get(route('generate.riwayat'))
            ->assertOk()
            ->assertSee('Detail Nota')
            ->assertSee('GEN-');
    }

    public function test_index_penjualan_menyatu_manual_dan_rekonstruksi(): void
    {
        $this->authUser();
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);

        // rekonstruksi tersimpan
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $svc->simpanDraft($res['batch']->id);
        $refRekon = $res['batch']->transaksiPenjualan()->first()->ref_transaksi;

        // manual sale — stok masuk di tanggal 2 & 8, jadi pakai tanggal akhir bulan
        $tglManual = $bulan->copy()->endOfMonth()->subDays(2);
        $bDenganStok = Barang::where('stock', '>', 0)->orderByDesc('stock')->first();
        if (! $bDenganStok) {
            $this->markTestSkipped('Tidak ada stok tersisa utk penjualan manual di skenario ini.');
        }
        app(\App\Services\PenjualanService::class)->simpanManual($tglManual, [['barang_id' => $bDenganStok->id, 'qty' => 1, 'harga_jual' => $bDenganStok->harga_jual]]);
        $refManual = \App\Models\TransaksiPenjualan::where('sumber', 'manual')->first()->ref_transaksi;

        $this->get(route('penjualan.index'))
            ->assertOk()
            ->assertSee($refRekon)
            ->assertSee('Rekonstruksi')
            ->assertSee($refManual);

        // filter sumber=manual: rekonstruksi tidak tampil
        $this->get(route('penjualan.index', ['sumber' => 'manual']))
            ->assertOk()
            ->assertSee($refManual)
            ->assertDontSee($refRekon);
    }

    public function test_edit_rekonstruksi_mengunci_undo_batch(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $batch = $res['batch'];
        $svc->simpanDraft($batch->id);

        $trx = $batch->transaksiPenjualan()->first();
        $item = $trx->items()->first();

        // edit nota rekonstruksi (ubah harga item jadi +10.000)
        $edited = app(\App\Services\PenjualanService::class)->edit($trx, $trx->tanggal_waktu, [[
            'barang_id' => $item->barang_id,
            'qty' => $item->qty,
            'harga_jual' => $item->harga_jual + 10000,
        ]]);

        $this->assertNotNull($edited->diedit_at);
        $this->assertNotSame($item->subtotal, $edited->fresh()->items()->first()->subtotal);

        // batch total/selisih direkonsiliasi
        $batch->refresh();
        $this->assertSame(
            (int) $batch->transaksiPenjualan()->sum('total'),
            (int) $batch->total_generate
        );

        // undo sekarang TERKUNCI
        $this->expectException(\InvalidArgumentException::class);
        $svc->batalBatchTersimpan($batch->id);
    }

    public function test_generate_maks_qty_per_item_per_nota(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, ['maks_qty_item_per_nota' => 2], false);
        $this->assertSame('ok', $res['status']);
        $batch = $res['batch'];

        foreach ($batch->transaksiPenjualan()->with('items.barang')->get() as $trx) {
            $barangDalamNota = [];
            foreach ($trx->items as $it) {
                $this->assertLessThanOrEqual(2, $it->qty, 'Qty melebihi batas pada ' . $trx->ref_transaksi);
                $this->assertNotContains($it->barang_id, $barangDalamNota, 'Barang ganda dlm 1 nota: ' . $trx->ref_transaksi);
                $barangDalamNota[] = $it->barang_id;
            }
        }
    }

    public function test_generate_maks_1_semua_baris_qty_1(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 600000, ['maks_qty_item_per_nota' => 1], false);
        $this->assertSame('ok', $res['status']);
        $batch = $res['batch'];

        foreach ($batch->transaksiPenjualan()->with('items')->get() as $trx) {
            foreach ($trx->items as $it) {
                $this->assertSame(1, $it->qty);
            }
        }
    }

    public function test_edit_rekonstruksi_sn_tetap_qty_1(): void
    {
        $bulan = Carbon::now()->startOfMonth();
        $this->setupStok($bulan);
        $svc = app(GenerateService::class);
        $res = $svc->generate($bulan->copy(), $bulan->copy()->endOfMonth(), 800000, [], false);
        $batch = $res['batch'];
        $svc->simpanDraft($batch->id);

        $trx = $batch->transaksiPenjualan()->with('items.barang')->get()
            ->first(fn ($t) => $t->items->contains(fn ($i) => $i->barang->isSn()));
        if (! $trx) {
            $this->markTestSkipped('Tidak ada item SN pada draft — kombinasi acak. '. ''.$batch->id);
        }
        $item = $trx->items->first(fn ($i) => $i->barang->isSn());

        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\PenjualanService::class)->edit($trx, $trx->tanggal_waktu, [[
            'barang_id' => $item->barang_id,
            'qty' => 2, // SN wajib 1
            'harga_jual' => $item->harga_jual,
        ]]);
    }

    public function test_hapus_batch_draft_dari_riwayat(): void
    {
        $this->authUser();
        $batch = BatchGenerate::create([
            'periode_mulai' => now()->startOfMonth()->toDateString(),
            'periode_selesai' => now()->endOfMonth()->toDateString(),
            'target_omset' => 100000,
            'status' => 'draft',
        ]);

        $this->deleteJson(route('generate.hapus-batch', $batch))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('batch_generate', ['id' => $batch->id]);
    }

    public function test_hapus_batch_dibatalkan_dari_riwayat(): void
    {
        $this->authUser();
        $batch = BatchGenerate::create([
            'periode_mulai' => now()->startOfMonth()->toDateString(),
            'periode_selesai' => now()->endOfMonth()->toDateString(),
            'target_omset' => 100000,
            'status' => 'dibatalkan',
        ]);

        $this->deleteJson(route('generate.hapus-batch', $batch))
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('batch_generate', ['id' => $batch->id]);
    }

    public function test_hapus_batch_tersimpan_ditolak(): void
    {
        $this->authUser();
        $batch = BatchGenerate::create([
            'periode_mulai' => now()->startOfMonth()->toDateString(),
            'periode_selesai' => now()->endOfMonth()->toDateString(),
            'target_omset' => 100000,
            'status' => 'tersimpan',
        ]);

        $this->deleteJson(route('generate.hapus-batch', $batch))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_riwayat_batch_terurut_terbaru(): void
    {
        $this->authUser();
        $a = BatchGenerate::create([
            'periode_mulai' => now()->subMonth()->startOfMonth()->toDateString(),
            'periode_selesai' => now()->subMonth()->endOfMonth()->toDateString(),
            'target_omset' => 100000,
            'status' => 'draft',
        ]);
        $b = BatchGenerate::create([
            'periode_mulai' => now()->startOfMonth()->toDateString(),
            'periode_selesai' => now()->endOfMonth()->toDateString(),
            'target_omset' => 200000,
            'status' => 'draft',
        ]);

        $this->get(route('generate.riwayat'))
            ->assertOk()
            ->assertSeeInOrder(['#' . $b->id, '#' . $a->id]);
    }
}
