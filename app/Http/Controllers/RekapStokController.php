<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use Illuminate\Http\Request;

class RekapStokController extends Controller
{
    public function index(Request $request)
    {
        [$groups, $totalSku, $totalStok, $merks] = self::rekap($request);

        return view('rekap-stok.index', compact('groups', 'totalSku', 'totalStok', 'merks'));
    }

    /**
     * Query + grouping bersama utk admin & publik (lihat Publik\RekapStokController):
     * Merk → Varian → baris SKU (kode, nama, ukuran, stok).
     *
     * @return array{0: \Illuminate\Support\Collection, 1: int, 2: int, 3: \Illuminate\Support\Collection}
     */
    public static function rekap(Request $request): array
    {
        $query = Barang::where('tipe_item', 'barang');

        if (($merk = trim((string) $request->get('merk'))) !== '') {
            $query->where('merk', $merk);
        }
        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('kode_barang', 'like', "%{$s}%")
                ->orWhere('nama', 'like', "%{$s}%")
                ->orWhere('ukuran_ban', 'like', "%{$s}%"));
        }

        $barangs = $query->orderBy('merk')->orderBy('varian')
            ->orderBy('ukuran_ban')->orderBy('kode_barang')->get();

        if ($request->boolean('sembunyikan_kosong')) {
            $barangs = $barangs->filter(fn ($b) => $b->stock > 0)->values();
        }

        $groups = $barangs->groupBy(fn ($b) => $b->labelMerk())
            ->map(fn ($items, $namaMerk) => [
                'merk' => $namaMerk,
                'total_sku' => $items->count(),
                'total_stok' => (int) $items->sum('stock'),
                'varians' => $items
                    ->groupBy(fn ($b) => trim((string) $b->varian) !== '' ? $b->varian : '-')
                    ->map(fn ($vs, $namaVarian) => [
                        'varian' => $namaVarian,
                        'total_stok' => (int) $vs->sum('stock'),
                        'items' => $vs->values(),
                    ])->values(),
            ])
            // 'Tanpa Merk' selalu paling bawah
            ->sortBy(fn ($g) => $g['merk'] === 'Tanpa Merk' ? '~~~' : $g['merk'])
            ->values();

        $merks = Barang::where('tipe_item', 'barang')
            ->whereNotNull('merk')->where('merk', '<>', '')
            ->distinct()->orderBy('merk')->pluck('merk');

        return [$groups, $barangs->count(), (int) $barangs->sum('stock'), $merks];
    }
}
