<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KartuStok extends Model
{
    protected $table = 'kartu_stok';

    protected $fillable = [
        'barang_id', 'tanggal', 'jenis', 'qty',
        'invoice_pembelian_id', 'transaksi_penjualan_id', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'datetime',
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

    public function transaksiPenjualan()
    {
        return $this->belongsTo(TransaksiPenjualan::class, 'transaksi_penjualan_id');
    }
}
