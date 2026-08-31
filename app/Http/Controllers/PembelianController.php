<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportFileException;
use App\Exceptions\StokException;
use App\Models\InvoicePembelian;
use App\Models\PembayaranHutang;
use App\Models\Supplier;
use App\Services\ExcelImportService;
use App\Services\PembelianService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class PembelianController extends Controller
{
    public function __construct(
        private PembelianService $service,
        private ExcelImportService $excel,
    ) {}

    /**
     * Form Transaksi Pembelian baru (PRD 5.1).
     */
    public function create()
    {
        $suppliers = Supplier::orderBy('nama')->get();

        return view('pembelian.transaksi', [
            'invoice' => null,
            'suppliers' => $suppliers,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $invoice = $this->service->simpan($this->parseForm($request), 'manual');

            return redirect()->route('pembelian.show', $invoice)
                ->with('success', 'Transaksi pembelian ' . $invoice->no_invoice . ' disimpan. Stok bertambah & histori harga tercatat.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /**
     * Edit invoice (PRD 5.5a) — operasi atomik.
     */
    public function edit(InvoicePembelian $invoice)
    {
        $suppliers = Supplier::orderBy('nama')->get();

        return view('pembelian.transaksi', compact('invoice', 'suppliers'));
    }

    public function update(Request $request, InvoicePembelian $invoice)
    {
        try {
            $this->service->edit($invoice, $this->parseForm($request, $invoice));

            return redirect()->route('pembelian.show', $invoice)->with('success', 'Invoice ' . $invoice->no_invoice . ' diperbarui (efek stok disesuaikan).');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Data Hutang Belum Lunas (PRD 5.2a). */
    public function invoiceHutang(Request $request)
    {
        $query = InvoicePembelian::with('supplier')
            ->where('status', 'belum_lunas');

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('nama', 'like', "%{$s}%")));
        }

        $invoices = $query->latest('tanggal_transaksi')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();

        return view('pembelian.invoice-hutang', compact('invoices'));
    }

    /** Data Pembelian (Lunas/Cash) (PRD 5.2b). */
    public function invoiceLunas(Request $request)
    {
        $query = InvoicePembelian::with('supplier')
            ->where('status', 'lunas');

        if ($s = trim((string) $request->get('q'))) {
            $query->where(fn ($q) => $q->where('no_invoice', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('nama', 'like', "%{$s}%")));
        }

        $invoices = $query->latest('tanggal_transaksi')->paginate((int) ($request->get('entries') ?: 10))->withQueryString();

        return view('pembelian.invoice-lunas', compact('invoices'));
    }

    public function show(InvoicePembelian $invoice)
    {
        $invoice->load(['items.barang', 'supplier', 'pembayaranHutang']);

        return view('pembelian.show', compact('invoice'));
    }

    /** Pembayaran/lunasi hutang (PRD 5.2a). */
    public function bayarStore(Request $request, InvoicePembelian $invoice)
    {
        $data = $request->validate([
            'nominal' => ['required', 'integer', 'min:1'],
            'tanggal_bayar' => ['required', 'date'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->pelunasan($invoice, (int) $data['nominal'], Carbon::parse($data['tanggal_bayar']), $data['catatan'] ?? null);

            $pesan = $invoice->fresh()->sudahLunas()
                ? 'Pembayaran diterima. Invoice kini lunas dan berpindah ke Data Pembelian.'
                : 'Pembayaran diterima. Sisa hutang: ' . uang($invoice->fresh()->sisa_hutang) . '.';

            return back()->with('success', $pesan);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Hapus invoice pembelian (PRD 5.5b) — dengan konfirmasi force bila stok minus. */
    public function destroy(Request $request, InvoicePembelian $invoice)
    {
        try {
            $this->service->hapus($invoice, $request->boolean('force'));

            return redirect()->route('pembelian.invoice-hutang')->with('success', 'Invoice ' . $invoice->no_invoice . ' dihapus (efek stok dibatalkan).');
        } catch (StokException $e) {
            // tampilkan warning 2 opsi: lanjutkan (stok minus) / batalkan
            return back()->with('stok_warning', [
                'invoice' => $invoice->id,
                'pesan' => $e->getMessage(),
                'detail' => $e->getDetail(),
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function print(InvoicePembelian $invoice)
    {
        $invoice->load(['items.barang', 'supplier', 'pembayaranHutang']);

        return view('pembelian.print', compact('invoice'));
    }

    // ---- Excel: template & import pembelian (PRD 5.3) ----
    public function downloadTemplate()
    {
        $path = $this->excel->templatePembelian();

        return response()->download($path, 'template-pembelian.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx']]);

        try {
            $hasil = $this->excel->importPembelian($request->file('file')->getRealPath());

            return redirect()->route('pembelian.invoice-lunas')
                ->with('success', 'Import pembelian selesai: ' . $hasil['jumlah_invoice'] . ' invoice dibuat.');
        } catch (ImportFileException $e) {
            return back()->withErrors(['file' => $e->getMessage() . (count($e->getErrors()) ? ' Contoh: ' . implode(' | ', array_slice($e->getErrors(), 0, 5)) : '')]);
        }
    }

    private function parseForm(Request $request, ?InvoicePembelian $invoice = null): array
    {
        $validated = $request->validate([
            'no_invoice' => ['nullable', 'string', 'max:40'],
            'supplier_id' => ['required', 'integer', 'exists:supplier,id'],
            'tanggal_transaksi' => ['required', 'date'],
            'jenis' => ['required', 'in:cash,hutang'],
            'diskon_total' => ['nullable', 'integer', 'min:0'],
            'bayar_atau_dp' => ['nullable', 'integer', 'min:0'],
            'jatuh_tempo' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.barang_id' => ['required', 'integer', 'exists:barang,id'],
            'items.*.harga_beli' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.diskon_item' => ['nullable', 'integer', 'min:0'],
            'items.*.no_sn' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'no_invoice' => $validated['no_invoice'] ?? null,
            'supplier_id' => $validated['supplier_id'],
            'tanggal_transaksi' => Carbon::parse($validated['tanggal_transaksi'])->format('Y-m-d H:i'),
            'jenis' => $validated['jenis'],
            'diskon_total' => (int) ($validated['diskon_total'] ?? 0),
            'bayar_atau_dp' => (int) ($validated['bayar_atau_dp'] ?? 0),
            'jatuh_tempo' => $validated['jatuh_tempo'] ?? null,
            'items' => array_map(fn ($it) => [
                'barang_id' => (int) $it['barang_id'],
                'harga_beli' => (int) $it['harga_beli'],
                'qty' => (int) $it['qty'],
                'diskon_item' => (int) ($it['diskon_item'] ?? 0),
                'no_sn' => $it['no_sn'] ?? null,
            ], $validated['items']),
        ];
    }
}
