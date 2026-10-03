<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\KartuStok;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Logika stock-aging FIFO bersama utk Tracking Stok admin & publik.
 *
 * Aturan beban (anti-ledakan load):
 * - Tampilan layar dibatasi MAKS_BULAN_TAMPIL bulan (default 2 terbaru).
 * - Query batch MASUK difilter bulan di SQL; batch KELUAR tetap penuh
 *   (penjualan kapan pun bisa menghabiskan batch terpilih — syarat
 *   ketepatan FIFO, tak bisa dipangkas tanpa mengubah makna angka).
 * - Export boleh semua bulan tapi dijaga pre-flight AMBANG_* baris.
 */
class StokTrackingService
{
    public const MAKS_BULAN_TAMPIL = 2;

    /** Estimasi baris batch masuk di atas ini → export xlsx ditolak (fail-fast). */
    public const AMBANG_BARIS_EXPORT = 3000;

    /** dompdf jauh lebih boros memori → ambang PDF lebih rendah. */
    public const AMBANG_BARIS_PDF = 1500;

    /** @return array{0: int, 1: int} [totalStok, totalSku] tanpa filter (2 agregat murah). */
    public function total(): array
    {
        $q = Barang::where('tipe_item', 'barang')->where('stock', '>', 0);

        return [(int) (clone $q)->sum('stock'), (int) (clone $q)->count()];
    }

    /** Daftar bulan Y-m (asc) yg punya batch masuk — query distinct murah, tanpa FIFO. */
    public function bulanTersedia(): Collection
    {
        // Jebakan SQLite-vs-MySQL: DATE_FORMAT tak ada di SQLite.
        $driver = KartuStok::query()->getConnection()->getDriverName();
        $expr = $driver === 'sqlite' ? "strftime('%Y-%m', tanggal)" : "DATE_FORMAT(tanggal, '%Y-%m')";

        return KartuStok::whereIn('jenis', ['masuk_pembelian', 'stok_awal', 'opname'])
            ->selectRaw("{$expr} as bl")
            ->distinct()
            ->orderBy('bl')
            ->pluck('bl')
            ->values();
    }

    /**
     * Resolve bulan terpilih + flag pembatasan.
     *
     * @param  array|string|null  $diminta  input months[] mentah
     * @return array{0: array, 1: bool} [selected(Y-m), dibatasi]
     */
    public function pilihBulan(Collection $tersedia, mixed $diminta): array
    {
        if ($diminta === null) {
            return [$tersedia->slice(-self::MAKS_BULAN_TAMPIL)->values()->all(), false];
        }
        $valid = array_values(array_filter(
            (array) $diminta,
            fn ($m) => is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m)
        ));
        if (count($valid) <= self::MAKS_BULAN_TAMPIL) {
            return [$valid, false];
        }
        rsort($valid);

        return [array_slice($valid, 0, self::MAKS_BULAN_TAMPIL), true];
    }

    /** Rentang [dari, sampai] Y-m dari bulan terpilih (utk filter SQL). */
    public static function rentang(array $selected): array
    {
        if ($selected === []) {
            return [null, null];
        }
        $urut = $selected;
        sort($urut);

        return [$urut[0], $urut[count($urut) - 1]];
    }

    /**
     * Simulasi FIFO per barang → batch yg masih punya sisa.
     * Semantik IDENTIK dgn implementasi awal (urutan + konsumsi tak diubah).
     */
    public function hasilFifo(?string $dariBulan = null, ?string $sampaiBulan = null): Collection
    {
        $barangs = Barang::where('tipe_item', 'barang')
            ->where('stock', '>', 0)
            ->orderBy('nama')
            ->get();

        $masukQ = KartuStok::whereIn('barang_id', $barangs->pluck('id'))
            ->whereIn('jenis', ['masuk_pembelian', 'stok_awal', 'opname']);
        if ($dariBulan && $sampaiBulan) {
            $masukQ->whereBetween('tanggal', [
                Carbon::createFromFormat('Y-m', $dariBulan)->startOfMonth(),
                Carbon::createFromFormat('Y-m', $sampaiBulan)->endOfMonth(),
            ]);
        }
        $masuk = $masukQ->orderBy('tanggal')->get()->groupBy('barang_id');

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
                    if ($sisaKeluar <= 0) {
                        break;
                    }
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

        return collect($results);
    }

    /** Saring ke bulan terpilih + hitung umur hari, urut tanggal. */
    public function saringDanUmur(Collection $results, array $selected): Collection
    {
        return $results
            ->filter(fn ($r) => in_array($r['tanggal']->format('Y-m'), $selected))
            ->sortBy('tanggal')
            ->values()
            ->map(fn ($r) => array_merge($r, [
                'umur_hari' => (int) now()->diffInDays($r['tanggal']),
            ]));
    }

    /** Estimasi baris batch masuk utk pre-flight export (batas atas: sisa ≤ masuk). */
    public function estimasiBaris(?string $dariBulan = null, ?string $sampaiBulan = null): int
    {
        $q = KartuStok::whereIn('jenis', ['masuk_pembelian', 'stok_awal', 'opname']);
        if ($dariBulan && $sampaiBulan) {
            $q->whereBetween('tanggal', [
                Carbon::createFromFormat('Y-m', $dariBulan)->startOfMonth(),
                Carbon::createFromFormat('Y-m', $sampaiBulan)->endOfMonth(),
            ]);
        }

        return (int) $q->count();
    }

    public function paginasi(Collection $baris, int $perPage, string $path, array $query): LengthAwarePaginator
    {
        $total = $baris->count();
        $halaman = LengthAwarePaginator::resolveCurrentPage();
        $potongan = $baris->slice(($halaman - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($potongan, $total, $perPage, $halaman, [
            'path' => $path,
            'query' => $query,
        ]);
    }
}
