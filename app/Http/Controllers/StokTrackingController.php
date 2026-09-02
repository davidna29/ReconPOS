<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\KartuStok;
use Illuminate\Http\Request;

class StokTrackingController extends Controller
{
    public function index(Request $request)
    {
        // 1. Ambil semua barang dengan stock > 0
        $barangs = Barang::where('tipe_item', 'barang')
            ->where('stock', '>', 0)
            ->orderBy('nama')
            ->get();

        // 2. Ambil semua mutasi stok yang relevan
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

        // 3. Simulasi FIFO per barang -> kumpulkan batch yang masih punya sisa
        $results = [];
        foreach ($barangs as $b) {
            $batches = $masuk[$b->id] ?? collect();
            $keluars = $keluar[$b->id] ?? collect();

            // Init remaining per batch
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

            // FIFO: keluar menghabiskan batch paling tua
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

            // Filter batch yang masih punya sisa > 0
            foreach ($remaining as $batch) {
                if ($batch['qty_sisa'] > 0) {
                    $results[] = $batch;
                }
            }
        }

        // 4. Ambil daftar bulan unik dari data
        $availableMonths = collect($results)
            ->map(fn ($r) => $r['tanggal']->format('Y-m'))
            ->unique()
            ->sort()
            ->values();

        // 5. Filter berdasarkan months[] dari request
        $selectedMonths = $request->input('months') ?? $availableMonths->slice(-3)->values()->all();
        if (! is_array($selectedMonths)) {
            $selectedMonths = [$selectedMonths];
        }
        $selectedMonths = array_filter($selectedMonths, fn ($m) => preg_match('/^\d{4}-\d{2}$/', $m));

        $filtered = collect($results)
            ->filter(fn ($r) => in_array($r['tanggal']->format('Y-m'), $selectedMonths))
            ->sortBy('tanggal')
            ->values();

        // 6. Hitung umur (hari) dari tanggal beli ke hari ini
        $filtered = $filtered->map(fn ($r) => array_merge($r, [
            'umur_hari' => (int) now()->diffInDays($r['tanggal']),
        ]));

        // 7. Group by bulan
        $grouped = $filtered->groupBy(fn ($r) => $r['tanggal']->format('Y-m'));

        return view('stok-tracking.index', compact(
            'grouped', 'availableMonths', 'selectedMonths'
        ));
    }
}
