<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Cadangkan database ke storage/app/private/backups-db/db-YYYYMMDD-HHMMSS.sql.gz.
 * Dipakai lokal maupun remote (oleh scripts/deploy.sh tahap backup).
 */
class CadangkanDb extends Command
{
    protected $signature = 'app:cadangkan-db';
    protected $description = 'Dump database (mysqldump/sqlite) ke file .sql.gz';

    public function handle(): int
    {
        $conn = config('database.default');
        $dir = storage_path('app/private/backups-db');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $nama = 'db-' . now()->format('Ymd-His') . '.sql.gz';
        $path = $dir . '/' . $nama;

        if ($conn === 'sqlite') {
            $file = config('database.connections.sqlite.database');
            $ok = copy($file, $dir . '/db-' . now()->format('Ymd-His') . '.sqlite');
            if (! $ok) {
                $this->error('Gagal menyalin file sqlite.');

                return 1;
            }
            $this->info('Backup sqlite: ' . $dir . '/db-' . now()->format('Ymd-His') . '.sqlite');

            return 0;
        }

        $c = config('database.connections.' . $conn);
        $mysqldump = trim((string) Process::run('command -v mysqldump')->output());
        if ($mysqldump === '') {
            $this->error('mysqldump tidak ditemukan di server ini.');

            return 1;
        }

        // Kredensial via env aman (tanpa shell quoting manual utk password).
        $env = [
            'MYSQL_PWD' => (string) ($c['password'] ?? ''),
        ];
        $cmd = [
            $mysqldump,
            '-h', (string) ($c['host'] ?? '127.0.0.1'),
            '-P', (string) ($c['port'] ?? '3306'),
            '-u', (string) ($c['username'] ?? ''),
            '--single-transaction', '--quick',
            (string) ($c['database'] ?? ''),
        ];
        $dump = Process::env($env)->timeout(600)->run($cmd);
        if (! $dump->successful()) {
            $this->error('mysqldump gagal: ' . trim($dump->errorOutput()));

            return 1;
        }
        file_put_contents('compress.zlib://' . $path, $dump->output());
        $this->info('Backup db: ' . $path . ' (' . number_format(filesize($path)) . ' byte)');

        return 0;
    }
}
