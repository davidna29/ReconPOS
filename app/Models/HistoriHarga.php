<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoriHarga extends Model
{
    protected $table = 'histori_harga';

    protected $fillable = [
        'barang_id', 'harga_beli', 'margin_dipakai', 'harga_jual_efektif',
        'tanggal_berlaku', 'invoice_pembelian_id',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_berlaku' => 'datetime',
        ];
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }

    public function invoicePembelian()
    {
        return $this->belongsTo(InvoicePembelian::class, 'invoice_pembelian_id');
    }
}
