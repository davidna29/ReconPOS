<?php

namespace App\Http\Controllers\Publik;

use App\Models\InvoicePembelian;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Mode Publik (tanpa login) — Pembelian read-only: Daftar Hutang Belum Lunas,
 * Data Pembelian (Lunas/Cash), detail invoice, dan cetak invoice.
 */
class PembelianController extends Controller
{
    /** Daftar hutang belum lunas (read-only) — dengan filter periode + supplier. */
    public function hutang(Request $request)
    {
        $query = InvoicePembelian::with('supplier')
            ->withSum('pembayaranHutang as cicilan_sum', 'nominal')
            ->withCount('pembayaranHutang as cicilan_count')
            ->where('status', 'belum_lunas');

        [$awal, $akhir] = $this->periodeDariRequest($request);
        if ($awal && $akhir) {
            $query->whereBetween('tanggal_transaksi', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
        }
        if ($sid = (int) $request->query('supplier')) {
            $query->where('supplier_id', $sid);
        }

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('nama', 'like', "%{$s}%")));
        }

        $invoices = $query->latest('tanggal_transaksi')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();
        $suppliers = Supplier::orderBy('nama')->get(['id', 'nama']);
        [$labelBeli, $totalBeli, $sisaBeli, $countBeli] = $this->kartuPembelian($request, $awal, $akhir);

        return view('pembelian.invoice-hutang', compact('invoices', 'suppliers', 'labelBeli', 'totalBeli', 'sisaBeli', 'countBeli') + ['publik' => true]);
    }

    /** Daftar pembelian lunas/cash (read-only) — dengan filter periode + supplier. */
    public function lunas(Request $request)
    {
        $query = InvoicePembelian::with('supplier')
            ->where('status', 'lunas');

        [$awal, $akhir] = $this->periodeDariRequest($request);
        if ($awal && $akhir) {
            $query->whereBetween('tanggal_transaksi', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
        }
        if ($sid = (int) $request->query('supplier')) {
            $query->where('supplier_id', $sid);
        }

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('nama', 'like', "%{$s}%")));
        }

        $invoices = $query->latest('tanggal_transaksi')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();
        $suppliers = Supplier::orderBy('nama')->get(['id', 'nama']);
        [$labelBeli, $totalBeli, $sisaBeli, $countBeli] = $this->kartuPembelian($request, $awal, $akhir);

        return view('pembelian.invoice-lunas', compact('invoices', 'suppliers', 'labelBeli', 'totalBeli', 'sisaBeli', 'countBeli') + ['publik' => true]);
    }

    /**
     * Kartu Pembelian: mengikuti filter periode + supplier milik tabel
     * (tanpa search/pagination). Tanpa filter = semua waktu.
     * @param array{0: ?Carbon, 1: ?Carbon} $periode hasil periodeDariRequest
     * @return array{0: string, 1: int, 2: int, 3: int} [label, total, sisa, count]
     */
    private function kartuPembelian(Request $request, ?Carbon $awal, ?Carbon $akhir): array
    {
        $query = InvoicePembelian::query();
        $label = 'Semua Waktu';
        if ($awal && $akhir) {
            $query->whereBetween('tanggal_transaksi', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
            $label = $this->labelPeriodeBeli($request, $awal, $akhir);
        }
        if ($sid = (int) $request->query('supplier')) {
            $query->where('supplier_id', $sid);
            if ($nama = Supplier::whereKey($sid)->value('nama')) {
                $label .= ' · ' . $nama;
            }
        }

        return [
            $label,
            (int) (clone $query)->sum('total_setelah_diskon') + (int) (clone $query)->sum('ppn'),
            (int) (clone $query)->sum('sisa_hutang'),
            (int) (clone $query)->count(),
        ];
    }

    /** Label periode kartu mengikuti aturan filter tabel (bulan > tahun > rentang). */
    private function labelPeriodeBeli(Request $request, Carbon $awal, Carbon $akhir): string
    {
        if (($bulan = (string) $request->query('bulan')) && preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            return bulan_id($awal);
        }
        if (($tahun = (int) $request->query('tahun')) && $tahun >= 2000 && $tahun <= 2099) {
            return 'Tahun ' . $tahun;
        }

        return $awal->format('d/m/Y') . ' – ' . $akhir->format('d/m/Y');
    }

    /**
     * Resolve periode dari query string (bulan > tahun > rentang from/to).
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function periodeDariRequest(Request $request): array
    {
        if ($bulan = (string) $request->query('bulan')) {
            if (preg_match('/^\d{4}-\d{2}$/', $bulan) && Carbon::createFromFormat('Y-m', $bulan) !== false) {
                $awal = Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();
                $akhir = $awal->copy()->endOfMonth();
                return [$awal, $akhir];
            }
        }
        if ($tahun = (int) $request->query('tahun')) {
            if ($tahun >= 2000 && $tahun <= 2099) {
                $awal = Carbon::create($tahun, 1, 1)->startOfDay();
                $akhir = Carbon::create($tahun, 12, 31)->endOfDay();
                return [$awal, $akhir];
            }
        }
        $from = $request->query('from');
        $to = $request->query('to');
        $parsedFrom = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? Carbon::parse($from) : null;
        $parsedTo = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? Carbon::parse($to) : null;
        if ($parsedFrom && $parsedTo && $parsedFrom->lte($parsedTo)) {
            return [$parsedFrom, $parsedTo];
        }

        return [null, null];
    }

    /** Detail invoice (read-only). */
    public function show(InvoicePembelian $invoice)
    {
        $invoice->load(['items.barang', 'supplier', 'pembayaranHutang']);

        return view('pembelian.show', compact('invoice') + ['publik' => true]);
    }

    /** Cetak invoice (read-only) — layouts/print.blade.php tanpa dependensi auth. */
    public function print(InvoicePembelian $invoice)
    {
        $invoice->load(['items.barang', 'supplier', 'pembayaranHutang']);

        return view('pembelian.print', compact('invoice'));
    }
}
