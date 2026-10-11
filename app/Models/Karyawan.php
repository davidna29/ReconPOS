<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Karyawan extends Model
{
    protected $table = 'karyawans';

    protected $fillable = [
        'nama', 'nik', 'status_pegawai', 'passport', 'ptkp', 'posisi', 'cabang',
        'sertifikat', 'kode_objek', 'id_tku',
        'link_ktp', 'link_kk', 'link_berkas_1', 'link_berkas_2', 'link_berkas_3',
        'is_aktif',
    ];

    /** Saran cabang utk datalist/dropdown (teks bebas, boleh bertambah tanpa migrasi). */
    public const CABANG_SARAN = [
        'TERMINAL BAN 1',
        'TERMINAL BAN 2',
        'TERMINAL BAN 3',
    ];

    protected function casts(): array
    {
        return ['is_aktif' => 'boolean'];
    }

    /** Label cabang utk tampilan/filter internal (NULL/'' → '-'). */
    public function labelCabang(): string
    {
        $cabang = trim((string) $this->cabang);

        return $cabang !== '' ? $cabang : '-';
    }

    public function pph21Items(): HasMany
    {
        return $this->hasMany(Pph21Item::class, 'karyawan_id');
    }

    public function scopeAktif($query)
    {
        return $query->where('is_aktif', true);
    }
}
