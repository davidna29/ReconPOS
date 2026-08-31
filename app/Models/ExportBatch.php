<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportBatch extends Model
{
    protected $table = 'export_batch';

    protected $fillable = [
        'format', 'filter_mulai', 'filter_selesai', 'filter_bulan',
        'jumlah_file', 'file_names', 'status_upload_manual',
    ];

    protected function casts(): array
    {
        return [
            'filter_mulai' => 'date',
            'filter_selesai' => 'date',
            'file_names' => 'array',
            'status_upload_manual' => 'boolean',
        ];
    }
}
