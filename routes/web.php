<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\BebanHutangController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeployController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\PembelianController;
use App\Http\Controllers\PengaturanController;
use App\Http\Controllers\PenjualanController;
use App\Http\Controllers\RekapStokController;
use App\Http\Controllers\StokTrackingController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\Publik\BarangController as PublikBarangController;
use App\Http\Controllers\Publik\DashboardController as PublikDashboardController;
use App\Http\Controllers\Publik\LandingController as PublikLandingController;
use App\Http\Controllers\Publik\PembelianController as PublikPembelianController;
use App\Http\Controllers\Publik\PenjualanController as PublikPenjualanController;
use App\Http\Controllers\Publik\PinController as PublikPinController;
use App\Http\Controllers\Publik\BebanHutangController as PublikBebanHutangController;
use App\Http\Controllers\Publik\RekapStokController as PublikRekapStokController;
use App\Http\Controllers\Publik\StokTrackingController as PublikStokTrackingController;
use App\Http\Middleware\PublikPinMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Halaman utama: tamu ke landing publik CTA, admin ke dashboard admin.
Route::get('/', function () {
    return auth()->check()
        ? app(DashboardController::class)->index(request())
        : redirect()->route('publik.landing');
})->name('dashboard');

Route::middleware('auth')->group(function () {
    // ---- Data Barang (PRD 4.3) ----
    Route::get('/barang', [BarangController::class, 'index'])->name('barang.index');
    Route::get('/barang/tambah', [BarangController::class, 'create'])->name('barang.create');
    Route::get('/barang/cari', [BarangController::class, 'cari'])->name('barang.cari');
    Route::get('/barang/template/download', [BarangController::class, 'downloadTemplate'])->name('barang.template.download');
    Route::post('/barang/template/import', [BarangController::class, 'importTemplate'])->name('barang.template.import');
    Route::get('/barang/template-harga/download', [BarangController::class, 'downloadTemplateHarga'])->name('barang.templateHarga.download');
    Route::post('/barang/template-harga/import', [BarangController::class, 'importUpdateHarga'])->name('barang.templateHarga.import');
    Route::post('/barang', [BarangController::class, 'store'])->name('barang.store');
    Route::get('/barang/{barang}', [BarangController::class, 'show'])->name('barang.show');
    Route::get('/barang/{barang}/edit', [BarangController::class, 'edit'])->name('barang.edit');
    Route::put('/barang/{barang}', [BarangController::class, 'update'])->name('barang.update');
    Route::delete('/barang/{barang}', [BarangController::class, 'destroy'])->name('barang.destroy');
    Route::get('/barang/{barang}/kartu-stok', [BarangController::class, 'kartuStok'])->name('barang.kartuStok');
    Route::post('/barang/{barang}/opname', [BarangController::class, 'opnameStore'])->name('barang.opname');
    Route::post('/barang/{barang}/stok-awal', [BarangController::class, 'stokAwalStore'])->name('barang.stokAwal');
    Route::get('/barang/{barang}/barcode', [BarangController::class, 'cetakBarcode'])->name('barang.barcode');

    // ---- Tracking Stok ----
    Route::get('/stok-tracking', [StokTrackingController::class, 'index'])->name('stok-tracking.index');

    // ---- Rekap Stok per Merk (Merk → Varian → Ukuran) ----
    Route::get('/rekap-stok', [RekapStokController::class, 'index'])->name('rekap-stok.index');

    // ---- Beban Hutang per Cabang (pemilahan + rekap, hutang saja) ----
    Route::get('/beban-hutang', [BebanHutangController::class, 'index'])->name('beban-hutang.index');
    Route::post('/beban-hutang/tetapkan', [BebanHutangController::class, 'tetapkan'])->name('beban-hutang.tetapkan');
    Route::get('/beban-hutang/template/download', [BebanHutangController::class, 'downloadTemplate'])->name('beban-hutang.template.download');
    Route::post('/beban-hutang/template/import', [BebanHutangController::class, 'importTemplate'])->name('beban-hutang.template.import');

    // ---- Data Supplier (PRD 4.4) ----
    Route::get('/supplier', [SupplierController::class, 'index'])->name('supplier.index');
    Route::get('/supplier/tambah', [SupplierController::class, 'create'])->name('supplier.create');
    Route::post('/supplier', [SupplierController::class, 'store'])->name('supplier.store');
    Route::get('/supplier/{supplier}/riwayat', [SupplierController::class, 'riwayat'])->name('supplier.riwayat');
    Route::get('/supplier/{supplier}/edit', [SupplierController::class, 'edit'])->name('supplier.edit');
    Route::put('/supplier/{supplier}', [SupplierController::class, 'update'])->name('supplier.update');
    Route::delete('/supplier/{supplier}', [SupplierController::class, 'destroy'])->name('supplier.destroy');

    // ---- Pembelian (PRD 5) ----
    Route::get('/pembelian/transaksi', [PembelianController::class, 'create'])->name('pembelian.create');
    Route::get('/pembelian/template/download', [PembelianController::class, 'downloadTemplate'])->name('pembelian.template.download');
    Route::post('/pembelian/template/import', [PembelianController::class, 'import'])->name('pembelian.template.import');
    Route::post('/pembelian', [PembelianController::class, 'store'])->name('pembelian.store');
    Route::get('/pembelian/hutang', [PembelianController::class, 'invoiceHutang'])->name('pembelian.invoice-hutang');
    Route::get('/pembelian/lunas', [PembelianController::class, 'invoiceLunas'])->name('pembelian.invoice-lunas');
    Route::get('/pembelian/bayar-bulk', [PembelianController::class, 'bayarBulkIndex'])->name('pembelian.bayar-bulk.index');
    Route::get('/pembelian/{invoice}', [PembelianController::class, 'show'])->name('pembelian.show');
    Route::get('/pembelian/{invoice}/edit', [PembelianController::class, 'edit'])->name('pembelian.edit');
    Route::put('/pembelian/{invoice}', [PembelianController::class, 'update'])->name('pembelian.update');
    Route::delete('/pembelian/{invoice}', [PembelianController::class, 'destroy'])->name('pembelian.destroy');
    Route::post('/pembelian/{invoice}/bayar', [PembelianController::class, 'bayarStore'])->name('pembelian.bayar');
    Route::post('/pembelian/bayar-bulk/pratinjau', [PembelianController::class, 'bayarBulkPreview'])->name('pembelian.bayar-bulk.preview');
    Route::post('/pembelian/bayar-bulk/konfirmasi', [PembelianController::class, 'bayarBulkConfirm'])->name('pembelian.bayar-bulk.confirm');
    Route::delete('/pembelian/bayar-bulk/{grup}', [PembelianController::class, 'bayarBulkCancel'])->name('pembelian.bayar-bulk.cancel');
    Route::delete('/pembelian/bayar/{pembayaran}', [PembelianController::class, 'bayarDestroy'])->name('pembelian.bayar.destroy');
    Route::get('/pembelian/{invoice}/print', [PembelianController::class, 'print'])->name('pembelian.print');
    Route::post('/pembelian/print-batch', [PembelianController::class, 'printBatch'])->name('pembelian.print-batch');
    Route::post('/pembelian/bulk-delete', [PembelianController::class, 'bulkDelete'])->name('pembelian.bulk-delete');

    // ---- Penjualan manual / kasir-lite ----
    Route::get('/penjualan', [PenjualanController::class, 'index'])->name('penjualan.index');
    Route::get('/penjualan/tambah', [PenjualanController::class, 'create'])->name('penjualan.create');
    Route::post('/penjualan', [PenjualanController::class, 'store'])->name('penjualan.store');
    Route::get('/penjualan/{transaksiPenjualan}/edit', [PenjualanController::class, 'edit'])->name('penjualan.edit');
    Route::get('/penjualan/{transaksiPenjualan}/print', [PenjualanController::class, 'print'])->name('penjualan.print');
    Route::post('/penjualan/print-batch', [PenjualanController::class, 'printBatch'])->name('penjualan.print-batch');
    Route::post('/penjualan/bulk-delete', [PenjualanController::class, 'bulkDelete'])->name('penjualan.bulk-delete');
    Route::put('/penjualan/{transaksiPenjualan}', [PenjualanController::class, 'update'])->name('penjualan.update');
    Route::get('/penjualan/stok/{barang}', [PenjualanController::class, 'stokBarang'])->name('penjualan.stok');
    Route::delete('/penjualan/{transaksiPenjualan}', [PenjualanController::class, 'destroy'])->name('penjualan.destroy');

    // ---- Generate Transaksi (PRD 6, 7) ----
    Route::get('/generate', [GenerateController::class, 'index'])->name('generate.index');
    Route::post('/generate', [GenerateController::class, 'generate'])->name('generate.run');
    Route::get('/generate/riwayat', [GenerateController::class, 'riwayat'])->name('generate.riwayat');
    Route::get('/generate/{batch}', [GenerateController::class, 'review'])->name('generate.review');
    Route::post('/generate/{batch}/simpan', [GenerateController::class, 'simpan'])->name('generate.simpan');
    Route::post('/generate/{batch}/batalkan', [GenerateController::class, 'batalkan'])->name('generate.batalkan');
    Route::delete('/generate/{batch}', [GenerateController::class, 'hapusDraft'])->name('generate.batal');
    Route::delete('/generate/nota/{transaksiPenjualan}', [GenerateController::class, 'hapusNota'])->name('generate.hapus-nota');
    Route::patch('/generate/item/{item}', [GenerateController::class, 'updateLine'])->name('generate.update-line');
    Route::delete('/generate/{batch}/hapus', [GenerateController::class, 'hapusBatch'])->name('generate.hapus-batch');

    // ---- Export (PRD 8) ----
    Route::get('/export', [ExportController::class, 'index'])->name('export.index');
    Route::post('/export/format-a', [ExportController::class, 'downloadFormatA'])->name('export.format-a');
    Route::post('/export/format-b', [ExportController::class, 'downloadFormatB'])->name('export.format-b');
    Route::post('/export/format-b-pdf', [ExportController::class, 'downloadFormatBPdf'])->name('export.format-b-pdf');
    Route::post('/export/{exportBatch}/tandai', [ExportController::class, 'tandaiUpload'])->name('export.tandai');
    Route::delete('/export/{exportBatch}', [ExportController::class, 'destroy'])->name('export.destroy');
    Route::get('/export/{exportBatch}/unduh/{index}', [ExportController::class, 'unduh'])->name('export.unduh');

    // ---- Backup & Restore (database) ----
    Route::get('/backup', [BackupController::class, 'index'])->name('backup.index');
    Route::post('/backup/buat', [BackupController::class, 'store'])->name('backup.store');
    Route::get('/backup/unduh/{nama}', [BackupController::class, 'download'])->name('backup.download');
    Route::delete('/backup/{nama}', [BackupController::class, 'destroy'])->name('backup.destroy');
    Route::post('/backup/unggah', [BackupController::class, 'upload'])->name('backup.upload');
    Route::get('/backup/pratinjau', [BackupController::class, 'preview'])->name('backup.preview');
    Route::post('/backup/pulihkan', [BackupController::class, 'restore'])->name('backup.restore');

    // ---- Kirim ke Hosting (hanya lokal — gate di controller, 404 di hosting) ----
    Route::get('/kirim-hosting', [DeployController::class, 'index'])->name('deploy.index');
    Route::post('/kirim-hosting', [DeployController::class, 'kirim'])->name('deploy.kirim');
    Route::get('/kirim-hosting/status', [DeployController::class, 'status'])->name('deploy.status');
    Route::post('/kirim-hosting/test-koneksi', [DeployController::class, 'testKoneksi'])->name('deploy.test-koneksi');

    // ---- Pengaturan (PRD 4.5) ----
    Route::get('/pengaturan', [PengaturanController::class, 'index'])->name('pengaturan.index');
    Route::post('/pengaturan', [PengaturanController::class, 'update'])->name('pengaturan.update');
    Route::post('/pengaturan/terapkan-margin', [PengaturanController::class, 'terapkanMargin'])->name('pengaturan.terapkan-margin');
    Route::post('/pengaturan/password', [PengaturanController::class, 'updatePassword'])->name('pengaturan.password');
    Route::post('/pengaturan/template', [PengaturanController::class, 'updateTemplate'])->name('pengaturan.template');
    Route::post('/pengaturan/template/reset', [PengaturanController::class, 'resetTemplate'])->name('pengaturan.template.reset');
    Route::post('/pengaturan/publik-pin', [PengaturanController::class, 'updatePublikPin'])->name('pengaturan.publik-pin');
});

