<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokoSetting extends Model
{
    protected $table = 'toko_settings';

    protected $fillable = [
        'nama_toko', 'alamat', 'telepon', 'npwp', 'logo',
        'margin_default', 'jam_buka', 'jam_tutup', 'hari_libur',
        'ambang_stok_menipis', 'parameter_generate', 'template_version',
    ];

    protected function casts(): array
    {
        return [
            'hari_libur' => 'array',
            'parameter_generate' => 'array',
        ];
    }

    public static function utama(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'nama_toko' => 'Bengkel Saya',
            'alamat' => null,
            'telepon' => null,
            'npwp' => '',
            'margin_default' => 20,
            'jam_buka' => '08:00',
            'jam_tutup' => '17:00',
            'hari_libur' => ['dates' => [], 'weekly' => []],
            'ambang_stok_menipis' => 3,
            'parameter_generate' => [
                'nota_min' => 1,
                'nota_max' => 8,
                'toleransi_persen' => 2,
                'variasi_min' => -10,
                'variasi_max' => 0,
                'maks_qty_item_per_nota' => 3,
                'excluded_item_ids' => [],
            ],
            'template_version' => 'v1',
        ]);
    }

    public function parameterGenerate(): array
    {
        return array_merge([
            'nota_min' => 1,
            'nota_max' => 8,
            'toleransi_persen' => 2,
            'variasi_min' => -10,
            'variasi_max' => 0,
            'maks_qty_item_per_nota' => 3,
            'excluded_item_ids' => [],
        ], $this->parameter_generate ?? []);
    }
}
