<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * Tombol Kirim ke Hosting — HANYA di lokal (gate app()->isLocal() + config).
 * Menjalankan app:kirim-hosting sebagai proses background, log di-stream ke file.
 */
class DeployController extends Controller
{
    private function gate(): void
    {
        abort_unless((bool) config('deploy.allow_remote'), 404);
    }

    public function index()
    {
        $this->gate();
        $log = @file_get_contents((string) config('deploy.log')) ?: '';
        $running = file_exists((string) config('deploy.running'));

        return view('deploy.index', compact('log', 'running'));
    }

    public function kirim(Request $request)
    {
        $this->gate();
        if (file_exists((string) config('deploy.running'))) {
            return back()->with('error', 'Deploy sedang berjalan. Tunggu selesai dulu.');
        }
        $data = $request->validate([
            'konfirmasi' => ['required', 'string'],
            'dry_run' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
            'tanpa_test' => ['nullable', 'boolean'],
            'backup' => ['nullable', 'boolean'],
            'tanpa_maintenance' => ['nullable', 'boolean'],
        ]);
        if (trim($data['konfirmasi']) !== 'KIRIM') {
            return back()->with('error', 'Konfirmasi salah — ketik persis: KIRIM.')->withInput();
        }

        $args = array_filter([
            ! empty($data['dry_run']) ? '--dry-run' : null,
            ! empty($data['force']) ? '--force' : null,
            ! empty($data['tanpa_test']) ? '--tanpa-test' : null,
            ! empty($data['backup']) ? '--backup' : null,
            ! empty($data['tanpa_maintenance']) ? '--tanpa-maintenance' : null,
        ]);
        Process::start(array_merge([PHP_BINARY, base_path() . '/artisan', 'app:kirim-hosting'], $args), base_path());

        return redirect()->route('deploy.index')->with('success', 'Deploy dimulai di background. Pantau log di bawah (refresh otomatis).');
    }

    public function status()
    {
        $this->gate();

        return response()->json([
            'running' => file_exists((string) config('deploy.running')),
            'log' => @file_get_contents((string) config('deploy.log')) ?: '',
        ]);
    }
}
