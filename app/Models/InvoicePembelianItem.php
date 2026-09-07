<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoicePembelianItem extends Model
{
    protected $table = 'invoice_pembelian_item';

    protected $fillable = [
        'invoice_pembelian_id', 'barang_id', 'harga_beli', 'qty',
        'diskon_item', 'ppn', 'sub_total', 'no_sn',
    ];

    public function invoicePembelian()
    {
        return $this->belongsTo(InvoicePembelian::class);
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }
}
