<?php

namespace App\Services;

use App\Enums\JenisMutasiStok;
use App\Models\Barang;
use App\Models\KartuStok;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Semua mutasi kartu stok + saldo berjalan (PRD 4, 4.3b, 5.4, 5.5).
 * Barang.stock selalu dijaga sinkron sebagai saldo hidup.
 */
class StockService
{
    public function mutasi(
        Barang $barang,
        Carbon $tanggal,
        JenisMutasiStok $jenis,
        int $qty,
        ?int $invoiceId = null,
        ?int $penjualanId = null,
        ?string $keterangan = null,
    ): KartuStok {
        $row = KartuStok::create([
            'barang_id' => $barang->id,
            'tanggal' => $tanggal,
            'jenis' => $jenis->value,
            'qty' => $qty,
            'invoice_pembelian_id' => $invoiceId,
            'transaksi_penjualan_id' => $penjualanId,
            'keterangan' => $keterangan,
        ]);

        $barang->stock += $qty;
        $barang->save();

        return $row;
    }

    public function stokAwal(Barang $barang, int $qty, ?string $keterangan = null): KartuStok
    {
        if ($qty < 0) {
            throw new InvalidArgumentException('Stok awal tidak boleh negatif.');
        }

        return $this->mutasi($barang, now(), JenisMutasiStok::STOK_AWAL, $qty, null, null, $keterangan ?? 'Stok awal');
    }

    /**
     * Opname: selisih = stok fisik - stok sistem. Wajib alasan (PRD 4.3b).
     */
    public function opname(Barang $barang, int $stokFisik, string $alasan): KartuStok
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new InvalidArgumentException('Alasan opname wajib diisi.');
        }

        $selisih = $stokFisik - $barang->stock;
        if ($selisih === 0) {
            throw new InvalidArgumentException('Stok fisik sama dengan stok sistem — tidak ada penyesuaian.');
        }

        return $this->mutasi($barang, now(), JenisMutasiStok::OPNAME, $selisih, null, null, 'Opname: ' . $alasan);
    }

    /** Saldo per barang pada tanggal/waktu tertentu (events <= batas). */
    public function saldoPada(Barang $barang, Carbon $batas): int
    {
        return (int) KartuStok::where('barang_id', $barang->id)
            ->where('tanggal', '<=', $batas)
            ->sum('qty');
    }

    /** Total qty terjual (keluar_penjualan) sejak tanggal tertentu — untuk validasi 5.5a. */
    public function terjualSejak(int $barangId, Carbon $sejak): int
    {
        return (int) KartuStok::where('barang_id', $barangId)
            ->where('jenis', 'keluar_penjualan')
            ->where('tanggal', '>=', $sejak)
            ->sum('qty') * -1;
    }

    /** Batalkan efek invoice pembelian (hapus kartu stok & kurangi stok di tabel barang). */
    public function batalEfekPembelian(int $invoiceId): void
    {
        $rows = KartuStok::where('invoice_pembelian_id', $invoiceId)->get();
        foreach ($rows as $row) {
            $barang = Barang::find($row->barang_id);
            if ($barang) {
                $barang->stock -= $row->qty;
                $barang->save();
            }
            $row->delete();
        }
    }

    /** Batalkan efek penjualan tersimpan (hapus kartu stok & kembalikan stok). */
    public function batalEfekPenjualan(int $transaksiId): void
    {
        $rows = KartuStok::where('transaksi_penjualan_id', $transaksiId)->get();
        foreach ($rows as $row) {
            $barang = Barang::find($row->barang_id);
            if ($barang) {
                $barang->stock -= $row->qty; // qty negatif (keluar) → menambah stok
                $barang->save();
            }
            $row->delete();
        }
    }
}
