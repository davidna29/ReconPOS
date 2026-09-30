<?php

namespace App\Services;

use App\Enums\JenisMutasiStok;
use App\Exceptions\StokException;
use App\Models\Barang;
use App\Models\BulkBayarHutang;
use App\Models\InvoicePembelian;
use App\Models\InvoicePembelianItem;
use App\Models\PembayaranHutang;
use App\Models\ReferensiSn;
use App\Models\TokoSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Transaksi pembelian (Cash/Hutang), impor Excel, pelunasan, edit & hapus (PRD 5).
 */
class PembelianService
{
    public function __construct(
        private HargaService $harga,
        private StockService $stock,
    ) {}

    public function buatNoInvoice(Carbon $tgl): string
    {
        $prefix = 'PB-' . $tgl->format('Ymd') . '-';
        $last = InvoicePembelian::where('no_invoice', 'like', $prefix . '%')->max('no_invoice');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function defaultJatuhTempo(Carbon $tglTransaksi): Carbon
    {
        return $tglTransaksi->copy()->addMonths(3)->endOfMonth();
    }

    /**
     * PPN otomatis invoice-level: round(DPP × tarif/100), DPP = subtotal − diskon_total.
     * Tarif default dari toko_settings.ppn_persen (fallback 11 bila kolom belum ada).
     */
    public static function hitungPpnOtomatis(int $dpp, ?int $persen = null): int
    {
        $tarif = $persen ?? (int) (TokoSetting::utama()->ppn_persen ?? 11);
        if ($dpp <= 0 || $tarif <= 0) {
            return 0;
        }

        return (int) round($dpp * $tarif / 100);
    }

    /**
     * @param array $data no_invoice?, supplier_id, tanggal_transaksi (Y-m-d H:i),
     *                    jenis (cash|hutang), diskon_total, bayar_atau_dp, jatuh_tempo?,
     *                    ppn? (int|null — null = hitung otomatis), ppn_otomatis? (bool),
     *                    items: [{barang_id, harga_beli, qty, diskon_item, no_sn?, ppn? (Rp per baris, Excel)}]
     * @param string $sumber manual | import_excel
     */
    public function simpan(array $data, string $sumber = 'manual'): InvoicePembelian
    {
        return DB::transaction(function () use ($data, $sumber) {
            $tgl = Carbon::parse($data['tanggal_transaksi']);
            $jenis = $data['jenis'] ?? 'cash';
            $noInvoice = trim($data['no_invoice'] ?? '') !== ''
                ? trim($data['no_invoice'])
                : $this->buatNoInvoice($tgl);

            if (InvoicePembelian::where('no_invoice', $noInvoice)->exists()) {
                throw new InvalidArgumentException('Nomor invoice "' . $noInvoice . '" sudah dipakai.');
            }

            $items = $data['items'] ?? [];
            if (count($items) === 0) {
                throw new InvalidArgumentException('Minimal harus ada 1 item pembelian.');
            }

            $subtotal = 0;
            $detail = [];
            foreach ($items as $it) {
                $barang = Barang::find($it['barang_id'] ?? 0);
                if (! $barang) {
                    throw new InvalidArgumentException('Barang tidak ditemukan.');
                }
                $qty = (int) ($it['qty'] ?? 0);
                $hargaBeli = (int) ($it['harga_beli'] ?? 0);
                $diskonItem = (int) ($it['diskon_item'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Qty harus lebih dari 0 untuk ' . $barang->nama . '.');
                }
                if ($hargaBeli <= 0) {
                    throw new InvalidArgumentException('Harga beli wajib diisi (lebih dari 0) untuk ' . $barang->nama . '.');
                }
                $sub = ($hargaBeli * $qty) - $diskonItem;
                if ($sub < 0) {
                    throw new InvalidArgumentException('Sub total item negatif untuk ' . $barang->nama . '.');
                }
                $subtotal += $sub;
                $ppnItem = max(0, (int) ($it['ppn'] ?? 0));
                $detail[] = [
                    'barang' => $barang,
                    'qty' => $qty,
                    'harga_beli' => $hargaBeli,
                    'diskon_item' => $diskonItem,
                    'sub' => $sub,
                    'no_sn' => $it['no_sn'] ?? null,
                    'ppn_item' => $ppnItem,
                ];
            }

            $diskonTotal = (int) ($data['diskon_total'] ?? 0);
            if ($diskonTotal > $subtotal) {
                throw new InvalidArgumentException('Diskon tidak boleh melebihi subtotal.');
            }
            $total = $subtotal - $diskonTotal;

            // PPN: prioritas (1) override per-SKU dari Excel (Σ PPN baris, baris kosong = 0),
            // (2) otomatis round(DPP × tarif) bila ppn_otomatis / ppn null (default form),
            // (3) override manual invoice-level (koreksi e-Faktur).
            $adaPpnItem = collect($detail)->contains(fn ($d) => $d['ppn_item'] > 0);
            if ($adaPpnItem) {
                $ppn = (int) collect($detail)->sum(fn ($d) => $d['ppn_item']);
            } elseif (! empty($data['ppn_otomatis']) || (array_key_exists('ppn', $data) && $data['ppn'] === null)) {
                $ppn = self::hitungPpnOtomatis($total);
            } else {
                $ppn = max(0, (int) ($data['ppn'] ?? 0));
            }

            $bayarDp = (int) ($data['bayar_atau_dp'] ?? 0);
            $isHutang = $jenis === 'hutang';

            // Sisa Hutang = (Subtotal - Diskon) + PPN - DP
            $totalBayar = $total + $ppn;

            if (! $isHutang && $bayarDp < $totalBayar) {
                throw new InvalidArgumentException('Pembayaran Cash minimal harus sama dengan total + PPN (' . uang($totalBayar) . ').');
            }

            $sisa = $isHutang ? max(0, $totalBayar - $bayarDp) : 0;
            $status = ($isHutang && $sisa > 0) ? 'belum_lunas' : 'lunas';
            $jatuhTempo = null;
            if ($isHutang) {
                $jatuhTempo = ! empty($data['jatuh_tempo'])
                    ? Carbon::parse($data['jatuh_tempo'])
                    : $this->defaultJatuhTempo($tgl);
            }

            $invoice = InvoicePembelian::create([
                'no_invoice' => $noInvoice,
                'supplier_id' => (int) $data['supplier_id'],
                'tanggal_transaksi' => $tgl,
                'jenis' => $jenis,
                'diskon_total' => $diskonTotal,
                'ppn' => $ppn,
                'subtotal' => $subtotal,
                'total_setelah_diskon' => $total,
                'bayar_atau_dp' => $bayarDp,
                'jatuh_tempo' => $jatuhTempo,
                'sisa_hutang' => $sisa,
                'status' => $status,
                'sumber' => $sumber,
            ]);

            foreach ($detail as $d) {
                InvoicePembelianItem::create([
                    'invoice_pembelian_id' => $invoice->id,
                    'barang_id' => $d['barang']->id,
                    'harga_beli' => $d['harga_beli'],
                    'qty' => $d['qty'],
                    'diskon_item' => $d['diskon_item'],
                    'ppn' => $d['ppn_item'],
                    'sub_total' => $d['sub'],
                    'no_sn' => $d['no_sn'],
                ]);

                // 5.4: tambah stok + histori harga
                $this->stock->mutasi(
                    $d['barang'],
                    $tgl,
                    JenisMutasiStok::MASUK_PEMBELIAN,
                    $d['qty'],
                    $invoice->id,
                    null,
                    'Pembelian ' . $noInvoice,
                );
                // Harga beli EFEKTIF utk HPP/histori/margin = neto + PPN.
                // Override per-SKU (Excel): langsung per baris (ppn_baris/qty).
                // Selain itu: alokasi PPN total proporsional per nilai baris.
                // Item & subtotal invoice tetap neto.
                $hargaBeliHistori = $adaPpnItem
                    ? $d['harga_beli'] + (int) round($d['ppn_item'] / max(1, $d['qty']))
                    : $this->hargaBeliEfektif($d['harga_beli'], $d['qty'], $d['sub'], $subtotal, $ppn);
                $this->harga->catatPembelian($d['barang'], $hargaBeliHistori, $tgl, $invoice->id);

                if ($d['barang']->isSn()) {
                    $this->daftarkanSn($d['barang'], $d['qty'], $invoice->id, (string) $d['no_sn']);
                }
            }

            return $invoice->load(['items', 'supplier']);
        });
    }

    /**
     * Harga beli efektif per unit utk histori harga / HPP / margin:
     * neto + alokasi PPN total secara proporsional berdasarkan nilai baris (sub = neto×qty - diskon_item).
     * Item invoice & subtotal tetap memakai harga NETO; PPN tetap ditambahkan sekali di Sisa/Cash.
     */
    private function hargaBeliEfektif(int $hargaNeto, int $qty, int $sub, int $subtotal, int $ppn): int
    {
        if ($ppn <= 0 || $subtotal <= 0 || $qty <= 0) {
            return $hargaNeto;
        }
        $alokasi = (int) round($ppn * $sub / $subtotal);

        return $hargaNeto + (int) round($alokasi / max(1, $qty));
    }

    /** Daftarkan nomor SN dari pembelian barang ber-SN (PRD 4.2 / 5.1). */
    public function daftarkanSn(Barang $barang, int $qty, int $invoiceId, string $masukan): void
    {
        $serials = preg_split('/[\s,;]+/', trim($masukan), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $dipakai = ReferensiSn::where('barang_id', $barang->id)->pluck('no_sn')->all();
        $dipakai = array_flip($dipakai);
        $base = $barang->kode_barang . '-' . $invoiceId . '-';
        $suffix = 1;

        for ($i = 0; $i < $qty; $i++) {
            $sn = $serials[$i] ?? $base . str_pad((string) $suffix++, 3, '0', STR_PAD_LEFT);
            while (isset($dipakai[$sn])) {
                $sn = $base . str_pad((string) $suffix++, 3, '0', STR_PAD_LEFT);
            }
            $dipakai[$sn] = true;
            ReferensiSn::create([
                'barang_id' => $barang->id,
                'no_sn' => $sn,
                'status' => 'tersedia',
                'invoice_pembelian_id' => $invoiceId,
            ]);
        }
    }

    /** Pelunasan/cicilan hutang (PRD 5.2a). Invoice otomatis lunas bila sisa = 0. */
    public function pelunasan(InvoicePembelian $invoice, int $nominal, Carbon $tanggal, ?string $catatan = null): void
    {
        if (! $invoice->isHutang()) {
            throw new InvalidArgumentException('Invoice ini bukan transaksi hutang.');
        }
        if ($invoice->sudahLunas()) {
            throw new InvalidArgumentException('Invoice sudah lunas.');
        }
        if ($nominal <= 0 || $nominal > $invoice->sisa_hutang) {
            throw new InvalidArgumentException('Nominal pembayaran harus antara 1 dan sisa hutang (' . uang($invoice->sisa_hutang) . ').');
        }

        DB::transaction(function () use ($invoice, $nominal, $tanggal, $catatan) {
            $this->catatPembayaran($invoice->fresh(), $nominal, $tanggal, $catatan);
        });
    }

    /**
     * Inti pencatatan 1 pembayaran (tanpa transaksi sendiri) — dipakai
     * pelunasan() satuan & bayarBulk() (yang membungkus loop dalam 1 transaksi).
     */
    private function catatPembayaran(InvoicePembelian $invoice, int $nominal, Carbon $tanggal, ?string $catatan = null, ?string $grupBulk = null): PembayaranHutang
    {
        $bayar = PembayaranHutang::create([
            'invoice_pembelian_id' => $invoice->id,
            'tanggal_bayar' => $tanggal,
            'nominal' => $nominal,
            'catatan' => $catatan,
            'grup_bulk' => $grupBulk,
        ]);

        $invoice->sisa_hutang -= $nominal;
        if ($invoice->sisa_hutang <= 0) {
            $invoice->sisa_hutang = 0;
            $invoice->status = 'lunas';
        }
        $invoice->save();

        return $bayar;
    }

    /**
     * Simulasi alokasi bayar-bulk per supplier (read-only, tanpa menulis DB).
     * Urutan FIFO: tanggal_transaksi ASC, id ASC. Dipakai pratinjau & eksekusi
     * agar angkanya identik.
     *
     * @return array{invoices: \Illuminate\Support\Collection, total_hutang: int, dialokasi: int, kembalian: int, rinci: array}
     */
    public function simulasiBulk(int $supplierId, int $nominal, ?string $cabang = null): array
    {
        if ($nominal <= 0) {
            throw new InvalidArgumentException('Nominal pembayaran harus lebih dari 0.');
        }

        $query = InvoicePembelian::where('supplier_id', $supplierId)
            ->where('status', 'belum_lunas')
            ->where('sisa_hutang', '>', 0);
        // Scope cabang (beban hutang): bila diisi, alokasi FIFO hanya ke faktur
        // cabang tsb; faktur cabang-NULL hanya ikut dalam mode "Semua cabang".
        if (($cabang = normalisasi_teks($cabang)) !== null) {
            $query->where('cabang', $cabang);
        }
        $invoices = $query->orderBy('tanggal_transaksi')
            ->orderBy('id')
            ->get();

        $total = (int) $invoices->sum('sisa_hutang');
        if ($total <= 0) {
            throw new InvalidArgumentException(
                'Supplier ini tidak punya hutang belum lunas'
                . ($cabang !== null ? ' pada cabang ' . $cabang . '.' : '.')
            );
        }

        // Kelebihan di atas total = kembalian (tidak dicatat sebagai kredit).
        $sisaAlokasi = min($nominal, $total);
        $rinci = [];
        foreach ($invoices as $inv) {
            if ($sisaAlokasi <= 0) {
                break;
            }
            $porsi = min((int) $inv->sisa_hutang, $sisaAlokasi);
            $sisaBaru = (int) $inv->sisa_hutang - $porsi;
            $rinci[] = [
                'invoice' => $inv,
                'dibayar' => $porsi,
                'sisa_baru' => $sisaBaru,
                'lunas_baru' => $sisaBaru <= 0,
            ];
            $sisaAlokasi -= $porsi;
        }

        $dialokasi = min($nominal, $total);

        return [
            'invoices' => $invoices,
            'total_hutang' => $total,
            'dialokasi' => $dialokasi,
            'kembalian' => $nominal - $dialokasi,
            'rinci' => $rinci,
        ];
    }

    /**
     * Bayar bulk per supplier (FIFO tertua dulu) dalam 1 transaksi atomik.
     * Membuat header BulkBayarHutang + N baris pembayaran (grup = uuid header).
     * $cabang opsional: scope beban yg dibayar (NULL = semua cabang).
     * Mengembalikan hasil alokasi + header utk tombol "Batalkan bulk ini".
     */
    public function bayarBulk(int $supplierId, int $nominal, Carbon $tanggal, ?string $catatan = null, ?string $buktiUrl = null, ?string $cabang = null): array
    {
        return DB::transaction(function () use ($supplierId, $nominal, $tanggal, $catatan, $buktiUrl, $cabang) {
            // Kunci baris agar dua bulk bersamaan tidak alokasi ganda.
            $cabang = normalisasi_teks($cabang);
            $simulasi = $this->simulasiBulk($supplierId, $nominal, $cabang);
            $header = BulkBayarHutang::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'supplier_id' => $supplierId,
                'cabang' => $cabang,
                'tanggal_bayar' => $tanggal,
                'nominal_bayar' => $nominal,
                'dialokasi' => $simulasi['dialokasi'],
                'kembalian' => $simulasi['kembalian'],
                'bukti_url' => $buktiUrl,
                'catatan' => $catatan,
            ]);
            $grup = $header->uuid;

            foreach ($simulasi['rinci'] as $i => $r) {
                /** @var InvoicePembelian $inv */
                $inv = InvoicePembelian::whereKey($r['invoice']->id)->lockForUpdate()->firstOrFail();
                if (! $inv->isHutang() || $inv->sudahLunas() || (int) $inv->sisa_hutang <= 0) {
                    throw new InvalidArgumentException('Invoice ' . $inv->no_invoice . ' berubah saat diproses. Ulangi pembayaran.');
                }
                $porsi = min((int) $inv->sisa_hutang, $r['dibayar']);
                if ($porsi <= 0) {
                    continue;
                }
                $catat = $catatan !== null && trim($catatan) !== ''
                    ? trim($catatan) . ' · bulk ' . ($i + 1) . '/' . count($simulasi['rinci']) . ' dari ' . uang($simulasi['dialokasi'])
                    : 'Bayar bulk ' . uang($simulasi['dialokasi']) . ' (' . ($i + 1) . '/' . count($simulasi['rinci']) . ')';
                $this->catatPembayaran($inv, $porsi, $tanggal, $catat, $grup);
            }

            return $simulasi + ['grup' => $grup, 'header' => $header, 'tanggal' => $tanggal, 'catatan' => $catatan, 'bukti_url' => $buktiUrl];
        });
    }

    /**
     * Hapus 1 pembayaran (revert): sisa dikembalikan, status dihitung ulang.
     * Salah input → hapus → input ulang (tidak ada edit nominal).
     */
    public function hapusPembayaran(PembayaranHutang $pembayaran): InvoicePembelian
    {
        return DB::transaction(function () use ($pembayaran) {
            $grup = $pembayaran->grup_bulk;
            /** @var InvoicePembelian $inv */
            $inv = InvoicePembelian::whereKey($pembayaran->invoice_pembelian_id)->lockForUpdate()->firstOrFail();
            $inv->sisa_hutang += (int) $pembayaran->nominal;
            if ($inv->isHutang() && (int) $inv->sisa_hutang > 0) {
                $inv->status = 'belum_lunas';
            }
            $inv->save();
            $pembayaran->delete();

            // Sinkronkan header bulk bila baris yang dihapus bagian dari paket bulk:
            // hitung ulang dari baris tersisa; header dihapus bila tak ada sisa.
            if ($grup) {
                $this->sinkronHeaderBulk($grup);
            }

            return $inv->fresh();
        });
    }

    /** Hitung ulang dialokasi/kembalian header dari baris tersisa (atau hapus header). */
    private function sinkronHeaderBulk(string $grup): void
    {
        $header = BulkBayarHutang::where('uuid', $grup)->first();
        if (! $header) {
            return;
        }
        $sisaBaris = PembayaranHutang::where('grup_bulk', $grup)->sum('nominal');
        if ((int) $sisaBaris <= 0) {
            $header->delete();

            return;
        }
        $header->update([
            'dialokasi' => (int) $sisaBaris,
            'kembalian' => max(0, (int) $header->nominal_bayar - (int) $sisaBaris),
        ]);
    }

    /**
     * Batalkan 1 grup bulk sekaligus (revert atomik + header dihapus agar
     * hilang dari riwayat). Baris grup yang sudah dihapus manual sebelumnya
     * dilewati & dilaporkan (toleran, bukan gagal).
     *
     * @return array{nominal_kembali: int, baris: int, invoice_ids: array}
     */
    public function batalkanBulk(string $grup): array
    {
        return DB::transaction(function () use ($grup) {
            $rows = PembayaranHutang::where('grup_bulk', $grup)->orderBy('id')->get();
            if ($rows->isEmpty()) {
                throw new InvalidArgumentException('Grup bulk tidak ditemukan (mungkin sudah dibatalkan).');
            }

            $kembali = 0;
            $invoiceIds = [];
            foreach ($rows->groupBy('invoice_pembelian_id') as $invoiceId => $perInv) {
                /** @var InvoicePembelian $inv */
                $inv = InvoicePembelian::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                $jumlah = (int) $perInv->sum('nominal');
                $inv->sisa_hutang += $jumlah;
                if ($inv->isHutang() && (int) $inv->sisa_hutang > 0) {
                    $inv->status = 'belum_lunas';
                }
                $inv->save();
                $kembali += $jumlah;
                $invoiceIds[] = $inv->id;
                PembayaranHutang::whereIn('id', $perInv->pluck('id'))->delete();
            }
            BulkBayarHutang::where('uuid', $grup)->delete();

            return ['nominal_kembali' => $kembali, 'baris' => $rows->count(), 'invoice_ids' => $invoiceIds];
        });
    }

    /**
     * Edit invoice pembelian sebagai operasi atomik (PRD 5.5a):
     * batal efek lama → terapkan efek baru. Ditolak bila qty baru < qty terjual sejak invoice.
     */
    public function edit(InvoicePembelian $invoice, array $data): InvoicePembelian
    {
        return DB::transaction(function () use ($invoice, $data) {
            if ($invoice->pembayaranHutang()->exists()) {
                throw new InvalidArgumentException(
                    'Invoice ini sudah punya histori pembayaran cicilan. Hapus histori pembayaran terlebih dahulu sebelum mengedit.'
                );
            }

            // 5.5a: qty baru tidak boleh < qty yang sudah terjual/keluar sejak invoice disimpan
            foreach ($data['items'] ?? [] as $it) {
                $soldSince = $this->stock->terjualSejak((int) $it['barang_id'], $invoice->tanggal_transaksi);
                if ((int) $it['qty'] < $soldSince) {
                    throw new InvalidArgumentException(
                        'Qty baru (' . $it['qty'] . ') lebih kecil dari ' . $soldSince
                        . ' unit yang sudah terjual sejak invoice disimpan. Qty minimal yang diizinkan: ' . $soldSince . '.'
                    );
                }
            }

            // 1) batal efek lama
            $this->stock->batalEfekPembelian($invoice->id);
            $this->harga->batalHistoriInvoice($invoice->id);
            // SN lama: hapus yang masih tersedia, pertahankan yang sudah terjual (jaga integritas histori)
            ReferensiSn::where('invoice_pembelian_id', $invoice->id)->where('status', 'tersedia')->delete();
            $invoice->items()->delete();

            // 2) terapkan efek baru (no_invoice boleh dikoreksi — netral uang/stok)
            $baru = trim((string) ($data['no_invoice'] ?? ''));
            if ($baru !== '' && $baru !== $invoice->no_invoice) {
                if (mb_strlen($baru) > 40) {
                    throw new InvalidArgumentException('Nomor invoice maksimal 40 karakter.');
                }
                if (InvoicePembelian::where('no_invoice', $baru)->where('id', '!=', $invoice->id)->exists()) {
                    throw new InvalidArgumentException('Nomor "' . $baru . '" sudah dipakai invoice lain.');
                }
                $invoice->no_invoice = $baru;
            }

            return $this->terapkanUlang($invoice, $data);
        });
    }

    private function terapkanUlang(InvoicePembelian $invoice, array $data): InvoicePembelian
    {
        $tgl = Carbon::parse($data['tanggal_transaksi'] ?? $invoice->tanggal_transaksi);
        $jenis = $data['jenis'] ?? $invoice->jenis;
        $items = $data['items'] ?? [];
        if (count($items) === 0) {
            throw new InvalidArgumentException('Minimal harus ada 1 item pembelian.');
        }

        $subtotal = 0;
        $detail = [];
        foreach ($items as $it) {
            $barang = Barang::find($it['barang_id'] ?? 0);
            if (! $barang) {
                throw new InvalidArgumentException('Barang tidak ditemukan.');
            }
            $qty = (int) ($it['qty'] ?? 0);
            $hargaBeli = (int) ($it['harga_beli'] ?? 0);
            $diskonItem = (int) ($it['diskon_item'] ?? 0);
            if ($qty <= 0 || $hargaBeli <= 0) {
                throw new InvalidArgumentException('Qty dan harga beli wajib lebih dari 0 untuk ' . $barang->nama . '.');
            }
            $sub = ($hargaBeli * $qty) - $diskonItem;
            if ($sub < 0) {
                throw new InvalidArgumentException('Sub total item negatif.');
            }
            $subtotal += $sub;
            $detail[] = compact('barang', 'qty', 'hargaBeli', 'diskonItem', 'sub') + [
                'no_sn' => $it['no_sn'] ?? null,
                'ppn_item' => max(0, (int) ($it['ppn'] ?? 0)),
            ];
        }

        $diskonTotal = (int) ($data['diskon_total'] ?? $invoice->diskon_total);
        if ($diskonTotal > $subtotal) {
            throw new InvalidArgumentException('Diskon tidak boleh melebihi subtotal.');
        }
        $total = $subtotal - $diskonTotal;
        // PPN: (1) override per-SKU Σ baris, (2) auto bila flag/null, (3) manual invoice-level, (4) fallback nilai lama.
        $adaPpnItem = collect($detail)->contains(fn ($d) => $d['ppn_item'] > 0);
        if ($adaPpnItem) {
            $ppn = (int) collect($detail)->sum(fn ($d) => $d['ppn_item']);
        } elseif (! empty($data['ppn_otomatis']) || (array_key_exists('ppn', $data) && $data['ppn'] === null)) {
            // Edit via form auto (flag) / import auto (ppn null): hitung ulang dari DPP baru.
            $ppn = self::hitungPpnOtomatis($total);
        } elseif (array_key_exists('ppn', $data)) {
            $ppn = max(0, (int) $data['ppn']);
        } else {
            // Backward-compat: pemanggil lama tanpa kunci ppn → pertahankan nilai lama.
            $ppn = (int) ($invoice->ppn ?? 0);
        }
        $bayarDp = (int) ($data['bayar_atau_dp'] ?? $invoice->bayar_atau_dp);
        $isHutang = $jenis === 'hutang';
        // Sisa Hutang = (Subtotal - Diskon) + PPN - DP
        $totalBayar = $total + $ppn;
        if (! $isHutang && $bayarDp < $totalBayar) {
            throw new InvalidArgumentException('Pembayaran Cash minimal harus sama dengan total + PPN (' . uang($totalBayar) . ').');
        }
        $sisa = $isHutang ? max(0, $totalBayar - $bayarDp) : 0;
        $status = ($isHutang && $sisa > 0) ? 'belum_lunas' : 'lunas';
        $jatuhTempo = $isHutang
            ? (! empty($data['jatuh_tempo']) ? Carbon::parse($data['jatuh_tempo']) : $this->defaultJatuhTempo($tgl))
            : null;

        $invoice->update([
            'supplier_id' => (int) ($data['supplier_id'] ?? $invoice->supplier_id),
            'tanggal_transaksi' => $tgl,
            'jenis' => $jenis,
            'diskon_total' => $diskonTotal,
            'ppn' => $ppn,
            'subtotal' => $subtotal,
            'total_setelah_diskon' => $total,
            'bayar_atau_dp' => $bayarDp,
            'jatuh_tempo' => $jatuhTempo,
            'sisa_hutang' => $sisa,
            'status' => $status,
        ]);

        foreach ($detail as $d) {
            InvoicePembelianItem::create([
                'invoice_pembelian_id' => $invoice->id,
                'barang_id' => $d['barang']->id,
                'harga_beli' => $d['hargaBeli'],
                'qty' => $d['qty'],
                'diskon_item' => $d['diskonItem'],
                'ppn' => $d['ppn_item'],
                'sub_total' => $d['sub'],
                'no_sn' => $d['no_sn'],
            ]);
            $this->stock->mutasi(
                $d['barang'],
                $tgl,
                JenisMutasiStok::MASUK_PEMBELIAN,
                $d['qty'],
                $invoice->id,
                null,
                'Pembelian ' . $invoice->no_invoice,
            );
            $hargaBeliHistori = $adaPpnItem
                ? $d['hargaBeli'] + (int) round($d['ppn_item'] / max(1, $d['qty']))
                : $this->hargaBeliEfektif($d['hargaBeli'], $d['qty'], $d['sub'], $subtotal, $ppn);
            $this->harga->catatPembelian($d['barang'], $hargaBeliHistori, $tgl, $invoice->id);
            if ($d['barang']->isSn()) {
                $this->daftarkanSn($d['barang'], $d['qty'], $invoice->id, (string) $d['no_sn']);
            }
        }

        return $invoice->load(['items', 'supplier']);
    }

    /**
     * Hapus invoice pembelian (PRD 5.5b).
     * - Diblokir bila invoice hutang punya histori pembayaran.
     * - Tanpa $force: dilempar StokException bila hapus membuat stok minus (UI menampilkan warning 2 opsi).
     * - Dengan $force: dilanjutkan walau stok jadi minus (dicatat lewat wording).
     */
    public function hapus(InvoicePembelian $invoice, bool $force = false): void
    {
        DB::transaction(function () use ($invoice, $force) {
            if ($invoice->pembayaranHutang()->exists()) {
                throw new InvalidArgumentException(
                    'Invoice hutang dengan histori pembayaran tidak bisa dihapus. Hapus/batalkan histori pembayarannya terlebih dahulu.'
                );
            }

            // Cek per SKU (agregat): reversal mengurangi stok sebesar JUMLAH qty semua baris
            // barang yang sama. Cek per-baris bisa lolos padahal total reversal membuat minus
            // (mis. SKU ganda 4x6=24, stok 10 → 10-24=-14 tanpa warning).
            $dampak = [];
            foreach ($invoice->items->groupBy('barang_id') as $barangId => $itemRows) {
                $barang = Barang::find($barangId);
                if (! $barang) {
                    continue;
                }
                $totalQty = (int) $itemRows->sum('qty');
                if (($barang->stock - $totalQty) < 0) {
                    $dampak[] = $barang->nama . ' (sisa ' . $barang->stock . ' < qty invoice ' . $totalQty . ')';
                }
            }
            if (! $force && count($dampak) > 0) {
                throw new StokException(
                    'Menghapus invoice ini akan membuat stok minus: ' . implode(', ', $dampak)
                    . '. Lanjutkan hapus dengan menandai anomali stok?',
                    $dampak,
                );
            }

            $this->stock->batalEfekPembelian($invoice->id);
            $this->harga->batalHistoriInvoice($invoice->id);
            // SN: hapus yang masih tersedia; yang terjual dipertahankan utk integritas histori (referensi dilepas)
            ReferensiSn::where('invoice_pembelian_id', $invoice->id)->where('status', 'tersedia')->delete();
            ReferensiSn::where('invoice_pembelian_id', $invoice->id)->where('status', 'terjual')
                ->update(['invoice_pembelian_id' => null]);
            $invoice->items()->delete();
            $invoice->delete();
        });
    }
}
