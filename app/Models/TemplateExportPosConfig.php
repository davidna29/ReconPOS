<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateExportPosConfig extends Model
{
    protected $table = 'template_export_pos_config';

    protected $fillable = [
        'template_version', 'nama_kolom', 'urutan', 'wajib_opsional',
        'format', 'nilai_default', 'sumber_data',
    ];

    public static function aktif(string $version = 'v1')
    {
        return static::where('template_version', $version)->orderBy('urutan')->get();
    }
}