// ============================================================================
// MODE PUBLIK (tanpa login) — read-only.
// Menu yang TAMPIL: Landing CTA + Dashboard, Data Barang, Tracking Stok, Pembelian, Penjualan.
// Menu yang DITUTUP: Data Supplier, Generate Transaksi, Export, Pengaturan, Backup
//   (tidak ada route -> 404). Semua aksi mutasi TIDAK didaftarkan di sini.
// View admin dipakai ulang dengan flag $publik = true (guard @if(! $publik)).
// PIN opsional: bila aktif, semua route kecuali pin.* butuh session publik_authenticated
//   (atau auth()->check() bypass untuk admin).
// ============================================================================
Route::prefix('publik')->name('publik.')->group(function () {
    // PIN (di luar middleware agar tidak loop)
    Route::get('/pin', [PublikPinController::class, 'form'])->name('pin.form');
    Route::post('/pin', [PublikPinController::class, 'verify'])->name('pin.verify');
    Route::post('/pin/clear', [PublikPinController::class, 'clear'])->name('pin.clear');

    Route::middleware(PublikPinMiddleware::class)->group(function () {
        Route::get('/landing', [PublikLandingController::class, 'index'])->name('landing');
        Route::get('/', [PublikDashboardController::class, 'index'])->name('dashboard');

    Route::get('/barang', [PublikBarangController::class, 'index'])->name('barang.index');
    Route::get('/barang/{barang}', [PublikBarangController::class, 'show'])->name('barang.show');
    Route::get('/barang/{barang}/kartu-stok', [PublikBarangController::class, 'kartuStok'])->name('barang.kartuStok');
    Route::get('/barang/{barang}/barcode', [PublikBarangController::class, 'cetakBarcode'])->name('barang.barcode');

    Route::get('/stok-tracking', [PublikStokTrackingController::class, 'index'])->name('stok-tracking.index');

    Route::get('/rekap-stok', [PublikRekapStokController::class, 'index'])->name('rekap-stok.index');

    Route::get('/beban-hutang', [PublikBebanHutangController::class, 'index'])->name('beban-hutang.index');

    Route::get('/pembelian/hutang', [PublikPembelianController::class, 'hutang'])->name('pembelian.invoice-hutang');
    Route::get('/pembelian/lunas', [PublikPembelianController::class, 'lunas'])->name('pembelian.invoice-lunas');
    Route::get('/pembelian/{invoice}', [PublikPembelianController::class, 'show'])->name('pembelian.show');
    Route::get('/pembelian/{invoice}/print', [PublikPembelianController::class, 'print'])->name('pembelian.print');

        Route::get('/penjualan', [PublikPenjualanController::class, 'index'])->name('penjualan.index');
        Route::get('/penjualan/{transaksiPenjualan}/print', [PublikPenjualanController::class, 'print'])->name('penjualan.print');
    });
});
