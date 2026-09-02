<?php

namespace App\Http\Controllers\Publik;

use App\Models\InvoicePembelian;
use Illuminate\Http\Request;

/**
 * Mode Publik (tanpa login) — Pembelian read-only: Daftar Hutang Belum Lunas,
 * Data Pembelian (Lunas/Cash), detail invoice, dan cetak invoice.
 */
class PembelianController extends Controller
{
    /** Daftar hutang belum lunas (read-only). */
    public function hutang(Request $request)
    {
        $query = InvoicePembelian::with('supplier')
            ->where('status', 'belum_lunas');

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('nama', 'like', "%{$s}%")));
        }

        $invoices = $query->latest('tanggal_transaksi')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();

        return view('pembelian.invoice-hutang', compact('invoices') + ['publik' => true]);
    }

    /** Daftar pembelian lunas/cash (read-only). */
    public function lunas(Request $request)
    {
        $query = InvoicePembelian::with('supplier')
            ->where('status', 'lunas');

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('nama', 'like', "%{$s}%")));
        }

        $invoices = $query->latest('tanggal_transaksi')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();

        return view('pembelian.invoice-lunas', compact('invoices') + ['publik' => true]);
    }

    /** Detail invoice (read-only). */
    public function show(InvoicePembelian $invoice)
    {
        $invoice->load(['items.barang', 'supplier', 'pembayaranHutang']);

        return view('pembelian.show', compact('invoice') + ['publik' => true]);
    }

    /** Cetak invoice (read-only) — layouts/print.blade.php tanpa dependensi auth. */
    public function print(InvoicePembelian $invoice)
    {
        $invoice->load(['items.barang', 'supplier', 'pembayaranHutang']);

        return view('pembelian.print', compact('invoice'));
    }
}
