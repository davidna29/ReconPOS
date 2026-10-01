<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Kirim kode lokal ke shared hosting (rsync delta + artisan remote).
 * Dijalankan manual via terminal ATAU tombol admin lokal (DeployController).
 * Keluar bukan-nol + pesan jelas pada tahap pertama yg gagal.
 */
class KirimHosting extends Command
{
    protected $signature = 'app:kirim-hosting
        {--dry-run : tampilkan yg akan dikirim/dijalankan tanpa mengubah apa pun}
        {--force : sertakan perubahan git yg belum di-commit}
        {--tanpa-test : lewati php artisan test}
        {--backup : cadangkan DB hosting dulu (app:cadangkan-db remote)}
        {--tanpa-maintenance : jangan masuk maintenance mode}';

    protected $description = 'Deploy ke shared hosting: cek, rsync, artisan remote, verifikasi';

    private string $logFile = '';
    private array $cfg = [];

    public function handle(): int
    {
        $this->cfg = config('deploy');
        $this->logFile = (string) ($this->cfg['log'] ?? storage_path('logs/kirim-hosting.log'));
        @unlink($this->logFile);
        file_put_contents((string) ($this->cfg['running'] ?? ''), json_encode(['pid' => getmypid(), 'mulai' => now()->toDateTimeString()]));

        try {
            return $this->jalan() ? 0 : 1;
        } finally {
            @unlink((string) ($this->cfg['running'] ?? ''));
        }
    }

    private function jalan(): bool
    {
        // 0. Gate: runtime lokal ATAU config (konsisten dgn menu sidebar).
        if (! app()->isLocal() && ! $this->cfg['allow_remote']) {
            return $this->gagal('Ditolak: deploy hanya diizinkan dari lokal (DEPLOY_ALLOW).');
        }
        $dry = (bool) $this->option('dry-run');
        $this->catat($dry ? '== MODE DRY-RUN (tidak ada yg diubah) ==' : '== KIRIM KE HOSTING ==');

        // 1. Prasyarat lokal.
        if (! $this->tahap('git bersih', fn () => $this->cekGit())) {
            return false;
        }
        if (! $this->option('tanpa-test') && ! $this->tahap('php artisan test', fn () => $this->cekTest())) {
            return false;
        }
        if (! $this->tahap('build Vite segar', fn () => $this->cekBuild())) {
            return false;
        }

        // 2. Kunci SSH.
        $ssh = $this->sshBase();
        $cek = $this->proc(array_merge($ssh, ['echo', 'SSH-OK', '&&', $this->cfg['php'], '-v']), 30);
        if (! $cek || ! str_contains($cek, 'SSH-OK')) {
            return $this->gagal('SSH gagal. Pasang key dulu: ssh-copy-id -p ' . $this->cfg['ssh_port'] . ' ' . $this->cfg['ssh_user'] . '@' . $this->cfg['ssh_host']);
        }
        $this->catat(trim($cek));

        // 3. Deteksi layout proyek di server.
        $paths = $this->deteksiPath($ssh);
        if (! $paths) {
            return false;
        }
        [$project, $docroot] = $paths;
        $this->catat('Project : ' . $project);
        $this->catat('Docroot : ' . $docroot);

        // 4. rsync kode (delta).
        $rsync = array_merge(['rsync', '-az', '--delete'], self::opsiExclude(), ['-e', $this->sshEString(), base_path() . '/', $this->cfg['ssh_user'] . '@' . $this->cfg['ssh_host'] . ':' . $project . '/']);
        if ($dry) {
            $lihat = $rsync;
            array_splice($lihat, 2, 0, ['-n', '--itemize-changes']);
            $out = $this->proc($lihat, 300);
            $this->catat($out === null ? '(rsync dry-run gagal dijalankan)' : "File berubah:\n" . $out);
            $this->catat('[dry-run] Perintah remote yg AKAN dijalankan:');
            foreach ($this->perintahRemote($project, $docroot, true) as $c) {
                $this->catat('  $ ' . $c);
            }

            return true;
        }
        if (! $this->tahap('rsync kode', fn () => $this->proc($rsync, (int) $this->cfg['timeout']) !== null)) {
            return false;
        }

        // 5. Maintenance (kecuali diminta tanpa).
        $maintenance = ! (bool) $this->option('tanpa-maintenance');
        if ($maintenance) {
            $this->remote($ssh, $project, $this->cfg['php'] . ' artisan down --render="errors::503" 2>/dev/null || ' . $this->cfg['php'] . ' artisan down');
        }

        $ok = true;
        try {
            // 6. Backup opsional (DB hosting, sebelum kode berubah).
            if ($this->option('backup') && ! $this->tahap('backup DB hosting', fn () => $this->remoteOk($ssh, $project, $this->cfg['php'] . ' artisan app:cadangkan-db'))) {
                $this->catat('PERINGATAN: backup gagal — lanjut deploy (migrasi bersifat aditif).');
            }
            // 7. Sambung public/ -> docroot.
            if ($ok && ! $this->tahap('sambung public', fn () => $this->remoteOk($ssh, $project, $this->cfg['php'] . ' artisan app:sambung-public --docroot=' . escapeshellarg($docroot)))) {
                $ok = false;
            }
            // 8. Dependensi + DB + cache (SATU binari PHP).
            foreach ($this->perintahRemote($project, $docroot, false) as $label => $cmd) {
                if (! $this->tahap($label, fn () => $this->remoteOk($ssh, $project, $cmd))) {
                    $ok = false;
                    break;
                }
            }
        } finally {
            if ($maintenance) {
                $this->remote($ssh, $project, $this->cfg['php'] . ' artisan up');
            }
        }
        if (! $ok) {
            return false;
        }

        // 9. Verifikasi HTTP.
        $kode = trim((string) $this->proc(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '30', rtrim((string) $this->cfg['url'], '/')], 60));
        $this->catat('HTTP ' . $this->cfg['url'] . ' => ' . $kode);
        if ($kode !== '200') {
            return $this->gagal('Verifikasi gagal (HTTP ' . $kode . '). Cek storage/logs/laravel.log di server.');
        }

