<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransaksiPenjualan extends Model
{
    protected $table = 'transaksi_penjualan';

    protected $fillable = [
        'ref_transaksi', 'tanggal_waktu', 'customer_id', 'jenis_transaksi',
        'tipe_pembayaran', 'sumber', 'status', 'batch_generate_id', 'total', 'diedit_at',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_waktu' => 'datetime',
            'diedit_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(TransaksiPenjualanItem::class);
    }

    public function batchGenerate()
    {
        return $this->belongsTo(BatchGenerate::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isRekonstruksi(): bool
    {
        return $this->sumber === 'rekonstruksi';
    }
}
