<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\BatchGenerate;
use App\Models\TokoSetting;
use App\Models\TransaksiPenjualan;
use App\Models\TransaksiPenjualanItem;
use App\Services\GenerateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class GenerateController extends Controller
{
    public function __construct(private GenerateService $service) {}

    /**
     * Form generate + pendaftaran batch (riwayat) (PRD 7).
     */
    public function index(Request $request)
    {
        $settings = TokoSetting::utama();
        $params = $settings->parameterGenerate();

        $batches = BatchGenerate::withCount('transaksiPenjualan')->latest()->limit(20)->get();
        $barangs = Barang::where('tipe_item', 'barang')->orderBy('kode_barang')->get();

        return view('generate.index', compact('settings', 'params', 'batches', 'barangs'));
    }

    /**
     * Jalankan generate draft (POST). Menangani warning kapasitas stok (PRD 6 poin 3).
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'periode_mulai' => ['required', 'date'],
            'periode_selesai' => ['required', 'date', 'after_or_equal:periode_mulai'],
            'target_omset' => ['required', 'integer', 'min:1'],
            'nota_min' => ['nullable', 'integer', 'min:1', 'max:30'],
            'nota_max' => ['nullable', 'integer', 'min:1', 'max:30'],
            'toleransi_persen' => ['nullable', 'integer', 'min:0', 'max:20'],
            'variasi_min' => ['nullable', 'integer', 'min:-50', 'max:0'],
            'variasi_max' => ['nullable', 'integer', 'min:-50', 'max:50'],
            'maks_qty_item_per_nota' => ['nullable', 'integer', 'min:1', 'max:20'],
            'excluded_item_ids' => ['nullable', 'array'],
        ]);

        $mulai = Carbon::parse($validated['periode_mulai']);
        $selesai = Carbon::parse($validated['periode_selesai']);

        $params = [
            'nota_min' => (int) ($validated['nota_min'] ?? 1),
            'nota_max' => (int) ($validated['nota_max'] ?? 8),
            'toleransi_persen' => (int) ($validated['toleransi_persen'] ?? 2),
            'variasi_min' => (int) ($validated['variasi_min'] ?? -10),
            'variasi_max' => (int) ($validated['variasi_max'] ?? 0),
            'maks_qty_item_per_nota' => (int) ($validated['maks_qty_item_per_nota'] ?? 3),
            'excluded_item_ids' => $validated['excluded_item_ids'] ?? [],
        ];

        try {
            $result = $this->service->generate(
                $mulai,
                $selesai,
                (int) $validated['target_omset'],
                $params,
                $request->boolean('force'),
            );

            if (($result['status'] ?? '') === 'kapasitas_kurang') {
                return back()->with('kapasitas_warning', [
                    'kapasitas' => $result['kapasitas'],
                    'target' => $result['target'],
                    'pesan' => $result['pesan'],
                    'input' => $request->except('_token'),
                ])->withInput();
            }

            return redirect()->route('generate.review', $result['batch']->id)
                ->with('success', 'Draft generate selesai dibuat. Review sebelum disimpan.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /**
     * Review draft per hari (PRD 7 langkah 5-6): edit baris, hapus nota, simpan, generate ulang, batalkan.
     */
    public function review(BatchGenerate $batch)
    {
        if ($batch->status !== 'draft') {
            return redirect()->route('generate.riwayat')->with('error', 'Batch tidak berstatus draft (tersimpan/dibatalkan) — tidak bisa direview.');
        }

        $batch->load(['transaksiPenjualan.items.barang']);

        $perHari = $batch->transaksiPenjualan
            ->sortBy('tanggal_waktu')
            ->groupBy(fn ($t) => $t->tanggal_waktu->toDateString());

        return view('generate.review', compact('batch', 'perHari'));
    }

    /** Simpan draft → pembukuan resmi (potong stok, tandai SN) (PRD 7 langkah 7). */
    public function simpan(BatchGenerate $batch)
    {
        try {
            $this->service->simpanDraft($batch->id);

            return redirect()->route('generate.riwayat')
                ->with('success', 'Draft disimpan sebagai transaksi rekonstruksi resmi. Stok terpotong & sumber transaksi ditandai "rekonstruksi".');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Undo batch tersimpan: kembalikan stok & NO_SN, status 'dibatalkan' (audit dipertahankan). */
    public function batalkan(BatchGenerate $batch)
    {
        try {
            $this->service->batalBatchTersimpan($batch->id);

            return redirect()->route('generate.riwayat')
                ->with('success', 'Batch #' . $batch->id . ' dibatalkan — stok dikembalikan, NO_SN dilepas, baris dipertahankan sebagai "dibatalkan" (audit).');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Batalkan draft (reversible — tanpa efek stok) (PRD 9). */
    public function hapusDraft(BatchGenerate $batch)
    {
        try {
            $this->service->hapusDraft($batch->id);

            return redirect()->route('generate.index')->with('success', 'Draft dibatalkan. Tidak ada efek ke stok.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Hapus 1 nota pada review. */
    public function hapusNota(TransaksiPenjualan $transaksiPenjualan)
    {
        try {
            $this->service->hapusNota($transaksiPenjualan);

            return back()->with('success', 'Nota ' . $transaksiPenjualan->ref_transaksi . ' dihapus dari draft.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Edit 1 baris item draft (qty/harga) pada review. */
    public function updateLine(Request $request, TransaksiPenjualanItem $item)
    {
        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'harga_jual' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->service->updateLine($item, (int) $validated['qty'], (int) $validated['harga_jual']);

            return back()->with('success', 'Baris diperbarui.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Riwayat batch generate (audit trail, PRD 9). */
    public function riwayat()
    {
        $batches = BatchGenerate::withCount('transaksiPenjualan')
            ->with(['transaksiPenjualan.items.barang'])
            ->latest()
            ->paginate(15);

        return view('generate.riwayat', compact('batches'));
    }
}
