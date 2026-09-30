<?php

namespace App\Http\Controllers\Publik;

use App\Models\Barang;
use App\Models\KategoriBarang;
use Illuminate\Http\Request;

/**
 * Mode Publik (tanpa login) — Data Barang read-only: index, show, kartu stok.
 * Tanpa create/store/update/destroy/excel/opname/stok-awal/barcode/cari.
 */
class BarangController extends Controller
{
    public function index(Request $request)
    {
        $query = Barang::with('kategori')->where('tipe_item', 'barang');

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('kode_barang', 'like', "%{$s}%")
                ->orWhere('nama', 'like', "%{$s}%")
                ->orWhere('merk', 'like', "%{$s}%")
                ->orWhere('varian', 'like', "%{$s}%")
                ->orWhere('ukuran_ban', 'like', "%{$s}%"));
        }
        if ($kategori = (int) $request->get('kategori')) {
            $query->where('kategori_id', $kategori);
        }
        if (($merk = trim((string) $request->get('merk'))) !== '') {
            $query->where('merk', $merk);
        }

        $barangs = $query->orderBy('kode_barang')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();
        $kategoris = KategoriBarang::orderBy('nama')->get();
        $merks = Barang::where('tipe_item', 'barang')
            ->whereNotNull('merk')->where('merk', '<>', '')
            ->distinct()->orderBy('merk')->pluck('merk');

        return view('barang.index', compact('barangs', 'kategoris', 'merks') + ['publik' => true]);
    }

    public function show(Barang $barang)
    {
        $histori = $barang->historiHarga()->latest('tanggal_berlaku')->limit(25)->get();
        $kartu = $barang->kartuStok()->get();

        return view('barang.show', compact('barang', 'histori', 'kartu') + ['publik' => true]);
    }

    public function kartuStok(Barang $barang)
    {
        $mutasi = $barang->kartuStok()->with(['invoicePembelian', 'transaksiPenjualan'])->get();
        $saldo = 0;
        $rows = $mutasi->map(function ($m) use (&$saldo) {
            $saldo += $m->qty;

            return [
                'tanggal' => $m->tanggal,
                'jenis' => $m->jenis,
                'qty' => $m->qty,
                'keterangan' => $m->keterangan,
                'referensi' => $m->invoicePembelian?->no_invoice ?? $m->transaksiPenjualan?->ref_transaksi ?? null,
                'saldo' => $saldo,
            ];
        });

        return view('barang.kartu-stok', compact('barang', 'rows') + ['publik' => true]);
    }

    /** Cetak label barcode (read-only). */
    public function cetakBarcode(Barang $barang)
    {
        return view('barang.barcode', compact('barang'));
    }
}
