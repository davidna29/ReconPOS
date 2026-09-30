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
        [$cards, $grand, $belumDibebani, $daftarCabang] = self::rekap($request);
        $suppliers = Supplier::orderBy('nama')->get();

        return view('beban-hutang.index', compact('cards', 'grand', 'belumDibebani', 'daftarCabang', 'suppliers'));
    }

    /**
     * Rekap beban hutang bersama utk admin & publik (lihat Publik\BebanHutangController).
     * Hanya invoice jenis=hutang. Filter periode memakai tanggal_transaksi.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: array, 2: \Illuminate\Contracts\Pagination\LengthAwarePaginator, 3: \Illuminate\Support\Collection}
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

        // Total dibayar per cabang dalam periode (tanggal_bayar ikut filter;
        // tanpa filter = sepanjang waktu). Sumber: baris pembayaran × cabang faktur.
        $bayarQuery = PembayaranHutang::query()
            ->join('invoice_pembelian', 'invoice_pembelian.id', '=', 'pembayaran_hutang.invoice_pembelian_id')
            ->where('invoice_pembelian.jenis', 'hutang');
        if ($awal && $akhir) {
            $bayarQuery->whereBetween('pembayaran_hutang.tanggal_bayar', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
        }
        if (($supplierId = (int) $request->get('supplier')) > 0) {
            $bayarQuery->where('invoice_pembelian.supplier_id', $supplierId);
        }
        $dibayarPerCabang = $bayarQuery
            ->selectRaw("COALESCE(NULLIF(invoice_pembelian.cabang, ''), 'Belum Dibebani') as cabang, SUM(pembayaran_hutang.nominal) as total")
            ->groupBy('cabang')
            ->pluck('total', 'cabang');

        // Urutan kartu: TB1 → TB2 → TB3 → abjad → 'Belum Dibebani' paling bawah.
        $urutan = array_flip(InvoicePembelian::CABANG_SARAN);
        $cards = $invoices->groupBy(fn ($inv) => $inv->labelCabang())
            ->map(fn ($items, $nama) => [
                'cabang' => $nama,
                'lembar' => $items->count(),
                'hutang' => (int) $items->sum('total_setelah_diskon'),
                'sisa' => (int) $items->sum('sisa_hutang'),
                'dibayar' => (int) ($dibayarPerCabang[$nama] ?? 0),
                'lunas' => $items->where('status', 'lunas')->count(),
                'belum_lunas' => $items->where('status', '!=', 'lunas')->count(),
                'items' => $items->values(),
            ])
            ->sortBy(fn ($c) => $c['cabang'] === 'Belum Dibebani' ? '~~~' : sprintf('%03d-%s', $urutan[$c['cabang']] ?? 999, $c['cabang']))
            ->values();

        $grand = [
            'lembar' => $invoices->count(),
            'hutang' => (int) $invoices->sum('total_setelah_diskon'),
            'sisa' => (int) $invoices->sum('sisa_hutang'),
            'dibayar' => (int) $dibayarPerCabang->sum(),
            'lunas' => $invoices->where('status', 'lunas')->count(),
        ];

        $belumDibebani = InvoicePembelian::with('supplier')
            ->where('jenis', 'hutang')
            ->whereNull('cabang')
            ->orderBy('tanggal_transaksi')
            ->paginate(25, ['*'], 'halaman_belum');

        $daftarCabang = InvoicePembelian::where('jenis', 'hutang')
            ->whereNotNull('cabang')->where('cabang', '<>', '')
            ->distinct()->orderBy('cabang')->pluck('cabang');

        return [$cards, $grand, $belumDibebani, $daftarCabang];
    }

    /** Tetapkan/ubah beban cabang utk 1..N faktur hutang (label saja — pembayaran tak tersentuh). */
    public function tetapkan(Request $request)
    {
        $data = $request->validate([
            'cabang' => ['required', 'string', 'max:50'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:invoice_pembelian,id'],
        ]);

        $cabang = normalisasi_teks($data['cabang']);
        if ($cabang === null) {
            return back()->with('error', 'Cabang wajib diisi.')->withInput();
        }

        $bukanHutang = InvoicePembelian::whereIn('id', $data['ids'])->where('jenis', '!=', 'hutang')->count();
        if ($bukanHutang > 0) {
            return back()->with('error', 'Hanya invoice hutang yg bisa dibebani.')->withInput();
        }

        $jumlah = 0;
        DB::transaction(function () use ($data, $cabang, &$jumlah) {
            $jumlah = InvoicePembelian::whereIn('id', $data['ids'])->update(['cabang' => $cabang]);
        });

        return back()->with('success', 'Beban "' . $cabang . '" ditetapkan ke ' . $jumlah . ' faktur.');
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
