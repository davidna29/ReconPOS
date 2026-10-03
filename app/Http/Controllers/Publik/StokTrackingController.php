<?php

namespace App\Http\Controllers\Publik;

use App\Http\Controllers\Controller;
use App\Services\StokTrackingService;
use Illuminate\Http\Request;

/**
 * Mode Publik (tanpa login) — Tracking Stok (stock aging FIFO), read-only.
 * Logika identik dengan StokTrackingController admin (via service bersama).
 */
class StokTrackingController extends Controller
{
    public function __construct(private StokTrackingService $stok) {}

    public function index(Request $request)
    {
        [$totalStok, $totalSku] = $this->stok->total();
        $availableMonths = $this->stok->bulanTersedia();
        [$selectedMonths, $dibatasi] = $this->stok->pilihBulan($availableMonths, $request->input('months'));

        [$dari, $sampai] = StokTrackingService::rentang($selectedMonths);
        $filtered = $this->stok->saringDanUmur($this->stok->hasilFifo($dari, $sampai), $selectedMonths);

        $entries = (int) $request->get('entries', 10);
        if (! in_array($entries, [10, 25, 50, 100], true)) {
            $entries = 10;
        }
        $paginator = $this->stok->paginasi($filtered, $entries, $request->url(), $request->query());
        $grouped = $paginator->getCollection()->groupBy(fn ($r) => $r['tanggal']->format('Y-m'));
        $ringkasBulan = $filtered->groupBy(fn ($r) => $r['tanggal']->format('Y-m'))
            ->map(fn ($rows) => [
                'batch' => $rows->count(),
                'sisa' => (int) $rows->sum('qty_sisa'),
                'sku' => $rows->pluck('barang.id')->unique()->count(),
            ])
            ->sortKeys();

        return view('stok-tracking.index', compact(
            'grouped', 'availableMonths', 'selectedMonths',
            'totalStok', 'totalSku', 'paginator', 'entries', 'dibatasi', 'ringkasBulan',
        ) + ['publik' => true]);
    }
}
