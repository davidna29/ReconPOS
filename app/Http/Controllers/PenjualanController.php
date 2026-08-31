<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\KartuStok;
use App\Models\TransaksiPenjualan;
use App\Services\PenjualanService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Penjualan manual (kasir-lite) — sumber 'manual' utk dashboard, generate & export (PRD 1.2b, 8).
 */
class PenjualanController extends Controller
{
    public function __construct(private PenjualanService $service) {}

    public function index(Request $request)
    {
        $query = TransaksiPenjualan::with('batchGenerate')->where('sumber', 'manual');
        if ($s = trim((string) $request->get('q'))) {
            $query->where('ref_transaksi', 'like', "%{$s}%");
        }
        $transaksis = $query->latest('tanggal_waktu')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();

        return view('penjualan.index', compact('transaksis'));
    }

    public function create()
    {
        return view('penjualan.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tanggal_waktu' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.barang_id' => ['required', 'integer', 'exists:barang,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.harga_jual' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $trx = $this->service->simpanManual(
                Carbon::parse($validated['tanggal_waktu']),
                array_map(fn ($it) => [
                    'barang_id' => (int) $it['barang_id'],
                    'qty' => (int) $it['qty'],
                    'harga_jual' => (int) $it['harga_jual'],
                ], $validated['items']),
            );

            return redirect()->route('penjualan.index')->with('success', 'Penjualan manual ' . $trx->ref_transaksi . ' disimpan (stok terpotong).');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(TransaksiPenjualan $transaksiPenjualan)
    {
        try {
            $this->service->hapus($transaksiPenjualan);

            return redirect()->route('penjualan.index')->with('success', 'Penjualan dihapus, stok dikembalikan.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Endpoint riwayat mutasi per barang utk form penjualan (info sisa stok). */
    public function stokBarang(Barang $barang)
    {
        $mutasi = KartuStok::where('barang_id', $barang->id)->orderBy('tanggal')->get();
        $rows = [];
        $saldo = 0;
        foreach ($mutasi as $m) {
            $saldo += $m->qty;
            $rows[] = [
                'tanggal' => $m->tanggal->format('d/m/Y H:i'),
                'jenis' => $m->jenis,
                'qty' => $m->qty,
                'saldo' => $saldo,
                'keterangan' => $m->keterangan,
            ];
        }

        return response()->json(['stock' => $barang->stock, 'mutasi' => $rows]);
    }
}
