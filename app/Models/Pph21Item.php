<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pph21Item extends Model
{
    protected $table = 'pph21_items';

    protected $fillable = [
        'periode_id', 'karyawan_id',
        'status_pegawai', 'nik', 'passport', 'ptkp', 'posisi',
        'sertifikat', 'kode_objek', 'id_tku',
        'gross', 'rate', 'is_rate_manual',
    ];

    protected function casts(): array
    {
        return [
            'gross' => 'integer',
            'rate' => 'decimal:2',
            'is_rate_manual' => 'boolean',
        ];
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Pph21Periode::class, 'periode_id');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_id');
    }
}
