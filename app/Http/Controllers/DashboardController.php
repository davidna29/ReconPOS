<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\TokoSetting;
use App\Models\TransaksiPenjualan;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $awalBulan = now()->startOfMonth();
        $akhirBulan = now()->endOfMonth();

        $omsetBulanIni = (int) TransaksiPenjualan::where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$awalBulan, $akhirBulan])
            ->sum('total');

        $hutang = InvoicePembelian::where('status', 'belum_lunas')->get();
        $totalHutang = (int) $hutang->sum('sisa_hutang');
        $jatuhTempo7Hari = $hutang->filter(function ($inv) {
            return $inv->jatuh_tempo && $inv->jatuh_tempo->lessThanOrEqualTo(now()->addDays(7));
        });

        $settings = TokoSetting::utama();
        $ambang = (int) $settings->ambang_stok_menipis;
        $stokKritis = Barang::where('tipe_item', 'barang')
            ->where('stock', '<=', $ambang)
            ->orderBy('stock')
            ->get();
        $stokHabis = $stokKritis->where('stock', '<=', 0)->count();

        // transaksi terakhir: penjualan & pembelian digabung, 10 terbaru
        $penjualanTerakhir = TransaksiPenjualan::withCount('items')
            ->where('status', 'tersimpan')
            ->latest('tanggal_waktu')
            ->limit(8)
            ->get()
            ->map(fn ($t) => [
                'jenis' => 'penjualan',
                'ref' => $t->ref_transaksi,
                'tanggal' => $t->tanggal_waktu,
                'pihak' => 'Customer Umum',
                'total' => $t->total,
                'status' => $t->sumber === 'rekonstruksi' ? 'rekonstruksi' : 'lunas',
            ]);

        $pembelianTerakhir = InvoicePembelian::with('supplier')
            ->latest('tanggal_transaksi')
            ->limit(8)
            ->get()
            ->map(fn ($i) => [
                'jenis' => 'pembelian',
                'ref' => $i->no_invoice,
                'tanggal' => $i->tanggal_transaksi,
                'pihak' => $i->supplier?->nama ?? '-',
                'total' => $i->total_setelah_diskon,
                'status' => $i->status,
            ]);

        $transaksiTerakhir = $penjualanTerakhir->concat($pembelianTerakhir)
            ->sortByDesc('tanggal')
            ->take(10)
            ->values();

        return view('dashboard.index', compact(
            'omsetBulanIni',
            'totalHutang',
            'jatuhTempo7Hari',
            'stokKritis',
            'stokHabis',
            'ambang',
            'transaksiTerakhir',
        ));
    }
}
