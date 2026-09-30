<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Barang extends Model
{
    protected $table = 'barang';

    protected $fillable = [
        'tipe_item', 'kode_barang', 'nama', 'deskripsi', 'kategori_id',
        'merk', 'varian', 'ukuran_ban',
        'satuan', 'sn_atau_non_sn', 'harga_beli_terakhir', 'margin_override',
        'mode_harga_jual', 'harga_jual', 'stock', 'lokasi_rak', 'status',
    ];

    /** Label merk utk grouping Rekap Stok (NULL/'' → 'Tanpa Merk'). */
    public function labelMerk(): string
    {
        $merk = trim((string) $this->merk);

        return $merk !== '' ? $merk : 'Tanpa Merk';
    }

    public function kategori()
    {
        return $this->belongsTo(KategoriBarang::class, 'kategori_id');
    }

    public function historiHarga()
    {
        return $this->hasMany(HistoriHarga::class);
    }

    public function referensiSn()
    {
        return $this->hasMany(ReferensiSn::class);
    }

    public function kartuStok()
    {
        return $this->hasMany(KartuStok::class)->orderBy('tanggal');
    }

    public function isSn(): bool
    {
        return $this->sn_atau_non_sn === 'SN';
    }

    public function isModeOtomatis(): bool
    {
        return $this->mode_harga_jual === 'otomatis';
    }

    /**
     * Apakah barang sudah pernah dipakai di transaksi (penjualan/pembelian)?
     */
    public function sudahDipakaiTransaksi(): bool
    {
        return $this->invoicePembelianItems()->exists()
            || $this->transaksiPenjualanItems()->exists();
    }

    public function invoicePembelianItems()
    {
        return $this->hasMany(InvoicePembelianItem::class);
    }

    public function transaksiPenjualanItems()
    {
        return $this->hasMany(TransaksiPenjualanItem::class);
    }
}
