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
    private function diizinkan(): bool
    {
        // Runtime OR config: menu memakai app()->isLocal(), jadi gate harus
        // konsisten dengannya (config bisa beku bila pernah config:cache).
        return app()->isLocal() || (bool) config('deploy.allow_remote');
    }

    private function gate(): void
    {
        abort_unless($this->diizinkan(), 404);
    }

    private function infoSsh(): string
    {
        return config('deploy.ssh_user') . '@' . config('deploy.ssh_host') . ':' . config('deploy.ssh_port');
    }

    public function index()
    {
        $this->gate();
        $log = @file_get_contents((string) config('deploy.log')) ?: '';
        $running = file_exists((string) config('deploy.running'));

        return view('deploy.index', [
            'log' => $log,
            'running' => $running,
            'sshInfo' => $this->infoSsh(),
            'appEnv' => config('app.env'),
        ]);
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
        app(\App\Services\DeployRunner::class)->luncurkan($args);

        return redirect()->route('deploy.index')->with('success', 'Deploy dimulai di background. Pantau log di bawah (refresh otomatis).');
    }

    /** Uji koneksi SSH ke hosting (20 detik, tanpa mengubah apa pun). */
    public function testKoneksi()
    {
        $this->gate();
        $cfg = config('deploy');
        $cmd = ['ssh', '-p', (string) $cfg['ssh_port'], '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'StrictHostKeyChecking=accept-new'];
        if (! empty($cfg['ssh_key'])) {
            array_push($cmd, '-i', (string) $cfg['ssh_key']);
        }
        $cmd[] = $cfg['ssh_user'] . '@' . $cfg['ssh_host'];
        $cmd[] = 'echo SSH-OK';

        $hasil = Process::timeout(25)->run($cmd);
        if ($hasil->successful() && str_contains($hasil->output(), 'SSH-OK')) {
            return back()->with('success', 'Koneksi SSH OK (' . $this->infoSsh() . '). Siap kirim tanpa password.');
        }

        return back()->with('error', 'SSH gagal: ' . trim($hasil->errorOutput() ?: $hasil->output()) . ' — pasang key dulu: ssh-copy-id -p ' . $cfg['ssh_port'] . ' ' . $cfg['ssh_user'] . '@' . $cfg['ssh_host']);
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
