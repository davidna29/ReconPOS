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
}
