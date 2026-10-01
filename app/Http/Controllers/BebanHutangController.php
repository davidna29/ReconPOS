<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportFileException;
use App\Models\InvoicePembelian;
use App\Models\PembayaranHutang;
use App\Models\Supplier;
use App\Services\ExcelImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BebanHutangController extends Controller
{
    public function __construct(private ExcelImportService $excel) {}

    public function index(Request $request)
    {
        [$cards, $grand, $belumDibebani, $belumDibebaniTotal, $daftarCabang, $rinciSemua] = self::rekap($request);
        $suppliers = Supplier::orderBy('nama')->get();

        return view('beban-hutang.index', compact('cards', 'grand', 'belumDibebani', 'belumDibebaniTotal', 'daftarCabang', 'suppliers', 'rinciSemua'));
    }

    /**
     * Rekap beban hutang bersama utk admin & publik (lihat Publik\BebanHutangController).
     * Hanya invoice jenis=hutang. Filter periode memakai tanggal_transaksi.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array, 2: \Illuminate\Contracts\Pagination\LengthAwarePaginator, 3: int, 4: \Illuminate\Support\Collection, 5: bool}
     */
    public static function rekap(Request $request): array
    {
        [$awal, $akhir] = self::rentangDariRequest($request);

        $query = InvoicePembelian::with('supplier')->where('jenis', 'hutang');
        if ($awal && $akhir) {
            $query->whereBetween('tanggal_transaksi', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
        }
        if (($cabang = trim((string) $request->get('cabang'))) !== '') {
            $query->where('cabang', $cabang);
        }
        if (($supplierId = (int) $request->get('supplier')) > 0) {
            $query->where('supplier_id', $supplierId);
        }
        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%"));
        }

        $invoices = $query->orderBy('tanggal_transaksi')->get();

        // Makna kohort + kini: filter memilih FAKTUR-nya (via tanggal_transaksi);
        // semua angka uang = kondisi terkini. Dibayar = uang muka + SELURUH cicilan
        // kapan pun (tanpa batas tanggal_bayar) sehingga Hutang = Sisa + Dibayar.
        // Dihitung dari himpunan faktur terfilter yg sama (bukan query terpisah).
        $cicilanPerCabang = PembayaranHutang::query()
            ->join('invoice_pembelian', 'invoice_pembelian.id', '=', 'pembayaran_hutang.invoice_pembelian_id')
            ->whereIn('pembayaran_hutang.invoice_pembelian_id', $invoices->pluck('id'))
            ->selectRaw("COALESCE(NULLIF(invoice_pembelian.cabang, ''), 'Belum Dibebani') as cabang, SUM(pembayaran_hutang.nominal) as total")
            ->groupBy('cabang')
            ->pluck('total', 'cabang');

        // Urutan kartu: TB1 → TB2 → TB3 → abjad → 'Belum Dibebani' paling bawah.
        // Hutang = total tagihan TERMASUK PPN (sebanding dgn sisa = hutang − terbayar).
        $tagihan = fn ($items) => (int) $items->sum('total_setelah_diskon') + (int) $items->sum('ppn');
        $urutan = array_flip(InvoicePembelian::CABANG_SARAN);
        $cards = $invoices->groupBy(fn ($inv) => $inv->labelCabang())
            ->map(function ($items, $nama) use ($tagihan, $urutan, $cicilanPerCabang) {
                $dibayar = (int) $items->sum('bayar_atau_dp') + (int) ($cicilanPerCabang[$nama] ?? 0);

                return [
                    'cabang' => $nama,
                    'lembar' => $items->count(),
                    'hutang' => $tagihan($items),
                    'sisa' => (int) $items->sum('sisa_hutang'),
                    'dibayar' => $dibayar,
                    'lunas' => $items->where('status', 'lunas')->count(),
                    'belum_lunas' => $items->where('status', '!=', 'lunas')->count(),
                    'items' => $items->values(),
                ];
            })
            ->sortBy(fn ($c) => $c['cabang'] === 'Belum Dibebani' ? '~~~' : sprintf('%03d-%s', $urutan[$c['cabang']] ?? 999, $c['cabang']))
            ->values();

        $grand = [
            'lembar' => $invoices->count(),
            'hutang' => $tagihan($invoices),
            'sisa' => (int) $invoices->sum('sisa_hutang'),
            'dibayar' => (int) $invoices->sum('bayar_atau_dp') + (int) $cicilanPerCabang->sum(),
            'lunas' => $invoices->where('status', 'lunas')->count(),
        ];

        // Daftar Tetapkan: paginasi 10/halaman (massal via template Excel).
        $belumDibebani = InvoicePembelian::with('supplier')
            ->where('jenis', 'hutang')
            ->whereNull('cabang')
            ->latest('tanggal_transaksi')
            ->paginate(10, ['*'], 'halaman_belum');
        $belumDibebaniTotal = (int) $belumDibebani->total();

        $daftarCabang = InvoicePembelian::where('jenis', 'hutang')
            ->whereNotNull('cabang')->where('cabang', '<>', '')
            ->distinct()->orderBy('cabang')->pluck('cabang');

        // Rincian tiap kartu dibatasi 10 baris kecuali ?rinci_semua=1.
        $rinciSemua = $request->boolean('rinci_semua');

        return [$cards, $grand, $belumDibebani, $belumDibebaniTotal, $daftarCabang, $rinciSemua];
    }

    /**
     * Tetapkan/ubah/kosongkan beban cabang utk 1..N faktur hutang
     * (label saja — pembayaran tak tersentuh). Cabang kosong = kembali Belum Dibebani.
     */
    public function tetapkan(Request $request)
    {
        $data = $request->validate([
            'cabang' => ['nullable', 'string', 'max:50'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:invoice_pembelian,id'],
        ]);

        $cabang = normalisasi_teks($data['cabang'] ?? null);

        $bukanHutang = InvoicePembelian::whereIn('id', $data['ids'])->where('jenis', '!=', 'hutang')->count();
        if ($bukanHutang > 0) {
            return back()->with('error', 'Hanya invoice hutang yg bisa dibebani.')->withInput();
        }

        $jumlah = 0;
        DB::transaction(function () use ($data, $cabang, &$jumlah) {
            $jumlah = InvoicePembelian::whereIn('id', $data['ids'])->update(['cabang' => $cabang]);
        });

        return $cabang === null
            ? back()->with('success', 'Beban ' . $jumlah . ' faktur dikosongkan (kembali Belum Dibebani).')
            : back()->with('success', 'Beban "' . $cabang . '" ditetapkan ke ' . $jumlah . ' faktur.');
    }

    public function downloadTemplate()
    {
        $path = $this->excel->templateBebanHutang();

        return response()->download($path, 'template-beban-hutang.xlsx');
    }

    public function importTemplate(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        try {
            $hasil = $this->excel->importBebanHutang($request->file('file')->getRealPath());

            return redirect()->route('beban-hutang.index')->with('success', 'Pemilahan selesai: ' . $hasil['diperbarui'] . ' faktur diberi beban cabang.');
        } catch (ImportFileException $e) {
            return back()->with('import_errors', [
                'judul' => 'Import beban hutang gagal divalidasi',
                'ringkasan' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Import excel beban hutang gagal di luar validasi', [
                'file' => $request->file('file')?->getClientOriginalName(),
                'ukuran' => $request->file('file')?->getSize(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $pesan = 'Terjadi kesalahan membaca file Excel: ' . $e->getMessage() . ' — detail lengkap ada di storage/logs/laravel.log.';

            return back()->with('import_errors', [
                'judul' => 'Gagal membaca file Excel',
                'ringkasan' => $pesan,
                'errors' => [$pesan],
            ]);
        }
    }

    /**
     * Rentang periode dari query string (prioritas: bulan > tahun > from/to),
     * pola sama dgn filter kartu omset dashboard & penjualan.
     *
     * @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon}
     */
    private static function rentangDariRequest(Request $request): array
    {
        if (($bulan = (string) $request->get('bulan')) !== '' && preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $awal = \Illuminate\Support\Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();

            return [$awal, $awal->copy()->endOfMonth()];
        }
        if (($tahun = (int) $request->get('tahun')) >= 2000 && $tahun <= 2099) {
            return [\Illuminate\Support\Carbon::create($tahun, 1, 1), \Illuminate\Support\Carbon::create($tahun, 12, 31)];
        }
        $from = (string) $request->get('from');
        $to = (string) $request->get('to');
        if ($from !== '' && $to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $a = \Illuminate\Support\Carbon::parse($from);
            $b = \Illuminate\Support\Carbon::parse($to);
            if ($a->lte($b)) {
                return [$a, $b];
            }
        }

        return [null, null];
    }
}
