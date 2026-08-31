<?php

use Illuminate\Support\Carbon;

if (!function_exists('rupiah')) {
    /** Format Rupiah: Rp 1.000.000 */
    function rupiah(int|float $nilai): string
    {
        return 'Rp ' . number_format((int) round($nilai), 0, ',', '.');
    }
}

if (!function_exists('uang')) {
    /** Format angka tanpa Rp: 1.000.000 */
    function uang(int|float $nilai): string
    {
        return number_format((int) round($nilai), 0, ',', '.');
    }
}

if (!function_exists('tanggal_id')) {
    /** Format tanggal Indonesia: Senin, 5 Juni 2026 (opsional dengan jam) */
    function tanggal_id(DateTimeInterface|null $t = null, bool $denganJam = false): string
    {
        $t ??= now();
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) $t->format('w')];
        $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int) $t->format('n')];
        $hasil = $hari . ', ' . $t->format('j') . ' ' . $bulan . ' ' . $t->format('Y');
        if ($denganJam) {
            $hasil .= ' ' . $t->format('H:i');
        }
        return $hasil;
    }
}

if (!function_exists('bulan_id')) {
    /** Bulan pendek Indonesia: Juni 2026 */
    function bulan_id(DateTimeInterface|string|null $t = null): string
    {
        $t = $t instanceof DateTimeInterface ? $t : Carbon::parse($t ?? date('Y-m-d'));
        $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
                  'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][(int) $t->format('n')];
        return $bulan . ' ' . $t->format('Y');
    }
}
