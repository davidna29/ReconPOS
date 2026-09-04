<?php

namespace App\Http\Controllers\Publik;

use App\Models\BatchGenerate;
use App\Models\TransaksiPenjualan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PenjualanController extends Controller
{
    public function index(Request $request)
    {
        // ---- Periode untuk kartu omset (identik dengan PenjualanController admin & Dashboard) ----
        [$awal, $akhir, $labelPeriode] = $this->periodeDariRequest($request);

        $omsetPeriode = (int) TransaksiPenjualan::where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()])
            ->sum('total');

        $batch = BatchGenerate::where('status', 'tersimpan')
            ->where('periode_mulai', '<=', $akhir)
            ->where('periode_selesai', '>=', $awal)
            ->latest('periode_mulai')
            ->first();
        $targetOmset = $batch?->target_omset;
        $sisaTarget = $targetOmset !== null ? max(0, $targetOmset - $omsetPeriode) : null;

        // ---- Tabel transaksi (read-only) ----
        // Kartu pakai default Bulan Berjalan, tabel hanya terfilter bila ada query periode eksplisit
        $query = TransaksiPenjualan::with(['items.barang'])
            ->where('status', 'tersimpan');
        if ($request->hasAny(['bulan', 'tahun', 'from', 'to'])) {
            $query->whereBetween('tanggal_waktu', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()]);
        }

        if ($s = trim((string) $request->get('q'))) {
            $query->where('ref_transaksi', 'like', "%{$s}%");
        }

        $transaksis = $query->latest('tanggal_waktu')
            ->paginate((int) ($request->get('entries') ?: 10))
            ->withQueryString();

        return view('penjualan.index', compact(
            'transaksis',
            'awal',
            'akhir',
            'labelPeriode',
            'omsetPeriode',
            'targetOmset',
            'sisaTarget',
        ) + ['publik' => true]);
    }

    /**
     * Resolve periode dari query string (salinan PenjualanController::periodeDariRequest).
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function periodeDariRequest(Request $request): array
    {
        $awal = now()->copy()->startOfMonth();
        $akhir = now()->copy()->endOfMonth();
        $label = 'Bulan Berjalan — ' . bulan_id(now());

        if ($bulan = (string) $request->query('bulan')) {
            if (preg_match('/^\d{4}-\d{2}$/', $bulan)) {
                $awal = Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();
                $akhir = $awal->copy()->endOfMonth();
                $label = bulan_id($awal);
            }
        } elseif ($tahun = (int) $request->query('tahun')) {
            if ($tahun >= 2000 && $tahun <= 2099) {
                $awal = Carbon::create($tahun, 1, 1)->startOfDay();
                $akhir = Carbon::create($tahun, 12, 31)->endOfDay();
                $label = 'Tahun ' . $tahun;
            }
        } elseif ($request->query('from') || $request->query('to')) {
            $from = $request->query('from');
            $to = $request->query('to');
            $parsedFrom = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? Carbon::parse($from) : null;
            $parsedTo = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? Carbon::parse($to) : null;
            if ($parsedFrom && $parsedTo && $parsedFrom->lte($parsedTo)) {
                $awal = $parsedFrom;
                $akhir = $parsedTo;
                $label = $parsedFrom->format('d/m/Y') . ' – ' . $parsedTo->format('d/m/Y');
            }
        }

        return [$awal, $akhir, $label];
    }

    /** Cetak struk (read-only). */
    public function print(TransaksiPenjualan $transaksiPenjualan)
    {
        $transaksiPenjualan->load(['items.barang', 'batchGenerate']);

        return view('penjualan.print', ['trx' => $transaksiPenjualan]);
    }
}
