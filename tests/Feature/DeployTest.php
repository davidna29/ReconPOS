<?php

namespace Tests\Feature;

use App\Console\Commands\KirimHosting;
use App\Console\Commands\SambungPublic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DeployTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'deploy@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    // ---------- gate: non-lokal = 404 ----------

    public function test_halaman_deploy_404_bila_tidak_diizinkan(): void
    {
        config(['deploy.allow_remote' => false]);
        $this->authUser();

        $this->get(route('deploy.index'))->assertNotFound();
        $this->post(route('deploy.kirim'), ['konfirmasi' => 'KIRIM'])->assertNotFound();
        $this->get(route('deploy.status'))->assertNotFound();
    }

    public function test_menu_kirim_hosting_sembunyi_di_non_lokal(): void
    {
        $this->authUser();
        // APP_ENV=testing (bukan local) -> menu tak tampil
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Kirim Hosting');
    }

    public function test_konfirmasi_salah_ditolak(): void
    {
        config(['deploy.allow_remote' => true]);
        $this->authUser();

        $this->post(route('deploy.kirim'), ['konfirmasi' => 'kirim'])
            ->assertSessionHas('error');
    }

    public function test_kirim_sukses_tanpa_menjalankan_asli(): void
    {
        config(['deploy.allow_remote' => true]);
        $this->authUser();
        $this->mock(\App\Services\DeployRunner::class, function ($m) {
            $m->shouldReceive('luncurkan')->once()->with(['--dry-run'])->andReturnNull();
        });

        $this->post(route('deploy.kirim'), ['konfirmasi' => 'KIRIM', 'dry_run' => '1'])
            ->assertRedirect(route('deploy.index'))
            ->assertSessionHas('success');
    }

    public function test_perintah_detached_selamat_dari_akhir_request(): void
    {
        // Regresi insiden Okt 2026: Process::start() tanpa wait() dibunuh
        // destructor saat request selesai -> log tak pernah terisi.
        $cmd = \App\Services\DeployRunner::perintahDetached(['--dry-run']);

        $this->assertStringContainsString('nohup', $cmd);
        $this->assertStringContainsString('app:kirim-hosting', $cmd);
        $this->assertStringContainsString('--dry-run', $cmd);
        $this->assertStringContainsString((string) config('deploy.log'), $cmd);
        $this->assertTrue((bool) preg_match('/2>&1 &/', $cmd), 'Harus background detached: ' . $cmd);
        $this->assertStringNotContainsString('Process::start', $cmd);
    }

    public function test_test_koneksi_ok(): void
    {
        config(['deploy.allow_remote' => true]);
        $this->authUser();

        \Illuminate\Support\Facades\Process::fake(['*SSH-OK*' => \Illuminate\Support\Facades\Process::result('SSH-OK')]);
        $this->post(route('deploy.test-koneksi'))->assertSessionHas('success');
    }

    public function test_test_koneksi_gagal(): void
    {
        config(['deploy.allow_remote' => true]);
        $this->authUser();

        // Catatan: Process::fake() me-merge handler antar panggilan, jadi
        // skenario ok & gagal dipisah ke dua test agar pola tak bertabrakan.
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::result('Permission denied', '', 255)]);
        $this->post(route('deploy.test-koneksi'))->assertSessionHas('error');
    }

    public function test_tolak_bila_sedang_berjalan(): void
    {
        config(['deploy.allow_remote' => true]);
        $this->authUser();
        file_put_contents((string) config('deploy.running'), '{}');

        $this->post(route('deploy.kirim'), ['konfirmasi' => 'KIRIM'])
            ->assertSessionHas('error');

        @unlink((string) config('deploy.running'));
    }

    // ---------- pure helpers ----------

    public function test_exclude_melindungi_file_rahasia_dan_data(): void
    {
        $flat = implode(' ', KirimHosting::opsiExclude());
        foreach (['.env*', 'vendor/', 'node_modules/', '.git/', 'tests/', 'database/*.sqlite*', 'storage/logs/*', 'storage/app/private/backups/*', 'storage/app/private/export/*'] as $wajib) {
            $this->assertStringContainsString($wajib, $flat);
        }
    }

    public function test_kandidat_path_dua_layout_hostinger(): void
    {
        $this->assertSame(
            ['/home/u1/domains/contoh.id/reconpos', '/home/u1/domains/contoh.id/public_html/reconpos'],
            KirimHosting::kandidatPath('contoh.id', 'u1')
        );
    }

    public function test_expand_home_hanya_awalan_tilde(): void
    {
        $this->assertSame('/home/u1/x/y', KirimHosting::expandHome('~/x/y', 'u1'));
        $this->assertSame('/sudah/absolut', KirimHosting::expandHome('/sudah/absolut', 'u1'));
    }

    public function test_prefix_relatif_kedua_layout(): void
    {
        // sejajar: ../reconpos
        $this->assertSame('../reconpos', SambungPublic::prefixRelatif('/home/u/domains/a.id/public_html', '/home/u/domains/a.id/reconpos'));
        // di dalam docroot: /reconpos
        $this->assertSame('reconpos', SambungPublic::prefixRelatif('/home/u/domains/a.id/public_html', '/home/u/domains/a.id/public_html/reconpos'));
        $this->assertStringContainsString('../reconpos/vendor/autoload.php', SambungPublic::templateIndex('../reconpos'));
    }

    public function test_docroot_otomatis(): void
    {
        $this->assertSame('/home/u/domains/a.id/public_html', SambungPublic::docrootOtomatis('/home/u/domains/a.id/public_html/reconpos'));
        $this->assertSame('/home/u/domains/a.id/public_html', SambungPublic::docrootOtomatis('/home/u/domains/a.id/reconpos'));
    }

    // ---------- dry-run ujung ke ujung (tanpa jaringan, Process fake) ----------

    public function test_dry_run_berhasil_tanpa_mengubah_server(): void
    {
        config(['deploy.allow_remote' => true]);
        $log = tempnam(sys_get_temp_dir(), 'dpl-log');
        $run = tempnam(sys_get_temp_dir(), 'dpl-run');
        @unlink($run);
        config(['deploy.log' => $log, 'deploy.running' => $run]);

        // Catatan: command array di-escape dgn quote ('echo' 'SSH-OK'),
        // jadi pola harus menarget token tunggal, bukan frasa berspasi.
        Process::fake([
            '*SSH-OK*' => Process::result("SSH-OK\nPHP 8.4.23 (cli)"),
            '*test*-d*' => Process::result('ADA'),
            '*' => Process::result(''),
        ]);

        $this->artisan('app:kirim-hosting', [
            '--dry-run' => true, '--force' => true, '--tanpa-test' => true,
        ])->assertExitCode(0);

        $isi = (string) file_get_contents($log);
        $this->assertStringContainsString('MODE DRY-RUN', $isi);
        $this->assertStringContainsString('rsync', $isi);
        $this->assertStringContainsString('AKAN dijalankan', $isi);
        $this->assertFileDoesNotExist($run); // marker dibersihkan
        @unlink($log);
    }

    public function test_command_menolak_bila_gate_mati(): void
    {
        config(['deploy.allow_remote' => false]);

        $this->artisan('app:kirim-hosting', ['--dry-run' => true])->assertExitCode(1);
    }
}
