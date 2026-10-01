<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Menjalankan app:kirim-hosting sebagai proses background terpisah.
 * Diekstrak agar bisa di-mock di test (Process::start via facade tak ikut fake).
 */
class DeployRunner
{
    public function luncurkan(array $args): void
    {
        Process::path(base_path())->start(array_merge([PHP_BINARY, base_path() . '/artisan', 'app:kirim-hosting'], $args));
    }
}
