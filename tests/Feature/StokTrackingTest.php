<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\KartuStok;
use App\Models\Supplier;
use App\Models\TransaksiPenjualan;
use App\Models\User;
use App\Services\PembelianService;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StokTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'st@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    public function test_halaman_tracking_stok_muat(): void
    {
        $this->authUser();

        $this->get(route('stok-tracking.index'))
            ->assertOk()
            ->assertSee('Tracking Stok')
            ->assertSee('filter_alt', false); // tombol filter
    }

    public function test_tracking_stok_tampilkan_batch_tersisa(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'T-1', 'nama' => 'Tracked', 'sn_atau_non_sn' => 'NON_SN']);

        // beli 10 item
        $inv = app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => now()->subDays(30)->format('Y-m-d H:i'),
            'jenis' => 'cash', 'bayar_atau_dp' => 500000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 10, 'diskon_item' => 0]],
        ]);
        // jual 3 item
        app(PenjualanService::class)->simpanManual(
            now()->subDays(10),
            [['barang_id' => $b->id, 'qty' => 3, 'harga_jual' => 60000]],
        );

        $res = $this->get(route('stok-tracking.index'))
            ->assertOk();

        // batch harus ada di tabel dengan sisa = 7
        $res->assertSee('Tracked')
            ->assertSee('7', false) // qty sisa
            ->assertSee('10', false); // qty awal
    }

    public function test_tracking_stok_filter_bulan(): void
    {
        $this->authUser();
        $s = Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'T-2', 'nama' => 'Filtered', 'sn_atau_non_sn' => 'NON_SN']);

        // beli di bulan Juni
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => '2026-06-15 10:00:00',
            'jenis' => 'cash', 'bayar_atau_dp' => 250000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 5, 'diskon_item' => 0]],
        ]);

        // filter Juni -> ada data
        $this->get(route('stok-tracking.index', ['months' => ['2026-06']]))
            ->assertOk()
            ->assertSee('Filtered');

        // filter Desember -> kosong
        $this->get(route('stok-tracking.index', ['months' => ['2026-12']]))
            ->assertOk()
            ->assertDontSee('Filtered');
    }

    private function beli(string $kode, string $nama, string $tgl, int $qty = 5): void
    {
        $s = Supplier::first() ?? Supplier::create(['nama' => 'S', 'kontak' => '1']);
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => $kode, 'nama' => $nama, 'sn_atau_non_sn' => 'NON_SN']);
        app(PembelianService::class)->simpan([
            'supplier_id' => $s->id,
            'tanggal_transaksi' => $tgl,
            'jenis' => 'cash', 'bayar_atau_dp' => $qty * 50000,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => $qty, 'diskon_item' => 0]],
        ]);
    }

    public function test_default_hanya_dua_bulan_terbaru(): void
    {
        $this->authUser();
        $this->beli('M-4', 'Item-Apr', '2026-04-10 10:00:00');
        $this->beli('M-5', 'Item-Me5', '2026-05-10 10:00:00');
        $this->beli('M-6', 'Item-Jn6', '2026-06-10 10:00:00');

        $html = $this->get(route('stok-tracking.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Item-Apr', $html);
        $this->assertStringContainsString('Item-Me5', $html);
        $this->assertStringContainsString('Item-Jn6', $html);
    }

    public function test_tiga_bulan_dipangkas_dua_terbaru_dengan_banner(): void
    {
        $this->authUser();
        $this->beli('M-4', 'Item-Apr', '2026-04-10 10:00:00');
        $this->beli('M-5', 'Item-Me5', '2026-05-10 10:00:00');
        $this->beli('M-6', 'Item-Jn6', '2026-06-10 10:00:00');

        $html = $this->get(route('stok-tracking.index', ['months' => ['2026-04', '2026-05', '2026-06']]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('dibatasi maksimal 2 bulan', $html);
        $this->assertStringNotContainsString('Item-Apr', $html);
        $this->assertStringContainsString('Item-Jn6', $html);
    }

    public function test_popup_guard_ada_di_html(): void
    {
        $this->authUser();

        $html = $this->get(route('stok-tracking.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-month-guard', $html);
        $this->assertStringContainsString('dibatasi maksimal 2', $html);
    }

    public function test_paginasi_memotong_baris_dan_bawa_filter(): void
    {
        $this->authUser();
        for ($i = 1; $i <= 12; $i++) {
            $this->beli('P-' . $i, 'Pagi ' . $i, '2026-06-10 10:00:00', 2);
        }

        $html = $this->get(route('stok-tracking.index', ['months' => ['2026-06'], 'entries' => 10]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('dari 12 batch', $html);

        $hal2 = $this->get(route('stok-tracking.index', ['months' => ['2026-06'], 'entries' => 10, 'page' => 2]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('11–12 dari 12 batch', $hal2);
        $this->assertStringContainsString('months', $hal2); // link pagination bawa query
    }

    public function test_export_xlsx_angka_sama_layar(): void
    {
        $this->authUser();
        $this->beli('X-1', 'Ekspor', '2026-06-10 10:00:00', 7);

        $this->get(route('stok-tracking.export.xlsx', ['months' => ['2026-06']]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $files = glob(storage_path('app/private/export/stok-tracking-*.xlsx')) ?: [];
        $this->assertNotEmpty($files);
        $path = collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
        try {
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $this->assertSame(['Ringkasan', 'Rincian'], $book->getSheetNames());
            $teks = json_encode($book->getSheetByName('Rincian')->toArray(null, true, false));
            $this->assertStringContainsString('Ekspor', $teks);
            $this->assertStringContainsString('X-1', $teks);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_pdf_header_dan_isi(): void
    {
        $this->authUser();
        $this->beli('X-2', 'EksporPdf', '2026-06-10 10:00:00', 4);

        $res = $this->get(route('stok-tracking.export.pdf', ['months' => ['2026-06']]));
        $res->assertOk();
        $this->assertStringStartsWith('application/pdf', $res->headers->get('content-type'));

        $files = glob(storage_path('app/private/export/stok-tracking-*.pdf')) ?: [];
        $this->assertNotEmpty($files);
        $path = collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
        try {
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_export_menolak_estimasi_raksasa(): void
    {
        $this->authUser();
        $b = Barang::create(['tipe_item' => 'barang', 'kode_barang' => 'BIG', 'nama' => 'Big', 'sn_atau_non_sn' => 'NON_SN', 'stock' => 1]);
        $rows = [];
        for ($i = 0; $i < 3001; $i++) {
            $rows[] = ['barang_id' => $b->id, 'tanggal' => '2026-06-10 10:00:00', 'jenis' => 'masuk_pembelian', 'qty' => 1];
        }
        KartuStok::insert($rows);

        $this->from(route('stok-tracking.index'))
            ->get(route('stok-tracking.export.xlsx', ['months' => ['2026-06']]))
            ->assertRedirect(route('stok-tracking.index'))
            ->assertSessionHas('error');
    }

    public function test_export_tanpa_login_ditolak(): void
    {
        $this->get(route('stok-tracking.export.xlsx'))->assertRedirect(route('login'));
        $this->get(route('stok-tracking.export.pdf'))->assertRedirect(route('login'));
    }
}
