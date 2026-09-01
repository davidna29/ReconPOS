<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\HistoriHarga;
use App\Models\TokoSetting;
use Illuminate\Support\Carbon;

/**
 * Logika harga beli, margin & harga jual (PRD 4.1).
 */
class HargaService
{
    public function marginEfektif(Barang $barang, ?TokoSetting $settings = null): int
    {
        $settings ??= TokoSetting::utama();

        return $barang->margin_override ?? (int) $settings->margin_default;
    }

    public function hitungHargaJual(int $hargaBeli, int $marginPersen): int
    {
        return (int) round($hargaBeli * (1 + $marginPersen / 100));
    }

    /**
     * Catat pembelian ke histori harga & perbarui master:
     * - harga_beli_terakhir selalu diperbarui
     * - harga_jual hanya dihitung ulang bila mode = otomatis (PRD 4.1c)
     */
    public function catatPembelian(Barang $barang, int $hargaBeli, Carbon $tanggal, ?int $invoiceId = null): HistoriHarga
    {
        $margin = $this->marginEfektif($barang);
        $hargaJual = $this->hitungHargaJual($hargaBeli, $margin);

        // Harga jual efektif yang berlakus utk tanggal ini:
        // - mode otomatis      : hitung dari margin (transaksi lama mengikuti batch saat itu)
        // - mode manual_terkunci: pakai harga jual terkunci user; bila 0, fallback hitung margin
        $hargaJualEfektif = $barang->isModeOtomatis()
            ? $hargaJual
            : (((int) $barang->harga_jual > 0) ? (int) $barang->harga_jual : $hargaJual);

        $hist = HistoriHarga::create([
            'barang_id' => $barang->id,
            'harga_beli' => $hargaBeli,
            'margin_dipakai' => $margin,
            'harga_jual_efektif' => $hargaJualEfektif,
            'tanggal_berlaku' => $tanggal,
            'invoice_pembelian_id' => $invoiceId,
        ]);

        $barang->harga_beli_terakhir = $hargaBeli;
        if ($barang->isModeOtomatis()) {
            $barang->harga_jual = $hargaJual;
        }
        $barang->save();

        return $hist;
    }

    /** Hapus histori harga milik sebuah invoice (dipakai saat edit/hapus pembelian). */
    public function batalHistoriInvoice(int $invoiceId): void
    {
        HistoriHarga::where('invoice_pembelian_id', $invoiceId)->delete();
    }

    /**
     * Harga jual efektif yang berlaku pada tanggal tertentu (dari histori),
     * fallback ke harga master bila belum ada histori. (PRD 4.1c)
     */
    /**
     * Cache per request untuk lookup histori harga (pola generate memanggil ribuan kali).
     */
    private static array $cacheHarga = [];

    public static function resetCache(): void
    {
        self::$cacheHarga = [];
    }

    public function hargaJualEfektifPada(Barang $barang, Carbon $tanggal): int
    {
        [$jual] = $this->hargaEfektifPada($barang, $tanggal);

        return $jual;
    }

    /**
     * Pasangan (harga_jual_efektif, harga_beli) yang berlaku pada tanggal tertentu.
     * Dipakai generate/export agar harga sesuai kondisi tanggal tsb.
     */
    public function hargaEfektifPada(Barang $barang, Carbon $tanggal): array
    {
        $key = $barang->id . '|' . $tanggal->toDateString();
        if (isset(self::$cacheHarga[$key])) {
            return self::$cacheHarga[$key];
        }

        $hist = HistoriHarga::where('barang_id', $barang->id)
            ->where('tanggal_berlaku', '<=', $tanggal->copy()->endOfDay())
            ->orderByDesc('tanggal_berlaku')
            ->first();

        $hasil = $hist
            ? [(int) $hist->harga_jual_efektif, (int) $hist->harga_beli]
            : [(int) $barang->harga_jual, (int) $barang->harga_beli_terakhir];

        return self::$cacheHarga[$key] = $hasil;
    }

    /** Saat toggle ke mode otomatis: hitung ulang harga jual seketika (PRD 4.1c). */
    public function toggleKeOtomatis(Barang $barang): void
    {
        $barang->mode_harga_jual = 'otomatis';
        if ($barang->harga_beli_terakhir > 0) {
            $barang->harga_jual = $this->hitungHargaJual($barang->harga_beli_terakhir, $this->marginEfektif($barang));
        }
        $barang->save();
    }
}
