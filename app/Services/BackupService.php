<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Backup & restore database (PRD tambahan).
 * Format .gentot-backup.json.gz (JSON per-tabel + manifest, di-gzip) —
 * portabel lintas driver (SQLite lokal ↔ MySQL hosting), tanpa mysqldump.
 * Tabel transient (sessions/cache/jobs) & migrations tidak ikut.
 */
class BackupService
{
    public const FORMAT = 1;

    public const RETENSI = 5;

    public const RETENSI_PRA = 3;

    /** Urutan induk-dulu (dipakai insert; hapus dibalik). */
    public const TABEL = [
        'users',
        'kategori_barang',
        'supplier',
        'barang',
        'toko_settings',
        'template_export_pos_config',
        'password_reset_tokens',
        'invoice_pembelian',
        'invoice_pembelian_item',
        'pembayaran_hutang',
        'bulk_bayar_hutang',
        'histori_harga',
        'referensi_sn',
        'kartu_stok',
        'batch_generate',
        'transaksi_penjualan',
        'transaksi_penjualan_item',
        'export_batch',
    ];

    /** Tabel transient yang dibersihkan pasca-restore (bukan bagian backup). */
    public const TRANSIENT = [
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public static function direktori(): string
    {
        $dir = storage_path('app/private/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (! is_dir($dir . '/tmp')) {
            mkdir($dir . '/tmp', 0775, true);
        }

        return $dir;
    }

    /** Buat file backup dari DB saat ini. $prefix: gentot-backup | pra-restore. */
    public function buat(string $prefix = 'gentot-backup'): array
    {
        $tables = [];
        $totalBaris = 0;
        foreach (self::TABEL as $t) {
            if (! Schema::hasTable($t)) {
                continue; // DB lama sebelum migration tertentu — lewati dgn sadar
            }
            $rows = DB::table($t)->get()->map(fn ($r) => (array) $r)->all();
            $tables[$t] = [
                'count' => count($rows),
                'checksum' => sha1(json_encode($rows)),
                'rows' => $rows,
            ];
            $totalBaris += count($rows);
        }

        $payload = [
            'manifest' => [
                'app' => 'Aplikasi GenTOt',
                'format' => self::FORMAT,
                'created_at' => Carbon::now()->toDateTimeString(),
                'source_driver' => DB::getDriverName(),
                'code_tables' => self::TABEL,
                'total_baris' => $totalBaris,
            ],
            'tables' => $tables,
        ];

        $nama = $prefix . '-' . Carbon::now()->format('Ymd-His') . '.json.gz';
        $path = self::direktori() . '/' . $nama;
        file_put_contents($path, gzencode(json_encode($payload), 9));
        $this->terapkanRetensi($prefix);

        return [
            'nama' => $nama,
            'baris_total' => $totalBaris,
            'ukuran' => filesize($path),
            'tabel' => collect($tables)->map(fn ($v) => $v['count'])->all(),
        ];
    }

    /** Daftar file backup (terbaru dulu) beserta ringkasan manifest. */
    public function daftar(): array
    {
        $daftar = [];
        foreach (glob(self::direktori() . '/*.json.gz') ?: [] as $path) {
            $nama = basename($path);
            $info = ['nama' => $nama, 'ukuran' => filesize($path), 'waktu' => filemtime($path), 'baris_total' => null, 'created_at' => null, 'rusak' => false];
            try {
                $data = $this->bacaFile($path);
                $info['baris_total'] = $data['manifest']['total_baris'] ?? null;
                $info['created_at'] = $data['manifest']['created_at'] ?? null;
            } catch (\Throwable) {
                $info['rusak'] = true;
            }
            $daftar[] = $info;
        }
        usort($daftar, fn ($a, $b) => $b['waktu'] <=> $a['waktu']);

        return $daftar;
    }

    public function hapus(string $nama): void
    {
        $path = $this->pathAman($nama);
        if (! is_file($path)) {
            throw new InvalidArgumentException('File backup tidak ditemukan.');
        }
        unlink($path);
    }

    public function pathUnduh(string $nama): string
    {
        $path = $this->pathAman($nama);
        if (! is_file($path)) {
            throw new InvalidArgumentException('File backup tidak ditemukan.');
        }

        return $path;
    }

    /**
     * Simpan file upload ke tmp/ dan validasi isi. Return token (basename)
     * utk langkah pratinjau/konfirmasi.
     */
    public function terimaUpload(string $tmpPath, string $namaAsli): string
    {
        if (! preg_match('/\.json\.gz$/i', $namaAsli)) {
            throw new InvalidArgumentException('File harus berekstensi .json.gz (hasil menu Backup).');
        }
        $token = 'upload-' . Carbon::now()->format('Ymd-His') . '-' . substr(sha1($namaAsli . microtime()), 0, 8) . '.json.gz';
        $tujuan = self::direktori() . '/tmp/' . $token;
        if (! @copy($tmpPath, $tujuan)) {
            throw new InvalidArgumentException('Gagal menyimpan file upload.');
        }
        // Validasi langsung agar file rusak ditolak sebelum pratinjau
        $this->bacaFile($tujuan);

        return $token;
    }

    public function pathTmp(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9\-]+\.json\.gz$/', $token)) {
            throw new InvalidArgumentException('Token file tidak valid.');
        }
        $path = self::direktori() . '/tmp/' . $token;
        if (! is_file($path)) {
            throw new InvalidArgumentException('File upload kedaluwarsa. Unggah ulang.');
        }

