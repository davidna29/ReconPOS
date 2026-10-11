<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pph21Periode extends Model
{
    protected $table = 'pph21_periodes';

    protected $fillable = ['masa', 'tahun', 'tgl_pemotongan', 'npwp_pemotong'];

    protected function casts(): array
    {
        return [
            'masa' => 'integer',
            'tahun' => 'integer',
            'tgl_pemotongan' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Pph21Item::class, 'periode_id');
    }

    public function label(): string
    {
        return sprintf('%02d/%d', $this->masa, $this->tahun);
    }
}
