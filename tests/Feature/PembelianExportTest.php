<?php

namespace Tests\Feature;

use App\Models\InvoicePembelian;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PembelianExportTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'beliexport@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    private function faktur(string $no, string $jenis, string $status, int $total, int $sisa, ?string $cabang = null, ?int $supplierId = null, int $ppn = 0): InvoicePembelian
    {
        return InvoicePembelian::create([
            'no_invoice' => $no,
            'supplier_id' => $supplierId ?? Supplier::create(['nama' => 'PT Beli', 'kontak' => '081'])->id,
            'tanggal_transaksi' => now()->format('Y-m-d H:i'),
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

    private function seedTab(): void
    {
        $this->faktur('H-001', 'hutang', 'belum_lunas', 1000000, 600000, 'TERMINAL BAN 1');
        $this->faktur('H-002', 'hutang', 'belum_lunas', 500000, 500000, 'TERMINAL BAN 2');
        $this->faktur('L-001', 'hutang', 'lunas', 2000000, 0, 'TERMINAL BAN 1');
        $this->faktur('C-001', 'cash', 'lunas', 300000, 0);
    }

    private function fileTerbaru(string $pola): ?string
    {
        $files = glob(storage_path('app/private/export/' . $pola)) ?: [];

        return $files === [] ? null : collect($files)->sortByDesc(fn ($f) => filemtime($f))->first();
    }

    public function test_export_hutang_xlsx_hanya_tab_hutang(): void
    {
        $this->authUser();
        $this->seedTab();

        $this->get(route('pembelian.hutang.export.xlsx', ['bulan' => now()->format('Y-m')]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = $this->fileTerbaru('pembelian-hutang-*.xlsx');
        $this->assertNotNull($path);
        try {
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $this->assertSame(['Ringkasan', 'Rincian'], $book->getSheetNames());

            $jumlah = $total = null;
            foreach ($book->getSheetByName('Ringkasan')->toArray(null, true, false) as $row) {
                if (($row[0] ?? '') === 'Jumlah') {
                    $jumlah = (int) $row[1];
                }
                if (($row[0] ?? '') === 'Total') {
                    $total = (int) $row[1];
                }
            }
            $this->assertSame(2, $jumlah);
            $this->assertSame(1500000, $total);

            $teks = json_encode($book->getSheetByName('Rincian')->toArray(null, true, false));
            $this->assertStringContainsString('H-001', $teks);
            $this->assertStringContainsString('H-002', $teks);
            $this->assertStringNotContainsString('L-001', $teks);
            $this->assertStringNotContainsString('C-001', $teks);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_lunas_xlsx_hanya_tab_lunas(): void
    {
        $this->authUser();
        $this->seedTab();

        $this->get(route('pembelian.lunas.export.xlsx', ['bulan' => now()->format('Y-m')]))->assertOk();

        $path = $this->fileTerbaru('pembelian-lunas-*.xlsx');
        $this->assertNotNull($path);
        try {
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $teks = json_encode($book->getSheetByName('Rincian')->toArray(null, true, false));
            $this->assertStringContainsString('L-001', $teks);
            $this->assertStringContainsString('C-001', $teks);
            $this->assertStringNotContainsString('H-001', $teks);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_hutang_pdf_header_dan_isi(): void
    {
        $this->authUser();
        $this->seedTab();

        $res = $this->get(route('pembelian.hutang.export.pdf', ['bulan' => now()->format('Y-m')]));
        $res->assertOk();
        $this->assertStringStartsWith('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('pembelian-hutang-' . now()->format('Y-m') . '.pdf', $res->headers->get('content-disposition'));

        $path = $this->fileTerbaru('pembelian-hutang-*.pdf');
        $this->assertNotNull($path);
        try {
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_export_menghormati_filter_supplier(): void
    {
        $this->authUser();
        $lain = Supplier::create(['nama' => 'PT Lain', 'kontak' => '082']);
        $this->faktur('H-A', 'hutang', 'belum_lunas', 100000, 100000, null, $lain->id);
        $this->faktur('H-B', 'hutang', 'belum_lunas', 200000, 200000);

        $this->get(route('pembelian.hutang.export.xlsx', ['bulan' => now()->format('Y-m'), 'supplier' => $lain->id]))
            ->assertOk();

        $path = $this->fileTerbaru('pembelian-hutang-*.xlsx');
        $this->assertNotNull($path);
        try {
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $teks = json_encode($book->getSheetByName('Rincian')->toArray(null, true, false));
            $this->assertStringContainsString('H-A', $teks);
            $this->assertStringNotContainsString('H-B', $teks);
        } finally {
            @unlink($path);
        }
    }

    public function test_export_menolak_mode_tahun(): void
    {
        $this->authUser();

        $this->from(route('pembelian.invoice-hutang'))
            ->get(route('pembelian.hutang.export.xlsx', ['tahun' => now()->year]))
            ->assertRedirect(route('pembelian.invoice-hutang'))
            ->assertSessionHas('error');
    }

    public function test_export_menolak_rentang_lebih_31_hari(): void
    {
        $this->authUser();

        $this->from(route('pembelian.invoice-lunas'))
            ->get(route('pembelian.lunas.export.pdf', ['from' => '2026-01-01', 'to' => '2026-03-01']))
            ->assertRedirect(route('pembelian.invoice-lunas'))
            ->assertSessionHas('error', 'Export dibatasi 1 bulan — rentang Dari–Sampai maksimal 31 hari.');
    }

    public function test_export_menolak_tanpa_filter(): void
    {
        $this->authUser();

        $this->from(route('pembelian.invoice-hutang'))
            ->get(route('pembelian.hutang.export.xlsx'))
            ->assertRedirect(route('pembelian.invoice-hutang'))
            ->assertSessionHas('error');
    }

    public function test_export_tanpa_login_ditolak(): void
    {
        $this->get(route('pembelian.hutang.export.xlsx', ['bulan' => now()->format('Y-m')]))
            ->assertRedirect(route('login'));
        $this->get(route('pembelian.lunas.export.pdf', ['bulan' => now()->format('Y-m')]))
            ->assertRedirect(route('login'));
    }
}
