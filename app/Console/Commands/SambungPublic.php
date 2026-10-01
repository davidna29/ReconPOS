<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Sambungkan docroot (public_html) ke proyek Laravel (remote maupun lokal).
 * Menyalin .htaccess + build/, menulis index.php dgn path prefix yg dihitung
 * dari posisi relatif docroot <-> proyek (mendukung dua layout Hostinger).
 */
class SambungPublic extends Command
{
    protected $signature = 'app:sambung-public {--docroot= : path document root (default: auto dari posisi proyek)}';
    protected $description = 'Tulis ulang index.php docroot + salin .htaccess/build dari folder public/';

    public function handle(): int
    {
        $project = base_path();
        $docroot = (string) ($this->option('docroot') ?: self::docrootOtomatis($project));
        if (! is_dir($docroot) && ! mkdir($docroot, 0775, true)) {
            $this->error('Docroot tidak ada dan gagal dibuat: ' . $docroot);

            return 1;
        }

        $prefix = self::prefixRelatif($docroot, $project);
        $this->info('Project : ' . $project);
        $this->info('Docroot : ' . $docroot);
        $this->info('Prefix  : ' . $prefix);

        // .htaccess standar Laravel
        copy($project . '/public/.htaccess', $docroot . '/.htaccess');

        // build/ segar (hapus sisa hash lama dulu)
        $buildTujuan = $docroot . '/build';
        if (is_dir($buildTujuan)) {
            File::deleteDirectory($buildTujuan);
        }
        File::copyDirectory($project . '/public/build', $buildTujuan);

        file_put_contents($docroot . '/index.php', self::templateIndex($prefix));
        $this->info('index.php + .htaccess + build/ tersambung.');

        return 0;
    }

    /** Tebak docroot dari posisi proyek (murni string, testable). */
    public static function docrootOtomatis(string $project): string
    {
        $project = rtrim(str_replace('\\', '/', $project), '/');
        if (str_contains($project, '/public_html/')) {
            return substr($project, 0, strpos($project, '/public_html/') + strlen('/public_html'));
        }

        return dirname($project) . '/public_html';
    }

    /**
     * Prefix path dari docroot ke proyek utk 3 baris index.php.
     * - Proyek sejajar docroot  => '../reconpos'
     * - Proyek di dalam docroot => '/reconpos'
     */
    public static function prefixRelatif(string $docroot, string $project): string
    {
        $doc = explode('/', trim(str_replace('\\', '/', $docroot), '/'));
        $pro = explode('/', trim(str_replace('\\', '/', $project), '/'));

        $sama = 0;
        while (isset($doc[$sama], $pro[$sama]) && $doc[$sama] === $pro[$sama]) {
            $sama++;
        }
        $naik = array_fill(0, count($doc) - $sama, '..');
        $turun = array_slice($pro, $sama);
        $rel = implode('/', array_merge($naik, $turun));

        return $rel === '' ? '.' : $rel;
    }

    public static function templateIndex(string $prefix): string
    {
        return <<<PHP
            <?php

            use Illuminate\Foundation\Application;
            use Illuminate\Http\Request;

            define('LARAVEL_START', microtime(true));

            // Ditulis oleh php artisan app:sambung-public (prefix: {$prefix})
            if (file_exists(\$maintenance = __DIR__.'/$prefix/storage/framework/maintenance.php')) {
                require \$maintenance;
            }

            require __DIR__.'/$prefix/vendor/autoload.php';

            /** @var Application \$app */
            \$app = require_once __DIR__.'/$prefix/bootstrap/app.php';

            \$app->handleRequest(Request::capture());

            PHP;
    }
}
