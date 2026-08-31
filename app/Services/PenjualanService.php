<?php

namespace App\Services;

use App\Enums\JenisMutasiStok;
use App\Models\Barang;
use App\Models\BatchGenerate;
use App\Models\ReferensiSn;
use App\Models\TransaksiPenjualan;
use App\Models\TransaksiPenjualanItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Penjualan (manual kasir + commit draft rekonstruksi) & efeknya ke stok/SN (PRD 7, 8).
 */
class PenjualanService
{
    public function __construct(private StockService $stock) {}

    public function buatNoRef(Carbon $tgl, string $prefix = 'TRX'): string
    {
        $prefix .= '-' . $tgl->format('Ymd') . '-';
        $last = TransaksiPenjualan::where('ref_transaksi', 'like', $prefix . '%')->max('ref_transaksi');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan penjualan manual (kasir-lite): langsung tersimpan, customer_id = 0 (PRD 4.2).
     * @param array $items [{barang_id, qty, harga_jual}]
     */
    public function simpanManual(Carbon $tanggal, array $items): TransaksiPenjualan
    {
        return DB::transaction(function () use ($tanggal, $items) {
            if (count($items) === 0) {
                throw new InvalidArgumentException('Minimal 1 item penjualan.');
            }

            $rows = [];
            $total = 0;
            $snReserve = [];
            foreach ($items as $it) {
                $barang = Barang::find($it['barang_id'] ?? 0);
                if (! $barang) {
                    throw new InvalidArgumentException('Barang tidak ditemukan.');
                }
                $qty = (int) ($it['qty'] ?? 0);
                $harga = (int) ($it['harga_jual'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Qty harus lebih dari 0.');
                }
                if ($harga <= 0) {
                    throw new InvalidArgumentException('Harga jual harus lebih dari 0.');
                }
                if ($barang->isSn() && $qty !== 1) {
                    throw new InvalidArgumentException('Barang ber-SN wajib qty = 1 per baris (' . $barang->nama . ').');
                }

                $saldo = $this->stock->saldoPada($barang, $tanggal);
                if ($qty > $saldo) {
                    throw new InvalidArgumentException(
                        'Stok ' . $barang->nama . ' tidak cukup pada tanggal tersebut (tersedia ' . $saldo . ', diminta ' . $qty . ').'
                    );
                }

                $noSn = null;
                if ($barang->isSn()) {
                    $sn = ReferensiSn::where('barang_id', $barang->id)
                        ->where('status', 'tersedia')
                        ->orderBy('id')
                        ->first();
                    if (! $sn) {
                        throw new InvalidArgumentException('Tidak ada NO_SN tersedia untuk ' . $barang->nama);
                    }
                    $noSn = $sn->no_sn;
                    $snReserve[] = $sn;
                }

                $sub = $qty * $harga;
                $total += $sub;
                $rows[] = compact('barang', 'qty', 'harga', 'sub', 'noSn');
            }

            $trx = TransaksiPenjualan::create([
                'ref_transaksi' => $this->buatNoRef($tanggal, 'TRX'),
                'tanggal_waktu' => $tanggal,
                'customer_id' => 0,
                'jenis_transaksi' => 'cash',
                'tipe_pembayaran' => 'cash',
                'sumber' => 'manual',
                'status' => 'tersimpan',
                'total' => $total,
            ]);

            foreach ($rows as $i => $r) {
                TransaksiPenjualanItem::create([
                    'transaksi_penjualan_id' => $trx->id,
                    'barang_id' => $r['barang']->id,
                    'qty' => $r['qty'],
                    'harga_jual' => $r['harga'],
                    'no_sn' => $r['noSn'],
                    'subtotal' => $r['sub'],
                ]);
                $this->stock->mutasi(
                    $r['barang'],
                    $tanggal,
                    JenisMutasiStok::KELUAR_PENJUALAN,
                    -$r['qty'],
                    null,
                    $trx->id,
                    'Penjualan manual ' . $trx->ref_transaksi,
                );
                if ($r['noSn'] !== null && isset($snReserve[$i])) {
                    $snReserve[$i]->update(['status' => 'terjual', 'transaksi_penjualan_id' => $trx->id]);
                }
            }

            return $trx->load(['items', 'items.barang']);
        });
    }

    /**
     * Commit draft rekonstruksi → tersimpan: potong stok & tandai SN terjual secara atomik (PRD 7 langkah 7-8).
     */
    public function terapkanDraftRekonstruksi(BatchGenerate $batch): void
    {
        DB::transaction(function () use ($batch) {
            // Diproses urut kronologis; mutasi batch sebelumnya SUDAH tercatat di kartu_stok,
            // sehingga saldoPada(trx) sudah mencakup konsumsi batch itu sendiri (tidak perlu dikurangi lg).
            $transaksi = $batch->transaksiPenjualan()
                ->where('status', 'draft')
                ->orderBy('tanggal_waktu')
                ->get();

            foreach ($transaksi as $trx) {
                foreach ($trx->items as $item) {
                    $barang = $item->barang;
                    $saldo = $this->stock->saldoPada($barang, $trx->tanggal_waktu);
                    if ($item->qty > $saldo) {
                        throw new InvalidArgumentException(
                            'Validasi stok saat simpan gagal untuk ' . $barang->nama
                            . ' (tersedia ' . $saldo . ', diminta ' . $item->qty . '). Generate ulang draft.'
                        );
                    }

                    $this->stock->mutasi(
                        $barang,
                        $trx->tanggal_waktu,
                        JenisMutasiStok::KELUAR_PENJUALAN,
                        -$item->qty,
                        null,
                        $trx->id,
                        'Penjualan ' . $trx->ref_transaksi . ' (rekonstruksi)',
                    );

                    if ($barang->isSn() && $item->no_sn) {
                        $sn = ReferensiSn::where('barang_id', $barang->id)
                            ->where('no_sn', $item->no_sn)
                            ->first();
                        if ($sn && $sn->status === 'tersedia') {
                            $sn->update(['status' => 'terjual', 'transaksi_penjualan_id' => $trx->id]);
                        }
                    }
                }

                $trx->update(['status' => 'tersimpan']);
            }

            $batch->update(['status' => 'tersimpan']);
        });
    }

    /** Hapus penjualan manual (reversibel). Penjualan rekonstruksi dihapus lewat batch draft. */
    public function hapus(TransaksiPenjualan $trx): void
    {
        if ($trx->sumber === 'rekonstruksi') {
            throw new InvalidArgumentException('Penjualan hasil generate dihapus lewat halaman Generate (batalkan draft).');
        }

        DB::transaction(function () use ($trx) {
            if ($trx->isDraft()) {
                $trx->items()->delete();
                $trx->delete();

                return;
            }

            // reversi SN
            foreach ($trx->items as $item) {
                if ($item->no_sn) {
                    ReferensiSn::where('barang_id', $item->barang_id)
                        ->where('no_sn', $item->no_sn)
                        ->where('transaksi_penjualan_id', $trx->id)
                        ->update(['status' => 'tersedia', 'transaksi_penjualan_id' => null]);
                }
            }

            $this->stock->batalEfekPenjualan($trx->id);
            $trx->items()->delete();
            $trx->delete();
        });
    }
}
