<?php

namespace App\Http\Controllers;

use App\Models\BatchGenerate;
use App\Models\Barang;
use App\Models\KartuStok;
use App\Models\TransaksiPenjualan;
use App\Services\PenjualanService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Satu laman "Penjualan": manual + hasil generate (rekonstruksi).
 * Nota tersimpan (manual & rekonstruksi) bisa diedit; rekonstruksi yang diedit mengunci undo batch.
 */
class PenjualanController extends Controller
{
    public function __construct(private PenjualanService $service) {}

    public function index(Request $request)
    {
        // ---- Periode untuk kartu omset (sama dgn dashboard) ----
        [$awal, $akhir, $labelPeriode] = $this->periodeDariRequest($request);

        $omsetPeriode = (int) TransaksiPenjualan::where('status', 'tersimpan')
            ->whereBetween('tanggal_waktu', [$awal->copy()->startOfDay(), $akhir->copy()->endOfDay()])
            ->sum('total');

        // Cari target dari batch_generate terakhir yang periode-nya overlap
        $batch = BatchGenerate::where('status', 'tersimpan')
            ->where('periode_mulai', '<=', $akhir)
            ->where('periode_selesai', '>=', $awal)
            ->latest('periode_mulai')
            ->first();
        $targetOmset = $batch?->target_omset;
        $sisaTarget = $targetOmset !== null ? max(0, $targetOmset - $omsetPeriode) : null;

        // ---- Tabel transaksi ----
        $query = TransaksiPenjualan::with(['batchGenerate', 'items.barang'])
            ->where('status', 'tersimpan');

        $sumber = $request->get('sumber');
        if (in_array($sumber, ['manual', 'rekonstruksi'], true)) {
            $query->where('sumber', $sumber);
        }
        if ($s = trim((string) $request->get('q'))) {
            $query->where('ref_transaksi', 'like', "%{$s}%");
        }

        $transaksis = $query->latest('tanggal_waktu')
            ->paginate((int) ($request->get('entries') ?: 10))
            ->withQueryString();

        $jumlah = [
            'semua' => TransaksiPenjualan::where('status', 'tersimpan')->count(),
            'manual' => TransaksiPenjualan::where('status', 'tersimpan')->where('sumber', 'manual')->count(),
            'rekonstruksi' => TransaksiPenjualan::where('status', 'tersimpan')->where('sumber', 'rekonstruksi')->count(),
        ];

        return view('penjualan.index', compact(
            'transaksis', 'jumlah', 'sumber',
            'awal', 'akhir', 'labelPeriode', 'omsetPeriode',
            'targetOmset', 'sisaTarget',
        ));
    }

    /**
     * Resolve periode dari query string (sama dgn DashboardController).
     */
    private function periodeDariRequest(Request $request): array
    {
        $awal = now()->copy()->startOfMonth();
        $akhir = now()->copy()->endOfMonth();
        $label = 'Bulan Berjalan — ' . bulan_id(now());

        if ($bulan = (string) $request->query('bulan')) {
            if (preg_match('/^\d{4}-\d{2}$/', $bulan)) {
                $awal = \Carbon\Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();
                $akhir = $awal->copy()->endOfMonth();
                $label = bulan_id($awal);
            }
        } elseif ($tahun = (int) $request->query('tahun')) {
            if ($tahun >= 2000 && $tahun <= 2099) {
                $awal = \Carbon\Carbon::create($tahun, 1, 1)->startOfDay();
                $akhir = \Carbon\Carbon::create($tahun, 12, 31)->endOfDay();
                $label = 'Tahun ' . $tahun;
            }
        } elseif ($request->query('from') || $request->query('to')) {
            $from = $request->query('from');
            $to = $request->query('to');
            $parsedFrom = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? \Carbon\Carbon::parse($from) : null;
            $parsedTo = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? \Carbon\Carbon::parse($to) : null;
            if ($parsedFrom && $parsedTo && $parsedFrom->lte($parsedTo)) {
                $awal = $parsedFrom;
                $akhir = $parsedTo;
                $label = $parsedFrom->format('d/m/Y') . ' – ' . $parsedTo->format('d/m/Y');
            }
        }

        return [$awal, $akhir, $label];
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

    public function edit(TransaksiPenjualan $transaksiPenjualan)
    {
        if ($transaksiPenjualan->status !== 'tersimpan') {
            return back()->with('error', 'Hanya nota tersimpan yang bisa diedit.');
        }
        $transaksiPenjualan->load(['items.barang']);

        return view('penjualan.edit', ['trx' => $transaksiPenjualan]);
    }

    public function update(Request $request, TransaksiPenjualan $transaksiPenjualan)
    {
        $validated = $request->validate([
            'tanggal_waktu' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.barang_id' => ['required', 'integer', 'exists:barang,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.harga_jual' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $trx = $this->service->edit(
                $transaksiPenjualan,
                Carbon::parse($validated['tanggal_waktu']),
                array_map(fn ($it) => [
                    'barang_id' => (int) $it['barang_id'],
                    'qty' => (int) $it['qty'],
                    'harga_jual' => (int) $it['harga_jual'],
                ], $validated['items']),
            );

            return redirect()->route('penjualan.index')->with('success', 'Nota ' . $trx->ref_transaksi . ' diperbarui (stok & NO_SN disesuaikan).');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function print(TransaksiPenjualan $transaksiPenjualan)
    {
        $transaksiPenjualan->load(['items.barang', 'batchGenerate']);

        return view('penjualan.print', ['trx' => $transaksiPenjualan]);
    }

    /** Bulk print: cetak banyak nota terpilih dalam satu halaman (page-break per nota). */
    public function printBatch(Request $request)
    {
        // ids yang sudah tidak ada dibuang diam-diam oleh whereIn; hanya validasi tipe integer.
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $trxs = TransaksiPenjualan::with(['items.barang'])
            ->whereIn('id', $validated['ids'])
            ->where('status', 'tersimpan')
            ->orderBy('tanggal_waktu')->orderBy('id')
            ->get();

        if ($trxs->isEmpty()) {
            return back()->with('error', 'Tidak ada nota valid untuk dicetak.');
        }

        return view('penjualan.print-batch', ['trxs' => $trxs]);
    }

    public function destroy(Request $request, TransaksiPenjualan $transaksiPenjualan)
    {
        try {
            $this->service->hapus($transaksiPenjualan);
            $msg = 'Penjualan ' . $transaksiPenjualan->ref_transaksi . ' dihapus, stok dikembalikan.';

            return $request->expectsJson()
                ? response()->json(['success' => true, 'message' => $msg])
                : redirect()->route('penjualan.index')->with('success', $msg);
        } catch (InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'error' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }
    }

    /** Bulk delete: hapus banyak nota via AJAX, toleran error per item. */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = [];
        $errors = [];
        foreach ($validated['ids'] as $id) {
            $trx = TransaksiPenjualan::find($id);
            if (! $trx || $trx->status !== 'tersimpan') {
                $errors[$id] = 'Tidak ditemukan atau bukan nota tersimpan.';
                continue;
            }
            try {
                $this->service->hapus($trx);
                $deleted[] = $id;
            } catch (Throwable $e) {
                $errors[$id] = $e->getMessage();
            }
        }

        return response()->json([
            'success' => count($deleted) > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => count($deleted) . ' nota dihapus.' . (count($errors) ? ' ' . count($errors) . ' gagal.' : ''),
        ]);
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
