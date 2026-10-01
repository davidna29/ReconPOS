<?php

namespace App\Console\Commands;

use App\Http\Controllers\BebanHutangController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class CekAgregasiDb extends Command
{
    protected $signature = 'db:cek-agregasi';

    protected $description = 'Latihan query agregat yg sensitif ONLY_FULL_GROUP_BY (baca saja, tanpa tulis). Dipakai guard deploy sebelum artisan up.';

    public function handle(): int
    {
        try {
            [$cards, $grand] = BebanHutangController::rekap(new Request);
        } catch (\Throwable $e) {
            $this->error('GAGAL: ' . $e->getMessage());

            return 1;
        }

        $this->info(sprintf(
            'OK: %d kartu cabang, %d faktur, hutang Rp%s',
            count($cards),
            $grand['lembar'],
            number_format($grand['hutang'], 0, ',', '.')
        ));

        return 0;
    }
}
