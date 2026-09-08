<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\InvoicePembelian;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BackupService;
use App\Services\PembelianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    /** Snapshot file backup FS-nyata sebelum test; hapus file baru sesudahnya. */
    private array $fileBackupAwal = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileBackupAwal = $this->semuaFileBackup();
    }

    protected function tearDown(): void
    {
        foreach (array_diff($this->semuaFileBackup(), $this->fileBackupAwal) as $baru) {
            @unlink($baru);
        }
        parent::tearDown();
    }

    private function semuaFileBackup(): array
    {
        $dir = BackupService::direktori();
        $files = array_merge(
            glob($dir . '/*.json.gz') ?: [],
            glob($dir . '/tmp/*.json.gz') ?: []
        );
        sort($files);

        return $files;
    }

    private function authUser(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'backup@a.id', 'password' => 'pass1234']);
        $this->actingAs($u);

        return $u;
    }

    private function seedTransaksi(): InvoicePembelian
    {
        $s = Supplier::create(['nama' => 'PT. Backup', 'kontak' => '0811']);
        $b = Barang::create([
            'tipe_item' => 'barang', 'kode_barang' => 'B-BKP', 'nama' => 'Ban Backup',
            'sn_atau_non_sn' => 'NON_SN',
        ]);

        return app(PembelianService::class)->simpan([
            'no_invoice' => 'INV-BKP',
            'supplier_id' => $s->id, 'tanggal_transaksi' => now()->format('Y-m-d H:i'),
            'jenis' => 'hutang', 'bayar_atau_dp' => 0, 'ppn' => 0,
            'items' => [['barang_id' => $b->id, 'harga_beli' => 50000, 'qty' => 4, 'diskon_item' => 0]],
        ]);
    }

    public function test_buat_backup_menyimpan_file_dan_manifest(): void
    {
        $this->authUser();
        $this->seedTransaksi();

        $hasil = app(BackupService::class)->buat();

        $this->assertFileExists(BackupService::direktori() . '/' . $hasil['nama']);
        $this->assertGreaterThan(0, $hasil['baris_total']);
        $this->assertSame(1, $hasil['tabel']['invoice_pembelian']);
        $this->assertSame(4, (int) Barang::where('kode_barang', 'B-BKP')->first()->stock);
        // Tabel transient tidak ikut
        $this->assertArrayNotHasKey('sessions', $hasil['tabel']);
        $this->assertArrayNotHasKey('cache', $hasil['tabel']);
        $this->assertArrayNotHasKey('jobs', $hasil['tabel']);
    }

    public function test_round_trip_restore_mengembalikan_data_persis(): void
    {
        $this->authUser();
        $inv = $this->seedTransaksi();
        $hasil = app(BackupService::class)->buat();
        $path = BackupService::direktori() . '/' . $hasil['nama'];

        // Ubah kondisi: hapus invoice + ubah pengaturan
        app(PembelianService::class)->hapus($inv, true);
        $this->assertSame(0, InvoicePembelian::count());

        $ringkas = app(BackupService::class)->restore($path);

        $this->assertSame(1, InvoicePembelian::count());
        $this->assertSame('INV-BKP', InvoicePembelian::first()->no_invoice);
        $this->assertSame(4, (int) Barang::where('kode_barang', 'B-BKP')->first()->stock);
        $this->assertGreaterThan(0, $ringkas['tabel']);
        // Pra-backup kondisi rusak tersimpan otomatis
        $this->assertFileExists(BackupService::direktori() . '/' . $ringkas['pra_backup']);
    }

    public function test_restore_file_rusak_ditolak_tanpa_mengubah_data(): void
    {
        $this->authUser();
        $this->seedTransaksi();
        $sebelum = InvoicePembelian::count();

        $palsu = BackupService::direktori() . '/tmp/rusak-test.json.gz';
        file_put_contents($palsu, gzencode('bukan-json'));

        try {
            app(BackupService::class)->restore($palsu);
            $this->fail('File rusak harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame($sebelum, InvoicePembelian::count());
    }

    public function test_restore_checksum_beda_ditolak(): void
    {
        $this->authUser();
        $this->seedTransaksi();
        $hasil = app(BackupService::class)->buat();
        $path = BackupService::direktori() . '/' . $hasil['nama'];

        // Ubah 1 nilai tanpa perbarui checksum
        $data = json_decode(gzdecode(file_get_contents($path)), true);
        $data['tables']['barang']['rows'][0]['stock'] = 999;
        file_put_contents($path, gzencode(json_encode($data)));

        try {
            app(BackupService::class)->restore($path);
            $this->fail('Checksum beda harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Checksum', $e->getMessage());
        }
        $this->assertSame(4, (int) Barang::where('kode_barang', 'B-BKP')->first()->stock);
    }

    public function test_restore_backup_versi_lama_tanpa_tabel_wajib_ditolak(): void
    {
        $this->authUser();
        $this->seedTransaksi();
        $hasil = app(BackupService::class)->buat();
        $path = BackupService::direktori() . '/' . $hasil['nama'];

        // Simulasi backup lama: buang 1 tabel wajib + perbaiki checksum sisanya
        $data = json_decode(gzdecode(file_get_contents($path)), true);
        unset($data['tables']['bulk_bayar_hutang']);
        file_put_contents($path, gzencode(json_encode($data)));

        try {
            app(BackupService::class)->restore($path);
            $this->fail('Tabel wajib hilang harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('bulk_bayar_hutang', $e->getMessage());
        }
        $this->assertSame(1, InvoicePembelian::count()); // utuh
    }

    public function test_restore_gagal_di_tengah_rollback_total(): void
    {
        $this->authUser();
        $this->seedTransaksi();
        $hasil = app(BackupService::class)->buat();
        $path = BackupService::direktori() . '/' . $hasil['nama'];

        // Sisipkan kolom tak dikenal + sesuaikan checksum agar lolos validasi awal
        $data = json_decode(gzdecode(file_get_contents($path)), true);
        $data['tables']['barang']['rows'][0]['kolom_palsu_xyz'] = 1;
        $data['tables']['barang']['checksum'] = sha1(json_encode($data['tables']['barang']['rows']));
        file_put_contents($path, gzencode(json_encode($data)));

        try {
            app(BackupService::class)->restore($path);
            $this->fail('Kolom tak dikenal harus menggagalkan restore.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('barang', $e->getMessage());
        }
        // Rollback: data lama utuh
        $this->assertSame(1, InvoicePembelian::count());
        $this->assertSame(4, (int) Barang::where('kode_barang', 'B-BKP')->first()->stock);
    }

    public function test_retensi_hanya_simpan_lima_terakhir(): void
    {
        $this->authUser();
        for ($i = 0; $i < 6; $i++) {
            app(BackupService::class)->buat();
            sleep(1); // nama file per detik — bedakan waktu
        }
        $files = glob(BackupService::direktori() . '/gentot-backup-*.json.gz') ?: [];
        $this->assertCount(5, $files);
    }

    public function test_http_alur_lengkap_dan_konfirmasi_ditolak(): void
    {
        $this->authUser();
        $this->seedTransaksi();

        // Buat via HTTP
        $this->post(route('backup.store'))->assertRedirect()->assertSessionHas('success');

        // Unduh
        $nama = app(BackupService::class)->daftar()[0]['nama'];
        $this->get(route('backup.download', $nama))->assertOk();

        // Unggah → pratinjau tampil
        $file = UploadedFile::fake()->createWithContent(
            'restore-test.json.gz',
            (string) file_get_contents(BackupService::direktori() . '/' . $nama)
        );
        $this->post(route('backup.upload'), ['file' => $file])->assertRedirect();
        $tmp = glob(BackupService::direktori() . '/tmp/upload-*.json.gz');
        $this->assertNotEmpty($tmp);
        $this->get(route('backup.preview', ['file' => basename($tmp[0])]))
            ->assertOk()->assertSee('Pratinjau Restore');

        // Konfirmasi salah → ditolak
        $this->post(route('backup.restore'), ['file' => basename($tmp[0]), 'konfirmasi' => 'restore'])
            ->assertSessionHas('error');
        $this->assertSame(1, InvoicePembelian::count());

        // Konfirmasi benar → jalan
        $this->post(route('backup.restore'), ['file' => basename($tmp[0]), 'konfirmasi' => 'RESTORE'])
            ->assertRedirect(route('backup.index'))->assertSessionHas('success');
        $this->assertSame(1, InvoicePembelian::count());
        $this->assertFalse(is_file($tmp[0])); // tmp dibersihkan
    }

    public function test_halaman_backup_hanya_admin(): void
    {
        $this->seedTransaksi();

        // Guest → login
        $this->get(route('backup.index'))->assertRedirect(route('login'));
        $this->post(route('backup.store'))->assertRedirect(route('login'));

        // Publik → 404
        $this->get('/publik/backup')->assertNotFound();
    }

}
