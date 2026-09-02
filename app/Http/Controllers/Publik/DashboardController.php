<?php

namespace App\Http\Controllers\Publik;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\TokoSetting;
use App\Models\TransaksiPenjualan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Mode Publik (tanpa login) — Dashboard read-only.
 * Query identik dengan DashboardController admin; view yang sama dengan flag $publik = true
 * agar semua tombol/link mutasi disembunyikan.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        [$awal, $akhir, $labelPeriode] = $this->periodeDariRequest($request);

        $omsetPeriode = (int) TransaksiPenjualan::where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()])
            ->sum('total');

        $hutang = InvoicePembelian::where('status', 'belum_lunas')->get();
        $totalHutang = (int) $hutang->sum('sisa_hutang');
        $jatuhTempo7Hari = $hutang->filter(function ($inv) {
            return $inv->jatuh_tempo && $inv->jatuh_tempo->lessThanOrEqualTo(now()->addDays(7));
        });

        $settings = TokoSetting::utama();
        $ambang = (int) $settings->ambang_stok_menipis;
        $stokKritis = Barang::where('tipe_item', 'barang')
            ->where('stock', '<=', $ambang)
            ->orderBy('stock')
            ->get();
        $stokHabis = $stokKritis->where('stock', '<=', 0)->count();

        $penjualanTerakhir = TransaksiPenjualan::withCount('items')
            ->where('status', 'tersimpan')
            ->latest('tanggal_waktu')
            ->limit(8)
            ->get()
            ->map(fn ($t) => [
                'ref' => $t->ref_transaksi,
                'tanggal' => $t->tanggal_waktu,
                'pihak' => 'Customer Umum',
                'total' => $t->total,
                'sumber' => $t->sumber,
            ]);

        $pembelianTerakhir = InvoicePembelian::with('supplier')
            ->latest('tanggal_transaksi')
            ->limit(8)
            ->get()
            ->map(fn ($i) => [
                'ref' => $i->no_invoice,
                'tanggal' => $i->tanggal_transaksi,
                'pihak' => $i->supplier?->nama ?? '-',
                'total' => $i->total_setelah_diskon,
                'status' => $i->status,
            ]);

        $barangTerbaru = Barang::where('tipe_item', 'barang')
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'kode_barang', 'nama', 'satuan', 'stock']);

        return view('dashboard.index', compact(
            'awal', 'akhir', 'labelPeriode', 'omsetPeriode',
            'totalHutang', 'jatuhTempo7Hari', 'stokKritis', 'stokHabis', 'ambang',
            'penjualanTerakhir', 'pembelianTerakhir', 'barangTerbaru',
        ) + ['publik' => true]);
    }

    /**
     * Resolve periode dari query string (salinan DashboardController).
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function periodeDariRequest(Request $request): array
    {
        $awal = now()->copy()->startOfMonth();
        $akhir = now()->copy()->endOfMonth();
        $label = 'Bulan Berjalan — ' . bulan_id(now());

        if ($bulan = (string) $request->query('bulan')) {
            if (preg_match('/^\d{4}-\d{2}$/', $bulan) && Carbon::createFromFormat('Y-m', $bulan) !== false) {
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
}
