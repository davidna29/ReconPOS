<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'supplier';

    protected $fillable = ['nama', 'kontak', 'email', 'alamat', 'catatan'];

    public function invoicePembelian()
    {
        return $this->hasMany(InvoicePembelian::class);
    }

    public function totalHutangBerjalan(): int
    {
        return (int) $this->invoicePembelian()
            ->where('status', 'belum_lunas')
            ->sum('sisa_hutang');
    }
}
