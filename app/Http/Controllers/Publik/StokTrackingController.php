<?php

namespace App\Http\Controllers\Publik;

use App\Models\Barang;
use App\Models\KartuStok;
use Illuminate\Http\Request;

/**
 * Mode Publik (tanpa login) — Tracking Stok (stock aging FIFO), read-only.
 * Logika identik dengan StokTrackingController admin.
 */
class StokTrackingController extends Controller
{
    public function index(Request $request)
    {
        $totalStok = (int) Barang::where('tipe_item', 'barang')
            ->where('stock', '>', 0)
            ->sum('stock');
        $totalSku = Barang::where('tipe_item', 'barang')
            ->where('stock', '>', 0)
            ->count();

        $barangs = Barang::where('tipe_item', 'barang')
            ->where('stock', '>', 0)
            ->orderBy('nama')
            ->get();

        $masuk = KartuStok::whereIn('barang_id', $barangs->pluck('id'))
            ->whereIn('jenis', ['masuk_pembelian', 'stok_awal', 'opname'])
            ->orderBy('tanggal')
            ->get()
            ->groupBy('barang_id');

        $keluar = KartuStok::whereIn('barang_id', $barangs->pluck('id'))
            ->where('jenis', 'keluar_penjualan')
            ->orderBy('tanggal')
            ->get()
            ->groupBy('barang_id');

        $results = [];
        foreach ($barangs as $b) {
            $batches = $masuk[$b->id] ?? collect();
            $keluars = $keluar[$b->id] ?? collect();

            $remaining = [];
            foreach ($batches as $i => $m) {
                $remaining[$i] = [
                    'barang' => $b,
                    'tanggal' => $m->tanggal,
                    'jenis' => $m->jenis,
                    'qty_awal' => $m->qty,
                    'qty_sisa' => $m->qty,
                    'invoice_id' => $m->invoice_pembelian_id,
                    'keterangan' => $m->keterangan,
                ];
            }

            foreach ($keluars as $k) {
                $sisaKeluar = abs($k->qty);
                foreach ($remaining as $idx => &$batch) {
                    if ($sisaKeluar <= 0) break;
                    if ($batch['qty_sisa'] > 0) {
                        $consume = min($batch['qty_sisa'], $sisaKeluar);
                        $batch['qty_sisa'] -= $consume;
                        $sisaKeluar -= $consume;
                    }
                }
                unset($batch);
            }

            foreach ($remaining as $batch) {
                if ($batch['qty_sisa'] > 0) {
                    $results[] = $batch;
                }
            }
        }

        $availableMonths = collect($results)
            ->map(fn ($r) => $r['tanggal']->format('Y-m'))
            ->unique()
            ->sort()
            ->values();

        $selectedMonths = $request->input('months') ?? $availableMonths->values()->all();
        if (! is_array($selectedMonths)) {
            $selectedMonths = [$selectedMonths];
        }
        $selectedMonths = array_filter($selectedMonths, fn ($m) => preg_match('/^\d{4}-\d{2}$/', $m));

        $filtered = collect($results)
            ->filter(fn ($r) => in_array($r['tanggal']->format('Y-m'), $selectedMonths))
            ->sortBy('tanggal')
            ->values();

        $filtered = $filtered->map(fn ($r) => array_merge($r, [
            'umur_hari' => (int) now()->diffInDays($r['tanggal']),
        ]));

        $grouped = $filtered->groupBy(fn ($r) => $r['tanggal']->format('Y-m'));

        return view('stok-tracking.index', compact(
            'grouped', 'availableMonths', 'selectedMonths',
            'totalStok', 'totalSku',
        ) + ['publik' => true]);
    }
}
