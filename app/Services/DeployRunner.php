<?php

namespace App\Services;

/**
 * Menjalankan app:kirim-hosting sebagai proses background terpisah.
 *
 * PENTING: JANGAN pakai Process::start() tanpa wait() utk ini — destructor
 * Symfony Process memanggil stop(0) saat objek dibuang di akhir request,
 * sehingga anak terbunuh sebelum sempat menulis log (insiden Okt 2026).
 * Sebagai gantinya spawn detached via nohup ... & (anak milik OS).
 */
class DeployRunner
{
    public function luncurkan(array $args): void
    {
        if (! function_exists('exec')) {
            throw new \RuntimeException('Fungsi exec() dimatikan di PHP ini — deploy background tak bisa jalan.');
        }
        exec(self::perintahDetached($args), $out);
    }

    /** String perintah detached (pure, testable — tanpa eksekusi). */
    public static function perintahDetached(array $args): string
    {
        $log = (string) config('deploy.log');
        $bagian = array_map(fn ($a) => escapeshellarg($a), $args);

        return 'cd ' . escapeshellarg(base_path())
            . ' && nohup ' . escapeshellarg(PHP_BINARY) . ' artisan app:kirim-hosting'
            . ($bagian ? ' ' . implode(' ', $bagian) : '')
            . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
    }
}
