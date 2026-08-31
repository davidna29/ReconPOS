<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembayaranHutang extends Model
{
    protected $table = 'pembayaran_hutang';

    protected $fillable = ['invoice_pembelian_id', 'tanggal_bayar', 'nominal', 'catatan'];

    protected function casts(): array
    {
        return [
            'tanggal_bayar' => 'date',
        ];
    }

    public function invoicePembelian()
    {
        return $this->belongsTo(InvoicePembelian::class);
    }
}
