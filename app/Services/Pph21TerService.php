<?php

namespace App\Services;

/**
 * Kalkulator tarif TER PPh 21 bulanan.
 * Tabel bracket disalin persis dari "TEMPLATE BPMP Excel to XML v.3.xlsx"
 * sheet DATA kolom P (TER A), Q (TER B), R (TER C) — tervalidasi melawan
 * nilai cache Excel (baris 5–10 cocok 100%).
 *
 * Pemetaan kolom L template:
 *   PTKP {TK/0, TK/1, K/0, HB/0, HB/1} → TER A
 *   PTKP {TK/2, TK/3, K/1, K/2, HB/2, HB/3} → TER B
 *   selain itu (K/3) → TER C
 */
class Pph21TerService
{
    public const STATUS_PEGAWAI = ['Resident', 'Foreign'];

    public const PTKP = [
        'TK/0', 'TK/1', 'TK/2', 'TK/3',
        'K/0', 'K/1', 'K/2', 'K/3',
        'HB/0', 'HB/1', 'HB/2', 'HB/3',
    ];

    public const SERTIFIKAT = ['N/A', 'DTP', 'ETC'];

    public const KODE_OBJEK = ['21-100-01', '21-100-02', '21-100-32'];

    private const GRUP_A = ['TK/0', 'TK/1', 'K/0', 'HB/0', 'HB/1'];

    private const GRUP_B = ['TK/2', 'TK/3', 'K/1', 'K/2', 'HB/2', 'HB/3'];

    /** [batas_atas_gross, tarif] — gross <= batas_atas → tarif. Di atas tabel → 34. */
    private const TER_A = [[5400000, 0], [5650000, 0.25], [5950000, 0.5], [6300000, 0.75], [6750000, 1], [7500000, 1.25], [8550000, 1.5], [9650000, 1.75], [10050000, 2], [10350000, 2.25], [10700000, 2.5], [11050000, 3], [11600000, 3.5], [12500000, 4], [13750000, 5], [15100000, 6], [16950000, 7], [19750000, 8], [24150000, 9], [26450000, 10], [28000000, 11], [30050000, 12], [32400000, 13], [35400000, 14], [39100000, 15], [43850000, 16], [47800000, 17], [51400000, 18], [56300000, 19], [62200000, 20], [68600000, 21], [77500000, 22], [89000000, 23], [103000000, 24], [125000000, 25], [157000000, 26], [206000000, 27], [337000000, 28], [454000000, 29], [550000000, 30], [695000000, 31], [910000000, 32], [1400000000, 33]];

    private const TER_B = [[6200000, 0], [6500000, 0.25], [6850000, 0.5], [7300000, 0.75], [9200000, 1], [10750000, 1.5], [11250000, 2], [11600000, 2.5], [12600000, 3], [13600000, 4], [14950000, 5], [16400000, 6], [18450000, 7], [21850000, 8], [26000000, 9], [27700000, 10], [29350000, 11], [31450000, 12], [33950000, 13], [37100000, 14], [41100000, 15], [45800000, 16], [49500000, 17], [53800000, 18], [58500000, 19], [64000000, 20], [71000000, 21], [80000000, 22], [93000000, 23], [109000000, 24], [129000000, 25], [163000000, 26], [211000000, 27], [374000000, 28], [459000000, 29], [555000000, 30], [704000000, 31], [957000000, 32], [1405000000, 33]];

    private const TER_C = [[6600000, 0], [6950000, 0.25], [7350000, 0.5], [7800000, 0.75], [8850000, 1], [9800000, 1.25], [10950000, 1.5], [11200000, 1.75], [12050000, 2], [12950000, 3], [14150000, 4], [15550000, 5], [17050000, 6], [19500000, 7], [22700000, 8], [26600000, 9], [28100000, 10], [30100000, 11], [32600000, 12], [35400000, 13], [38900000, 14], [43000000, 15], [47400000, 16], [51200000, 17], [55800000, 18], [60400000, 19], [66700000, 20], [74500000, 21], [83200000, 22], [95600000, 23], [110000000, 24], [134000000, 25], [169000000, 26], [221000000, 27], [390000000, 28], [463000000, 29], [561000000, 30], [709000000, 31], [965000000, 32], [1419000000, 33]];

    public static function grupUntukPtkp(string $ptkp): string
    {
        if (in_array($ptkp, self::GRUP_A, true)) {
            return 'A';
        }
        if (in_array($ptkp, self::GRUP_B, true)) {
            return 'B';
        }

        return 'C';
    }

    public static function hitung(string $ptkp, int $gross): float
    {
        $tabel = match (self::grupUntukPtkp($ptkp)) {
            'A' => self::TER_A,
            'B' => self::TER_B,
            default => self::TER_C,
        };

        foreach ($tabel as [$batas, $tarif]) {
            if ($gross <= $batas) {
                return (float) $tarif;
            }
        }

        return 34.0;
    }

    public static function isNikValid(?string $nik): bool
    {
        return is_string($nik) && preg_match('/^\d{16}$/', $nik) === 1;
    }

    public static function isNpwpPemotongValid(?string $npwp): bool
    {
        return self::isNikValid($npwp);
    }

    public static function idTkuDariNpwp(string $npwp): string
    {
        return $npwp.'000000';
    }

    /**
     * Bangun rumus Excel kolom TER (P/Q/R template) dari tabel bracket —
     * satu-satunya sumber kebenaran bersama hitung(), agar nilai PHP
     * dan formula Excel tidak pernah selisih.
     */
    public static function formulaTer(string $grup, string $sel): string
    {
        $tabel = match (strtoupper($grup)) {
            'A' => self::TER_A,
            'B' => self::TER_B,
            default => self::TER_C,
        };

        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        [$b0, $r0] = $tabel[0];
        $rumus = '=IF('.$sel.'<='.$b0.','.$fmt($r0);
        $prev = $b0;
        foreach (array_slice($tabel, 1) as [$batas, $tarif]) {
            $rumus .= ',IF(AND('.$sel.'>'.$prev.','.$sel.'<='.$batas.'),'.$fmt($tarif);
            $prev = $batas;
        }
        $rumus .= ',34'.str_repeat(')', count($tabel));

        return $rumus;
    }

    /** Rumus Excel kolom Tarif (L template): pilih TER A/B/C berdasar PTKP. */
    public static function formulaTarif(string $selPtkp, string $selA, string $selB, string $selC): string
    {
        $orA = 'OR('.implode(',', array_map(fn ($p) => $selPtkp.'="'.$p.'"', self::GRUP_A)).')';
        $orB = 'OR('.implode(',', array_map(fn ($p) => $selPtkp.'="'.$p.'"', self::GRUP_B)).')';

        return '=IF('.$orA.','.$selA.',IF('.$orB.','.$selB.','.$selC.'))';
    }
}
