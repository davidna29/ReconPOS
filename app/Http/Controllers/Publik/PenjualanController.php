<?php

namespace App\Http\Controllers\Publik;

use App\Models\TransaksiPenjualan;
use Illuminate\Http\Request;

/**
 * Mode Publik (tanpa login) — Penjualan read-only.
 * Halaman publik HANYA menampilkan daftar transaksi tersimpan (tanpa kartu omset/
 * target, tanpa tab Manual/Rekonstruksi, tanpa kolom sumber, tanpa edit).
 */
class PenjualanController extends Controller
{
    public function index(Request $request)
    {
        $query = TransaksiPenjualan::with(['items.barang'])
            ->where('status', 'tersimpan');

        if ($s = trim((string) $request->get('q'))) {
            $query->where('ref_transaksi', 'like', "%{$s}%");
        }

        $transaksis = $query->latest('tanggal_waktu')
            ->paginate((int) ($request->get('entries') ?: 10))
            ->withQueryString();

        return view('penjualan.index', compact('transaksis') + ['publik' => true]);
    }

    /** Cetak struk (read-only). */
    public function print(TransaksiPenjualan $transaksiPenjualan)
    {
        $transaksiPenjualan->load(['items.barang', 'batchGenerate']);

        return view('penjualan.print', ['trx' => $transaksiPenjualan]);
    }
}
