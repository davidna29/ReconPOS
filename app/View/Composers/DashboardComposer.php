<?php

namespace App\View\Composers;

use App\Models\InvoicePembelian;
use App\Models\TransaksiPenjualan;
use Illuminate\View\View;

/**
 * Data grafik dashboard (Batch G) — View Composer agar Controller,
 * Service, dan migration tidak disentuh. Semua angka dari query
 * yang sama dengan kartu dashboard; tanpa data dummy.
 */
class DashboardComposer
{
    public function compose(View $view): void
    {
        $data = $view->getData();
        /** @var \Illuminate\Support\Carbon $awal */
        $awal = $data['awal'];
        /** @var \Illuminate\Support\Carbon $akhir */
        $akhir = $data['akhir'];

        $start = $awal->copy()->startOfDay();
        $end = $akhir->copy()->endOfDay();

        // ---- Trend omset per hari (periode aktif) ----
        $perHari = TransaksiPenjualan::where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$start, $end])
            ->selectRaw('DATE(tanggal_waktu) as d, SUM(total) as t')
            ->groupBy('d')
            ->pluck('t', 'd');

        $days = (int) $start->diffInDays($end) + 1;
        // Rentang > 1 tahun diagregasi per minggu agar grafik tetap terbaca (total tetap utuh).
        $step = $days > 366 ? 7 : 1;
        $trendLabels = [];
        $trendValues = [];
        for ($i = 0; $i < $days; $i += $step) {
            $chunkAwal = $start->copy()->addDays($i);
            $chunkAkhir = $start->copy()->addDays(min($i + $step - 1, $days - 1));
            $sum = 0;
            for ($j = $i; $j <= min($i + $step - 1, $days - 1); $j++) {
                $sum += (int) ($perHari[$start->copy()->addDays($j)->format('Y-m-d')] ?? 0);
            }
            $trendLabels[] = $step === 1
                ? $chunkAwal->format('d/m')
                : $chunkAwal->format('d/m') . '–' . $chunkAkhir->format('d/m');
            $trendValues[] = $sum;
        }

        // ---- Delta vs periode sebelumnya (durasi sama, tepat sebelum $awal) ----
        $prevAkhir = $start->copy()->subDay()->endOfDay();
        $prevAwal = $prevAkhir->copy()->subDays($days - 1)->startOfDay();
        $omsetPrev = (int) TransaksiPenjualan::where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$prevAwal, $prevAkhir])
            ->sum('total');
        $omsetNow = (int) ($data['omsetPeriode'] ?? 0);
        $omsetDeltaPct = $omsetPrev > 0
            ? round(($omsetNow - $omsetPrev) / $omsetPrev * 100, 1)
            : null;

        // ---- Aging hutang: jarak urgency |jatuh_tempo − hari ini| ----
        $hutang = InvoicePembelian::where('status', 'belum_lunas')->get();
        $aging = ['< 7 hari' => 0, '7–30 hari' => 0, '> 30 hari' => 0];
        $tanpaTempo = 0;
        $today = now()->startOfDay();
        foreach ($hutang as $inv) {
            if (! $inv->jatuh_tempo) {
                $tanpaTempo++;
                continue;
            }
            $jarak = abs($today->diffInDays($inv->jatuh_tempo->copy()->startOfDay()));
            if ($jarak < 7) {
                $aging['< 7 hari']++;
            } elseif ($jarak <= 30) {
                $aging['7–30 hari']++;
            } else {
                $aging['> 30 hari']++;
            }
        }

        // Controller sudah mengurut stokKritis ASC (stok tersedikit dulu).
        $kritikTop = isset($data['stokKritis'])
            ? $data['stokKritis']->take(10)->values()
            : collect();

        $view->with([
            'trendLabels' => $trendLabels,
            'trendValues' => $trendValues,
            'trendStep' => $step,
            'omsetPrev' => $omsetPrev,
            'omsetDeltaPct' => $omsetDeltaPct,
            'agingLabels' => array_keys($aging),
            'agingValues' => array_values($aging),
            'tanpaTempo' => $tanpaTempo,
            'kritikTop' => $kritikTop,
        ]);
    }
}
