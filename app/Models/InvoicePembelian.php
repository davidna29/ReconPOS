<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoicePembelian extends Model
{
    protected $table = 'invoice_pembelian';

    protected $fillable = [
        'no_invoice', 'supplier_id', 'tanggal_transaksi', 'jenis',
        'diskon_total', 'ppn', 'subtotal', 'total_setelah_diskon',
        'bayar_atau_dp', 'jatuh_tempo', 'sisa_hutang', 'status', 'sumber', 'cabang',
    ];

    /** Saran cabang utk datalist/dropdown (teks bebas, boleh bertambah tanpa migrasi). */
    public const CABANG_SARAN = [
        'TERMINAL BAN 1',
        'TERMINAL BAN 2',
        'TERMINAL BAN 3',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_transaksi' => 'datetime',
            'jatuh_tempo' => 'date',
            'ppn' => 'integer',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(InvoicePembelianItem::class);
    }

    public function pembayaranHutang()
    {
        return $this->hasMany(PembayaranHutang::class)->orderBy('tanggal_bayar');
    }

    public function isHutang(): bool
    {
        return $this->jenis === 'hutang';
    }

    public function sudahLunas(): bool
    {
        return $this->status === 'lunas';
    }

    /** Label cabang utk grouping Rekap Beban Hutang (NULL/'' → 'Belum Dibebani'). */
    public function labelCabang(): string
    {
        $cabang = trim((string) $this->cabang);

        return $cabang !== '' ? $cabang : 'Belum Dibebani';
    }
}
