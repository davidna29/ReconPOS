<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportFileException;
use App\Models\ExportBatch;
use App\Models\Pph21Item;
use App\Models\Pph21Periode;
use App\Models\TokoSetting;
use App\Services\Pph21ExportService;
use App\Services\Pph21ImportService;
use App\Services\Pph21TerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Pph21Controller extends Controller
{
    public function __construct(
        private Pph21ImportService $impor,
        private Pph21ExportService $ekspor,
    ) {}

    public function index()
    {
        $periodes = Pph21Periode::withCount('items')->orderBy('tahun', 'desc')->orderBy('masa', 'desc')->paginate(12);
        $riwayat = ExportBatch::where('format', 'PPh21')->latest()->limit(10)->get();
        $npwp = (string) (TokoSetting::utama()->npwp ?? '');

        return view('pph21.index', compact('periodes', 'riwayat', 'npwp'));
    }

    public function storePeriode(Request $request)
    {
        $data = $request->validate([
            'masa' => ['required', 'integer', 'min:1', 'max:12'],
            'tahun' => ['required', 'integer', 'min:2000', 'max:2100'],
            'tgl_pemotongan' => ['nullable', 'date'],
        ]);

        $tgl = $data['tgl_pemotongan'] ?? sprintf('%04d-%02d-', $data['tahun'], $data['masa']).cal_days_in_month(CAL_GREGORIAN, $data['masa'], $data['tahun']);
        $npwp = preg_replace('/\D/', '', (string) (TokoSetting::utama()->npwp ?? ''));
        if (strlen($npwp) !== 16) {
            return back()->with('error', 'NPWP CV di Pengaturan belum 16 digit — isi dulu sebelum membuat periode.');
        }

        $periode = Pph21Periode::firstOrCreate(
            ['masa' => $data['masa'], 'tahun' => $data['tahun']],
            ['tgl_pemotongan' => $tgl, 'npwp_pemotong' => $npwp]
        );

        return redirect()->route('pph21.show', $periode)->with('success', 'Periode '.$periode->label().' siap — isi gaji lalu tarik Excel/XML.');
    }

    public function show(Pph21Periode $periode)
    {
        $periode->load(['items.karyawan']);

        return view('pph21.show', compact('periode'));
    }

    public function destroyPeriode(Pph21Periode $periode)
    {
        $periode->delete();

        return redirect()->route('pph21.index')->with('success', 'Periode '.$periode->label().' dihapus.');
    }

    public function downloadTemplateGaji(Pph21Periode $periode)
    {
        $nama = sprintf('template-gaji-%04d-%02d.xlsx', $periode->tahun, $periode->masa);

        return response()->download($this->impor->templateGaji($periode), $nama);
    }

    public function importGaji(Request $request, Pph21Periode $periode)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);

        try {
            $hasil = $this->impor->importGaji($periode, $request->file('file')->getRealPath());

            return redirect()->route('pph21.show', $periode)->with('success', 'Import gaji selesai: '.$hasil['dibuat'].' baru, '.$hasil['diupdate'].' diperbarui.');
        } catch (ImportFileException $e) {
            return back()->with('import_errors', [
                'judul' => 'Import gaji gagal divalidasi',
                'ringkasan' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Import excel gaji gagal di luar validasi', ['error' => $e->getMessage()]);

            return back()->with('import_errors', [
                'judul' => 'Gagal membaca file Excel',
                'ringkasan' => 'Terjadi kesalahan membaca file Excel — detail ada di storage/logs/laravel.log.',
                'errors' => [],
            ]);
        }
    }

    public function updateItem(Request $request, Pph21Item $item)
    {
        $data = $request->validate([
            'gross' => ['required', 'integer', 'min:0'],
            'rate_manual' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($data['rate_manual'] === null || $data['rate_manual'] === '') {
            $item->update([
                'gross' => $data['gross'],
                'rate' => Pph21TerService::hitung($item->ptkp, $data['gross']),
                'is_rate_manual' => false,
            ]);
        } else {
            $item->update([
                'gross' => $data['gross'],
                'rate' => round((float) $data['rate_manual'], 2),
                'is_rate_manual' => true,
            ]);
        }

        return back()->with('success', 'Data gaji diperbarui (tarif '.($item->is_rate_manual ? 'manual' : 'otomatis TER').').');
    }

    public function destroyItem(Pph21Item $item)
    {
        $periode = $item->periode;
        $item->delete();

        return redirect()->route('pph21.show', $periode)->with('success', 'Baris gaji dihapus dari periode ini.');
    }

    public function export(Pph21Periode $periode)
    {
        $hasil = $this->ekspor->export($periode);

        if (! $hasil['ok']) {
            return back()->with('import_errors', [
                'judul' => 'Export PPh 21 gagal',
                'ringkasan' => 'Pre-validasi gagal, file tidak dihasilkan.',
                'errors' => $hasil['errors'],
            ]);
        }

        return redirect()->route('pph21.index')
            ->with('success', 'Excel + XML periode '.$periode->label().' berhasil dibuat. Klik tautan di Riwayat Export.');
    }
}
