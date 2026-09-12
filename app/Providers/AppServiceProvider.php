<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Models\Barang;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limiting login (anti brute force, PRD 1.1).
        // PENTING: didaftarkan di provider (bukan routes/web.php) agar tetap
        // aktif saat route:cache dipakai di hosting — file routes tidak
        // dieksekusi per-request bila route ter-cache (menyebabkan
        // "Rate limiter [login] is not defined" saat POST /login).
        RateLimiter::for('login', fn () => Limit::perMinute(5)->by(request()->ip()));

        // Data grafik dashboard (Batch G) — via composer agar controller tetap steril.
        View::composer('dashboard.index', \App\View\Composers\DashboardComposer::class);

        // Strip "Baru Ditambahkan" di halaman Data Barang (pindahan dari dashboard).
        View::composer('barang.index', function ($view) {
            $view->with('barangTerbaruStrip', Barang::where('tipe_item', 'barang')
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'kode_barang', 'nama', 'satuan', 'stock']));
        });
    }
}