        return $path;
    }

    /**
     * Pratinjau restore: validasi + perbandingan per tabel vs DB saat ini.
     * Read-only — tidak menulis apa pun.
     */
    public function pratinjau(string $path): array
    {
        $data = $this->bacaFile($path);
        $this->validasiStruktur($data, true);

        $perTabel = [];
        $warnings = [];
        foreach (self::TABEL as $t) {
            $diFile = $data['tables'][$t]['count'] ?? null;
            $diDb = Schema::hasTable($t) ? (int) DB::table($t)->count() : null;
            $perTabel[] = [
                'tabel' => $t,
                'di_file' => $diFile,
                'di_db' => $diDb,
                'selisih' => ($diFile !== null && $diDb !== null) ? $diFile - $diDb : null,
                'hilang_di_file' => $diFile === null,
            ];
            if ($diFile === null) {
                $warnings[] = "Tabel {$t} tidak ada di file (dibutuhkan kode saat ini).";
            }
        }

        $skipped = array_values(array_diff(array_keys($data['tables']), self::TABEL));
        foreach ($skipped as $s) {
            $warnings[] = "Tabel {$s} di file tidak dikenal kode saat ini — akan diabaikan.";
        }

        $errors = [];
        foreach ($perTabel as $p) {
            if ($p['hilang_di_file']) {
                $errors[] = "Tabel wajib {$p['tabel']} tidak ada di file backup.";
            }
        }

        return [
            'manifest' => $data['manifest'],
            'per_tabel' => $perTabel,
            'skipped' => $skipped,
            'warnings' => $warnings,
            'errors' => $errors,
            'ok' => count($errors) === 0,
        ];
    }

    /**
     * Eksekusi restore: validasi ketat → pra-backup otomatis → timpa atomik.
     * Mengembalikan ringkasan (termasuk nama pra-backup utk jalan kembali).
     *
     * ATURAN KERAS MySQL: di dalam DB::transaction HANYA boleh DML
     * (delete/insert). DDL apa pun (ALTER/CREATE/DROP/TRUNCATE) menyebabkan
     * *implicit commit* di MySQL → transaksi hilang → "There is no active
     * transaction" saat commit + atomisitas jebol (insiden restore 08/09/2026
     * di hosting; SQLite lokal tidak terpengaruh sehingga lolos test).
     */
    public function restore(string $path): array
    {
        $data = $this->bacaFile($path);
        $this->validasiStruktur($data, false); // ketat: checksum + tabel wajib

        $pra = $this->buat('pra-restore');

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($data) {
                // Hapus anak-dulu (urutan balik) — DML saja, JANGAN truncate
                foreach (array_reverse(self::TABEL) as $t) {
                    if (Schema::hasTable($t)) {
                        DB::table($t)->delete();
                    }
                }
                // Insert induk-dulu per chunk — DML saja
                foreach (self::TABEL as $t) {
                    $rows = $data['tables'][$t]['rows'] ?? null;
                    if ($rows === null || ! Schema::hasTable($t)) {
                        continue;
                    }
                    try {
                        foreach (array_chunk($rows, 500) as $chunk) {
                            DB::table($t)->insert($chunk);
                        }
                    } catch (\Throwable $e) {
                        throw new InvalidArgumentException(
                            "Gagal menulis tabel {$t} (kemungkinan beda struktur kolom versi kode): " . $e->getMessage()
                        );
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // DDL (sequence) SESUDAH transaksi commit — tidak boleh di dalamnya
        $this->perbaikiSequence();

        // Bersihkan transient + cache aplikasi/teks agar tak ada sesi basi
        foreach (self::TRANSIENT as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->delete();
            }
        }
        Artisan::call('optimize:clear');

        return [
            'pra_backup' => $pra['nama'],
            'baris_total' => $data['manifest']['total_baris'] ?? null,
            'created_at' => $data['manifest']['created_at'] ?? null,
            'tabel' => count($data['tables']),
        ];
    }

    // ---------- internal ----------

    /** Baca + decode file .json.gz → array. */
    private function bacaFile(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException('File backup tidak ditemukan.');
        }
        $raw = @gzdecode((string) file_get_contents($path));
        if ($raw === false) {
            throw new InvalidArgumentException('File bukan arsip backup yang valid (gagal dekompresi).');
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['manifest'], $data['tables']) || ! is_array($data['tables'])) {
            throw new InvalidArgumentException('File bukan backup Aplikasi GenTOt yang valid.');
        }

        return $data;
    }

    /**
     * Validasi struktur + checksum. $longgar=true dipakai pratinjau daftar
     * (tetap tampil walau ada masalah); restore memakai $longgar=false.
     */
    private function validasiStruktur(array $data, bool $longgar): void
    {
        $m = $data['manifest'];
        if (($m['app'] ?? null) !== 'Aplikasi GenTOt' || ! isset($data['tables'])) {
            throw new InvalidArgumentException('File bukan backup Aplikasi GenTOt yang valid.');
        }
        if (($m['format'] ?? 0) !== self::FORMAT) {
            throw new InvalidArgumentException('Format backup tidak didukung (format ' . ($m['format'] ?? '?') . ').');
        }
        foreach ($data['tables'] as $t => $info) {
            if (! is_array($info) || ! isset($info['rows'], $info['checksum']) || ! is_array($info['rows'])) {
                throw new InvalidArgumentException("Blok tabel {$t} rusak.");
            }
            if (sha1(json_encode($info['rows'])) !== $info['checksum']) {
                throw new InvalidArgumentException("Checksum tabel {$t} tidak cocok — file korup/diubah.");
            }
        }
        if (! $longgar) {
            foreach (self::TABEL as $t) {
                if (Schema::hasTable($t) && ! isset($data['tables'][$t])) {
                    throw new InvalidArgumentException("Tabel wajib {$t} tidak ada di file (backup dari versi kode lebih lama).");
                }
            }
        }
    }

    /** Samakan auto-increment dengan id maksimum per tabel (lintas driver). */
    private function perbaikiSequence(): void
    {
        $driver = DB::getDriverName();
        foreach (self::TABEL as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            try {
                $max = (int) DB::table($t)->max('id');
            } catch (\Throwable) {
                continue; // tabel tanpa kolom id (mis. password_reset_tokens)
            }
            if ($driver === 'sqlite') {
                DB::statement('UPDATE sqlite_sequence SET seq = ? WHERE name = ?', [$max, $t]);
            } elseif ($driver === 'mysql') {
                DB::statement('ALTER TABLE `' . $t . '` AUTO_INCREMENT = ' . ($max + 1));
            }
        }
    }

    private function terapkanRetensi(string $prefix): void
    {
        $batas = $prefix === 'pra-restore' ? self::RETENSI_PRA : self::RETENSI;
        $files = glob(self::direktori() . '/' . $prefix . '-*.json.gz') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, $batas) as $lama) {
            @unlink($lama);
        }
    }

    private function pathAman(string $nama): string
    {
        if (! preg_match('/^[A-Za-z0-9\-]+\.json\.gz$/', $nama)) {
            throw new InvalidArgumentException('Nama file tidak valid.');
        }

        return self::direktori() . '/' . $nama;
    }
}