        $this->catat('SELESAI. Commit lokal: ' . trim((string) $this->proc(['git', '-C', base_path(), 'rev-parse', '--short', 'HEAD'], 30)));
        $this->info('SELESAI');

        return true;
    }

    // ---------- tahap & util ----------

    private function tahap(string $nama, callable $fn): bool
    {
        $this->catat('--- ' . $nama . ' ---');
        try {
            if ($fn()) {
                $this->catat('OK: ' . $nama);

                return true;
            }
        } catch (\Throwable $e) {
            $this->catat('GAGAL: ' . $nama . ' — ' . $e->getMessage());
        }

        return $this->gagal('Berhenti di tahap: ' . $nama);
    }

    private function gagal(string $pesan): bool
    {
        $this->catat('GAGAL: ' . $pesan);
        $this->error($pesan);

        return false;
    }

    private function catat(string $pesan): void
    {
        $baris = '[' . now()->format('H:i:s') . '] ' . $pesan;
        $this->line($baris);
        file_put_contents($this->logFile, $baris . PHP_EOL, FILE_APPEND);
    }

    private function proc(array $cmd, int $timeout): ?string
    {
        $r = Process::timeout($timeout)->run($cmd);
        $this->catat('$ ' . implode(' ', array_map(fn ($a) => str_contains($a, ' ') ? "'$a'" : $a, $cmd)));
        $out = trim($r->output() . "\n" . $r->errorOutput());
        if ($out !== '') {
            foreach (explode("\n", $out) as $l) {
                $this->catat('  | ' . $l);
            }
        }
        if (! $r->successful()) {
            $this->catat('  ! exit ' . $r->exitCode());
        }

        return $r->successful() ? $r->output() : null;
    }

    private function sshBase(): array
    {
        $cmd = ['ssh', '-p', (string) $this->cfg['ssh_port'], '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'StrictHostKeyChecking=accept-new'];
        if (! empty($this->cfg['ssh_key'])) {
            array_push($cmd, '-i', (string) $this->cfg['ssh_key']);
        }

        return array_merge($cmd, [$this->cfg['ssh_user'] . '@' . $this->cfg['ssh_host']]);
    }

    private function sshEString(): string
    {
        $e = 'ssh -p ' . $this->cfg['ssh_port'] . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new';
        if (! empty($this->cfg['ssh_key'])) {
            $e .= ' -i ' . escapeshellarg((string) $this->cfg['ssh_key']);
        }

        return $e;
    }

    /** Jalankan perintah di server (cd project dulu). True bila exit 0. */
    private function remote(array $ssh, string $project, string $cmd): ?string
    {
        return $this->proc(array_merge($ssh, ['cd ' . escapeshellarg($project) . ' && ' . $cmd]), (int) $this->cfg['timeout']);
    }

    private function remoteOk(array $ssh, string $project, string $cmd): bool
    {
        return $this->remote($ssh, $project, $cmd) !== null;
    }

    /** Daftar exclude rsync (pure, testable). */
    public static function opsiExclude(): array
    {
        $pola = [
            '.env*', 'node_modules/', 'vendor/', '.git/', '.DS_Store',
            'database/*.sqlite*', '.phpunit.result.cache', 'public/hot',
            'storage/logs/*', 'storage/framework/cache/*', 'storage/framework/sessions/*',
            'storage/framework/views/*', 'storage/app/private/backups/*', 'storage/app/private/backups-db/*',
            'storage/app/private/export/*', 'storage/debugbar/',
        ];
        $out = [];
        foreach ($pola as $p) {
            $out[] = '--exclude';
            $out[] = $p;
        }

        return $out;
    }

    /** Kandidat path proyek dari domain URL (pure, testable). */
    public static function kandidatPath(string $domain): array
    {
        $d = '~/domains/' . $domain;

        return [$d . '/reconpos', $d . '/public_html/reconpos'];
    }

    private function deteksiPath(array $ssh): ?array
    {
        if (! empty($this->cfg['path'])) {
            $project = (string) $this->cfg['path'];
        } else {
            $domain = (string) parse_url((string) $this->cfg['url'], PHP_URL_HOST);
            $project = null;
            foreach (self::kandidatPath($domain) as $k) {
                $cek = $this->proc(array_merge($ssh, ['test -d ' . escapeshellarg($k) . ' && echo ADA']), 30);
                if ($cek !== null && str_contains($cek, 'ADA')) {
                    $project = $k;
                    break;
                }
            }
            if (! $project) {
                $this->gagal('Path proyek tak ditemukan. Isi DEPLOY_PATH manual. Dicoba: ' . implode(', ', self::kandidatPath($domain)));

                return null;
            }
        }
        $docroot = ! empty($this->cfg['docroot']) ? (string) $this->cfg['docroot'] : '~/domains/' . parse_url((string) $this->cfg['url'], PHP_URL_HOST) . '/public_html';
        $cekDoc = $this->proc(array_merge($ssh, ['test -d ' . escapeshellarg($docroot) . ' && echo ADA']), 30);
        if ($cekDoc === null || ! str_contains($cekDoc, 'ADA')) {
            $this->gagal('Docroot tak ditemukan: ' . $docroot . '. Isi DEPLOY_DOCROOT manual.');

            return null;
        }

        return [$project, $docroot];
    }

    /** Perintah artisan remote berurutan (label => cmd). Pure kecuali baca cfg. */
    public function perintahRemote(string $project, string $docroot, bool $dry): array
    {
        $php = (string) $this->cfg['php'];

        return [
            'composer install' => '(command -v composer >/dev/null && composer install --no-dev --optimize-autoloader) || (' . $php . ' composer.phar install --no-dev --optimize-autoloader) || { echo BUTUH-COMPOSER-MANUAL; exit 1; }',
            'migrate' => $php . ' artisan migrate --force',
            'izin storage' => 'chmod -R 775 storage bootstrap/cache',
            'cache config' => $php . ' artisan optimize:clear && ' . $php . ' artisan config:cache && ' . $php . ' artisan route:cache && ' . $php . ' artisan view:cache',
        ];
    }

    private function cekGit(): bool
    {
        $out = $this->proc(['git', '-C', base_path(), 'status', '--porcelain'], 30);
        if ($out === null) {
            $this->catat('git tak tersedia — lewati cek kebersihan.');

            return true;
        }
        if (trim($out) !== '' && ! $this->option('force')) {
            $this->catat('Working tree kotor. Commit dulu atau pakai --force.');

            return false;
        }

        return true;
    }

    private function cekTest(): bool
    {
        $this->catat('Menjalankan php artisan test (bisa ~1 menit)...');

        return $this->proc([PHP_BINARY, base_path() . '/artisan', 'test'], 900) !== null;
    }

    private function cekBuild(): bool
    {
        $manifest = base_path() . '/public/build/manifest.json';
        if (! file_exists($manifest)) {
            $this->catat('public/build/manifest.json hilang — jalankan: npm run build');

            return false;
        }
        $tManifest = filemtime($manifest);
        foreach (['resources/css', 'resources/js'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path() . '/' . $dir));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getMTime() > $tManifest) {
                    $this->catat('Build kedaluwarsa (' . $f->getPathname() . ' lebih baru). Jalankan: npm run build');

                    return false;
                }
            }
        }

        return true;
    }
}
