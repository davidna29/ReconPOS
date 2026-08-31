<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\PembelianController;
use App\Http\Controllers\PengaturanController;
use App\Http\Controllers\PenjualanController;
use App\Http\Controllers\SupplierController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Rate limiting login (anti brute force, PRD 1.1)
RateLimiter::for('login', fn () => Limit::perMinute(5)->by(request()->ip()));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

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
    Route::get('/pembelian/{invoice}', [PembelianController::class, 'show'])->name('pembelian.show');
    Route::get('/pembelian/{invoice}/edit', [PembelianController::class, 'edit'])->name('pembelian.edit');
    Route::put('/pembelian/{invoice}', [PembelianController::class, 'update'])->name('pembelian.update');
    Route::delete('/pembelian/{invoice}', [PembelianController::class, 'destroy'])->name('pembelian.destroy');
    Route::post('/pembelian/{invoice}/bayar', [PembelianController::class, 'bayarStore'])->name('pembelian.bayar');
    Route::get('/pembelian/{invoice}/print', [PembelianController::class, 'print'])->name('pembelian.print');

    // ---- Penjualan manual / kasir-lite ----
    Route::get('/penjualan', [PenjualanController::class, 'index'])->name('penjualan.index');
    Route::get('/penjualan/tambah', [PenjualanController::class, 'create'])->name('penjualan.create');
    Route::post('/penjualan', [PenjualanController::class, 'store'])->name('penjualan.store');
    Route::get('/penjualan/stok/{barang}', [PenjualanController::class, 'stokBarang'])->name('penjualan.stok');
    Route::delete('/penjualan/{transaksiPenjualan}', [PenjualanController::class, 'destroy'])->name('penjualan.destroy');

    // ---- Generate Transaksi (PRD 6, 7) ----
    Route::get('/generate', [GenerateController::class, 'index'])->name('generate.index');
    Route::post('/generate', [GenerateController::class, 'generate'])->name('generate.run');
    Route::get('/generate/riwayat', [GenerateController::class, 'riwayat'])->name('generate.riwayat');
    Route::get('/generate/{batch}', [GenerateController::class, 'review'])->name('generate.review');
    Route::post('/generate/{batch}/simpan', [GenerateController::class, 'simpan'])->name('generate.simpan');
    Route::delete('/generate/{batch}', [GenerateController::class, 'hapusDraft'])->name('generate.batal');
    Route::delete('/generate/nota/{transaksiPenjualan}', [GenerateController::class, 'hapusNota'])->name('generate.hapus-nota');
    Route::patch('/generate/item/{item}', [GenerateController::class, 'updateLine'])->name('generate.update-line');

    // ---- Export (PRD 8) ----
    Route::get('/export', [ExportController::class, 'index'])->name('export.index');
    Route::post('/export/format-a', [ExportController::class, 'downloadFormatA'])->name('export.format-a');
    Route::post('/export/format-b', [ExportController::class, 'downloadFormatB'])->name('export.format-b');
    Route::post('/export/{exportBatch}/tandai', [ExportController::class, 'tandaiUpload'])->name('export.tandai');
    Route::get('/export/{exportBatch}/unduh/{index}', [ExportController::class, 'unduh'])->name('export.unduh');

    // ---- Pengaturan (PRD 4.5) ----
    Route::get('/pengaturan', [PengaturanController::class, 'index'])->name('pengaturan.index');
    Route::post('/pengaturan', [PengaturanController::class, 'update'])->name('pengaturan.update');
    Route::post('/pengaturan/template', [PengaturanController::class, 'updateTemplate'])->name('pengaturan.template');
    Route::post('/pengaturan/template/reset', [PengaturanController::class, 'resetTemplate'])->name('pengaturan.template.reset');
});
