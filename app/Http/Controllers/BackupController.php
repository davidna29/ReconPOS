<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BackupController extends Controller
{
    public function __construct(
        private BackupService $backup,
    ) {}

    /** Laman Backup & Restore: buat, daftar, unduh, hapus, unggah. */
    public function index()
    {
        return view('backup.index', [
            'daftar' => $this->backup->daftar(),
        ]);
    }

    /** Buat backup dari DB saat ini. */
    public function store()
    {
        $hasil = $this->backup->buat();

        return back()->with('success', 'Backup ' . $hasil['nama'] . ' selesai: ' . number_format($hasil['baris_total'], 0, ',', '.') . ' baris.');
    }

    public function download(string $nama)
    {
        try {
            return response()->download($this->backup->pathUnduh($nama), $nama);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(string $nama)
    {
        try {
            $this->backup->hapus($nama);

            return back()->with('success', 'File ' . $nama . ' dihapus.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Langkah 1 restore: unggah file → validasi → teruskan ke pratinjau. */
    public function upload(Request $request)
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:20480']],
            ['file.max' => 'Ukuran file melebihi 20 MB — hubungi admin/pakai transfer manual.']
        );

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');
        try {
            $token = $this->backup->terimaUpload($file->getRealPath(), $file->getClientOriginalName());

            return redirect()->route('backup.preview', ['file' => $token]);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Langkah 2 restore: validasi + perbandingan per tabel (read-only). */
    public function preview(Request $request)
    {
        try {
            $path = $this->backup->pathTmp((string) $request->query('file'));
            $hasil = $this->backup->pratinjau($path);

            return view('backup.preview', [
                'token' => (string) $request->query('file'),
                'hasil' => $hasil,
            ]);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('backup.index')->with('error', $e->getMessage());
        }
    }

    /** Langkah 3 restore: ketik RESTORE → pra-backup otomatis → timpa atomik. */
    public function restore(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'string'],
            'konfirmasi' => ['required', 'string'],
        ]);
        if (trim($data['konfirmasi']) !== 'RESTORE') {
            return back()->with('error', 'Konfirmasi salah — ketik persis: RESTORE.')->withInput();
        }

        try {
            $path = $this->backup->pathTmp($data['file']);
            $hasil = $this->backup->restore($path);
            @unlink($path);

            return redirect()->route('backup.index')->with('success',
                'Restore selesai: ' . number_format((int) ($hasil['baris_total'] ?? 0), 0, ',', '.')
                . ' baris dari backup ' . ($hasil['created_at'] ?? '?')
                . ' (' . $hasil['tabel'] . ' tabel). Pra-backup kondisi sebelumnya tersimpan: ' . $hasil['pra_backup'] . ' — silakan login ulang bila sesi berakhir.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }
}
