<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransaksiPenjualanItem extends Model
{
    protected $table = 'transaksi_penjualan_item';

    protected $fillable = [
        'transaksi_penjualan_id', 'barang_id', 'qty', 'harga_jual', 'no_sn', 'subtotal',
    ];

    public function transaksiPenjualan()
    {
        return $this->belongsTo(TransaksiPenjualan::class);
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }
}
