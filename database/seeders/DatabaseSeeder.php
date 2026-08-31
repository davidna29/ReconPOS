<?php

namespace Database\Seeders;

use App\Models\TemplateExportPosConfig;
use App\Models\TokoSetting;
use App\Models\User;
use App\Services\ExportPosService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1 akun admin (PRD 1.2a / 9) — tanpa registrasi publik
        $admin = User::firstOrCreate(
            ['email' => 'admin@gentot.local'],
            ['name' => 'Admin Toko', 'password' => 'gentot2026'],
        );
        $this->command?->info('Admin: admin@gentot.local / gentot2026');

        // 1 baris konfigurasi toko (PRD 4.5)
        $settings = TokoSetting::utama();
        $settings->update([
            'nama_toko' => 'Bengkel Saya',
            'alamat' => 'Jl. Raya No. 1, Jakarta',
            'telepon' => '021-555-0101',
        ]);
        $this->command?->info('Pengaturan toko tersedia (margin ' . $settings->margin_default . '%).');

        // Mapping kolom Template Export POS versi v1 (PRD 8b) — konfigurabel, bukan hardcode
        if (TemplateExportPosConfig::count() === 0) {
            foreach (ExportPosService::mappingDefault() as $m) {
                TemplateExportPosConfig::create($m + ['template_version' => 'v1']);
            }
            $this->command?->info('Template Export POS v1 (11 kolom) ter-seed.');
        }

        // Data demo bengkel (opsional — hapus baris ini bila ingin DB kosong)
        $this->call(DemoSeeder::class);
    }
}
