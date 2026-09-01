<?php

namespace App\Http\Controllers;

use App\Models\ExportBatch;
use App\Models\TokoSetting;
use App\Services\ExportPosService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ExportController extends Controller
{
    public function __construct(private ExportPosService $service) {}

    public function index()
    {
        $batches = ExportBatch::latest()->limit(20)->get();
        $mapping = $this->service->mappingAktif();

        return view('export.index', compact('batches', 'mapping'));
    }

    /** Export Format A — siap import POS Bengkel (PRD 8b). */
    public function downloadFormatA(Request $request)
    {
        $validated = $request->validate([
            'periode_mulai' => ['nullable', 'date'],
            'periode_selesai' => ['nullable', 'date'],
            'bulan' => ['nullable', 'date_format:Y-m'],
        ]);

        try {
            $hasil = $this->service->buildFormatA(
                $validated['periode_mulai'] ?? null,
                $validated['periode_selesai'] ?? null,
                $validated['bulan'] ?? null,
            );

            if (! $hasil['ok']) {
                return back()->withErrors(['export' => 'Pre-validasi gagal, file tidak dihasilkan: ' . implode(' | ', array_slice($hasil['errors'], 0, 10))]);
            }

            if (count($hasil['files']) === 1) {
                return response()->download(
                    $this->service->pathFile($hasil['files'][0]),
                    $hasil['files'][0],
                );
            }

            return redirect()->route('export.index')
                ->with('success', 'File terpecah menjadi ' . count($hasil['files']) . ' bagian (maks 5.000 baris/file). Klik tautan di bawah Riwayat Export.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Export Format B — laporan/arsip internal (PRD 8c). */
    public function downloadFormatB(Request $request)
    {
        $validated = $request->validate([
            'periode_mulai' => ['nullable', 'date'],
            'periode_selesai' => ['nullable', 'date'],
            'bulan' => ['nullable', 'date_format:Y-m'],
            'sumber' => ['nullable', 'in:manual,rekonstruksi'],
        ]);

        try {
            $hasil = $this->service->buildFormatB(
                $validated['periode_mulai'] ?? null,
                $validated['periode_selesai'] ?? null,
                $validated['bulan'] ?? null,
                $validated['sumber'] ?? null,
            );

            return response()->download($this->service->pathFile($hasil['files'][0]), $hasil['files'][0]);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Unduh file export tertentu (divalidasi terhadap export_batch — PRD 5.5 keamanan file). */
    public function unduh(ExportBatch $exportBatch, int $index)
    {
        $names = $exportBatch->file_names ?? [];
        if (! isset($names[$index])) {
            abort(404, 'File tidak ditemukan.');
        }
        $path = $this->service->pathFile($names[$index]);
        if (! file_exists($path)) {
            abort(404, 'File sudah tidak ada di penyimpanan.');
        }

        return response()->download($path, $names[$index]);
    }

    /** Tandai batch export sudah diupload ke POS Bengkel (jejak 4.2). */
    public function tandaiUpload(ExportBatch $exportBatch)
    {
        $exportBatch->status_upload_manual = ! $exportBatch->status_upload_manual;
        $exportBatch->save();

        return back()->with('success', $exportBatch->status_upload_manual
            ? 'Batch ditandai sudah diupload ke POS.'
            : 'Tanda "sudah diupload" dihapus.');
    }

    /** Hapus riwayat export + file fisiknya (PRD 8 jejak; file regenerable). */
    public function destroy(ExportBatch $exportBatch)
    {
        $this->service->hapusBatch($exportBatch);

        return back()->with(
            'success',
            'Riwayat export Format ' . $exportBatch->format . ' (' . $exportBatch->created_at->format('d/m/Y H:i') . ') dihapus beserta file-nya.'
        );
    }
}
