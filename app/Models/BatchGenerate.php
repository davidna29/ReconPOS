<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BatchGenerate extends Model
{
    protected $table = 'batch_generate';

    protected $fillable = [
        'periode_mulai', 'periode_selesai', 'target_omset', 'parameter_json',
        'status', 'total_generate', 'selisih', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'periode_mulai' => 'date',
            'periode_selesai' => 'date',
            'parameter_json' => 'array',
        ];
    }

    public function transaksiPenjualan()
    {
        return $this->hasMany(TransaksiPenjualan::class);
    }
}
