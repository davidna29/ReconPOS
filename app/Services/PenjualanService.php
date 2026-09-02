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
    /**
     * Edit nota penjualan (manual / rekonstruksi tersimpan) — atomik:
     * batal efek lama -> terapkan efek baru (stok & NO_SN direkonsiliasi).
     * Konsekuensi (keputusan produk): nota yang diedit menandai diedit_at,
     * sehingga batch rekonstruksi terkunci (tidak bisa di-undo lagi).
     *
     * @param array $items [{barang_id, qty, harga_jual}]
     */
    public function edit(TransaksiPenjualan $trx, Carbon $tanggal, array $items): TransaksiPenjualan
    {
        if ($trx->status !== 'tersimpan') {
            throw new InvalidArgumentException('Hanya nota berstatus tersimpan yang bisa diedit.');
        }
        if (count($items) === 0) {
            throw new InvalidArgumentException('Minimal 1 item penjualan.');
        }

        return DB::transaction(function () use ($trx, $tanggal, $items) {
            // siapkan baris valid & hitung total
            $rows = [];
            $total = 0;
            $barangLama = $trx->items->keyBy('barang_id');
            foreach ($items as $it) {
                $barang = Barang::find($it['barang_id'] ?? 0);
                if (! $barang) {
                    throw new InvalidArgumentException('Barang tidak ditemukan.');
                }
                $qty = (int) ($it['qty'] ?? 0);
                $harga = (int) ($it['harga_jual'] ?? 0);
                if ($qty <= 0 || $harga <= 0) {
                    throw new InvalidArgumentException('Qty dan harga harus lebih dari 0 untuk ' . $barang->nama . '.');
                }
                if ($barang->isSn() && $qty !== 1) {
                    throw new InvalidArgumentException('Barang ber-SN wajib qty = 1 per baris (' . $barang->nama . ').');
                }
                $rows[] = compact('barang', 'qty', 'harga');
                $total += $qty * $harga;
            }

            // cek stok SETELAH reversal (saldo lama + qty nota ini dikembalikan)
            foreach ($rows as $r) {
                $qtyLama = (int) ($barangLama[$r['barang']->id]->qty ?? 0);
                $tersedia = $this->stock->saldoPada($r['barang'], $tanggal) + $qtyLama;
                if ($r['qty'] > $tersedia) {
                    throw new InvalidArgumentException(
                        'Stok ' . $r['barang']->nama . ' pada tanggal tersebut hanya '
                        . max(0, $tersedia - $qtyLama) . ' unit tersedia (diminta ' . $r['qty'] . ').'
                    );
                }
            }

            // 1) batal efek lama: stok, kartu, NO_SN
            $this->stock->batalEfekPenjualan($trx->id);
            foreach ($trx->items as $oldItem) {
                if ($oldItem->no_sn) {
                    ReferensiSn::where('barang_id', $oldItem->barang_id)
                        ->where('no_sn', $oldItem->no_sn)
                        ->where('transaksi_penjualan_id', $trx->id)
                        ->update(['status' => 'tersedia', 'transaksi_penjualan_id' => null]);
                }
            }
            $trx->items()->delete();

            // 2) terapkan efek baru
            $snCadangan = []; // sn utk baris SN baru
            foreach ($rows as $r) {
                // refresh instance barang — stok sudah berubah oleh reversal (hindari nilai stale)
                $r['barang'] = $r['barang']->fresh();
                $noSn = null;
                if ($r['barang']->isSn()) {
                    $lama = $barangLama[$r['barang']->id] ?? null;
                    if ($lama && $lama->no_sn && $r['qty'] === 1) {
                        $noSn = $lama->no_sn; // pertahankan NO_SN yg sama
                    } else {
                        if (! isset($snCadangan[$r['barang']->id])) {
                            $snCadangan[$r['barang']->id] = ReferensiSn::where('barang_id', $r['barang']->id)
                                ->where('status', 'tersedia')->whereNull('transaksi_penjualan_id')
                                ->orderBy('id')->pluck('no_sn')->all();
                        }
                        $noSn = array_shift($snCadangan[$r['barang']->id]);
                        if ($noSn === null) {
                            throw new InvalidArgumentException('Tidak ada NO_SN tersedia untuk ' . $r['barang']->nama);
                        }
                    }
                }

                TransaksiPenjualanItem::create([
                    'transaksi_penjualan_id' => $trx->id,
                    'barang_id' => $r['barang']->id,
                    'qty' => $r['qty'],
                    'harga_jual' => $r['harga'],
                    'no_sn' => $noSn,
                    'subtotal' => $r['qty'] * $r['harga'],
                ]);

                $this->stock->mutasi(
                    $r['barang'],
                    $tanggal,
                    JenisMutasiStok::KELUAR_PENJUALAN,
                    -$r['qty'],
                    null,
                    $trx->id,
                    'Penjualan ' . $trx->ref_transaksi . ($trx->sumber === 'rekonstruksi' ? ' (rekonstruksi)' : ''),
                );

                if ($noSn !== null) {
                    ReferensiSn::where('barang_id', $r['barang']->id)
                        ->where('no_sn', $noSn)
                        ->update(['status' => 'terjual', 'transaksi_penjualan_id' => $trx->id]);
                }
            }

            // 3) finalisasi nota + rekonsiliasi batch (rekonstruksi)
            $trx->update([
                'tanggal_waktu' => $tanggal,
                'total' => $total,
                'diedit_at' => now(),
            ]);

            if ($trx->sumber === 'rekonstruksi' && $trx->batch_generate_id) {
                $batch = $trx->batchGenerate;
                if ($batch) {
                    $batch->total_generate = (int) $batch->transaksiPenjualan()->sum('total');
                    $batch->selisih = $batch->total_generate - $batch->target_omset;
                    $batch->save();
                }
            }

            return $trx->fresh(['items', 'items.barang']);
        });
    }

    public function hapus(TransaksiPenjualan $trx): void
    {
        // rekonstruksi belum diedit → harus lewat undo batch; sudah diedit → boleh hapus langsung
        if ($trx->sumber === 'rekonstruksi' && ! $trx->diedit_at) {
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
