<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Batas export MAKS 1 bulan: mode bulan OK; from/to maks 31 hari;
 * mode tahun & tanpa filter DITOLAK (file bisa raksasa).
 */
trait MembatasiExportSebulan
{
    /**
     * @return array{0: Carbon, 1: Carbon, 2: string} [awal, akhir, labelNamaFile]
     *
     * @throws InvalidArgumentException
     */
    private static function periodeExportSebulan(Request $request): array
    {
        $bulan = (string) $request->get('bulan');
        if ($bulan !== '' && preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $awal = Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();

            return [$awal, $awal->copy()->endOfMonth(), $bulan];
        }

        $from = (string) $request->get('from');
        $to = (string) $request->get('to');
        if ($from !== '' && $to !== '') {
            $awal = Carbon::parse($from)->startOfDay();
            $akhir = Carbon::parse($to)->endOfDay();
            if ($awal->greaterThan($akhir)) {
                throw new InvalidArgumentException('Rentang tanggal tidak valid (Dari melewati Sampai).');
            }
            if ($awal->diffInDays($akhir) > 30) {
                throw new InvalidArgumentException('Export dibatasi 1 bulan — rentang Dari–Sampai maksimal 31 hari.');
            }

            return [$awal, $akhir, $awal->format('Ymd') . '-' . $akhir->format('Ymd')];
        }

        throw new InvalidArgumentException('Export dibatasi 1 bulan — pilih Bulan atau isi rentang Dari–Sampai (maksimal 31 hari). Mode Tahun tidak didukung.');
    }
}
