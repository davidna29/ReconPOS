<?php

namespace App\Http\Controllers;

use App\Models\InvoicePembelian;
use App\Models\Supplier;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();
        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('nama', 'like', "%{$s}%")->orWhere('kontak', 'like', "%{$s}%"));
        }
        $suppliers = $query->orderBy('nama')->paginate((int) ($request->get('entries') ?: 10))
            ->withQueryString();

        return view('supplier.index', compact('suppliers'));
    }

    public function create()
    {
        return view('supplier.form', ['supplier' => null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:190'],
            'kontak' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email'],
            'alamat' => ['nullable', 'string'],
            'catatan' => ['nullable', 'string'],
        ]);
        $supplier = Supplier::create($data);

        $redirectTo = $request->get('redirect_to');
        if ($redirectTo === 'pembelian') {
            return redirect()->route('pembelian.create')->with('success', 'Supplier "' . $supplier->nama . '" berhasil ditambahkan.');
        }

        return redirect()->route('supplier.index')->with('success', 'Supplier "' . $supplier->nama . '" berhasil ditambahkan.');
    }

    public function edit(Supplier $supplier)
    {
        return view('supplier.form', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:190'],
            'kontak' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email'],
            'alamat' => ['nullable', 'string'],
            'catatan' => ['nullable', 'string'],
        ]);
        $supplier->update($data);

        return redirect()->route('supplier.index')->with('success', 'Supplier diperbarui.');
    }

    public function destroy(Supplier $supplier)
    {
        try {
            if ($supplier->invoicePembelian()->exists()) {
                throw new InvalidArgumentException('Supplier ini sudah punya transaksi pembelian — tidak bisa dihapus.');
            }
            $supplier->delete();

            return redirect()->route('supplier.index')->with('success', 'Supplier dihapus.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function riwayat(Supplier $supplier, Request $request)
    {
        $query = InvoicePembelian::with('supplier')->where('supplier_id', $supplier->id);
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        $invoices = $query->latest('tanggal_transaksi')->paginate(10)->withQueryString();

        return view('supplier.riwayat', compact('supplier', 'invoices'));
    }
}
