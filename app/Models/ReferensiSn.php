<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferensiSn extends Model
{
    protected $table = 'referensi_sn';

    protected $fillable = [
        'barang_id', 'no_sn', 'status', 'invoice_pembelian_id', 'transaksi_penjualan_id',
    ];

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }

    public function invoicePembelian()
    {
        return $this->belongsTo(InvoicePembelian::class, 'invoice_pembelian_id');
    }

    public function transaksiPenjualan()
    {
        return $this->belongsTo(TransaksiPenjualan::class, 'transaksi_penjualan_id');
    }
}
