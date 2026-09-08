<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkBayarHutang extends Model
{
    protected $table = 'bulk_bayar_hutang';

    protected $fillable = [
        'uuid', 'supplier_id', 'tanggal_bayar', 'nominal_bayar',
        'dialokasi', 'kembalian', 'bukti_url', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_bayar' => 'date',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Baris pembayaran satu paket bulk (masih ada; yang dihapus satuan tak ikut). */
    public function pembayaran()
    {
        return $this->hasMany(PembayaranHutang::class, 'grup_bulk', 'uuid');
    }
}
