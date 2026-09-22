@php($publik = $publik ?? false)
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($publik)<meta name="robots" content="noindex, nofollow">@endif
    <title>@yield('title', 'Dashboard') — ReconPOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        (function () {
            var pref = localStorage.getItem('theme');
            var dark = pref === 'dark' || (!pref && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
        })();
    </script>
</head>

<body class="flex min-h-screen bg-surface-muted font-sans text-on-surface">
    <!-- Overlay mobile (tanpa backdrop blur: konten & sidebar tetap tajam saat drawer terbuka) -->
    <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/40 md:hidden"></div>

    <!-- SideNavBar (replika struktur code.html, menu sesuai PRD) -->
    <nav id="app-sidebar" aria-label="Navigasi utama"
        class="fixed left-0 top-0 z-40 flex h-screen w-[240px] -translate-x-full flex-col border-r border-outline-variant bg-surface-container-low transition-all duration-200 md:z-20 md:translate-x-0">
        <div class="sidebar-brand mb-2 flex items-center gap-3 px-4 py-4">
            <div class="brand-tile">
                <span class="material-symbols-outlined" aria-hidden="true" data-icon="build">build</span>
            </div>
            <div class="sidebar-brand-text">
                <h1 class="text-headline-sm font-black tracking-tight text-primary">ReconPOS</h1>
                <p class="text-body-sm text-secondary">{{ $publik ? 'Mode Publik — Lihat Saja' : 'Automated Sales Reconstruction' }}</p>
            </div>
            <button id="theme-toggle" type="button" class="btn-icon ml-auto" title="Toggle gelap/terang">
                <span class="material-symbols-outlined text-[20px] dark-icon hidden" aria-hidden="true">light_mode</span>
                <span class="material-symbols-outlined text-[20px] light-icon" aria-hidden="true">dark_mode</span>
            </button>
        </div>

        <ul class="flex flex-1 flex-col gap-1 overflow-y-auto pb-2">
            <li class="nav-section" aria-hidden="true">Menu Utama</li>
            <li>
                <a class="nav-link {{ request()->routeIs('dashboard', 'publik.dashboard') ? 'nav-link-active' : '' }}" href="{{ route(($publik ? 'publik.' : '') . 'dashboard') }}" title="Dashboard" @if (request()->routeIs('dashboard', 'publik.dashboard')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-variation-settings: 'FILL' 1;">dashboard</span>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('barang.*', 'publik.barang.*') ? 'nav-link-active' : '' }}" href="{{ route(($publik ? 'publik.' : '') . 'barang.index') }}" title="Data Barang" @if (request()->routeIs('barang.*', 'publik.barang.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                    <span class="nav-label">Data Barang</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('stok-tracking.*', 'publik.stok-tracking.*') ? 'nav-link-active' : '' }}" href="{{ route(($publik ? 'publik.' : '') . 'stok-tracking.index') }}" title="Tracking Stok" @if (request()->routeIs('stok-tracking.*', 'publik.stok-tracking.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">history</span>
                    <span class="nav-label">Tracking Stok</span>
                </a>
            </li>
            @if (! $publik)
            <li class="nav-section" aria-hidden="true">Admin</li>
            <li>
                <a class="nav-link {{ request()->routeIs('supplier.*') ? 'nav-link-active' : '' }}" href="{{ route('supplier.index') }}" title="Data Supplier" @if (request()->routeIs('supplier.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
                    <span class="nav-label">Data Supplier</span>
                </a>
            </li>
            @endif
            <li>
                <a class="nav-link {{ request()->routeIs('pembelian.*', 'publik.pembelian.*') ? 'nav-link-active' : '' }}" href="{{ route(($publik ? 'publik.' : '') . 'pembelian.invoice-hutang') }}" title="Pembelian" @if (request()->routeIs('pembelian.*', 'publik.pembelian.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
                    <span class="nav-label">Pembelian</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('penjualan.*', 'publik.penjualan.*') ? 'nav-link-active' : '' }}" href="{{ route(($publik ? 'publik.' : '') . 'penjualan.index') }}" title="Penjualan" @if (request()->routeIs('penjualan.*', 'publik.penjualan.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">point_of_sale</span>
                    <span class="nav-label">Penjualan</span>
                </a>
            </li>
            @if (! $publik)
            <li>
                <a class="nav-link {{ request()->routeIs('generate.*') ? 'nav-link-active' : '' }}" href="{{ route('generate.index') }}" title="Generate Transaksi" @if (request()->routeIs('generate.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span>
                    <span class="nav-label">Generate Transaksi</span>
                </a>
            </li>
            @endif
            @if (! $publik)
            <li>
                <a class="nav-link {{ request()->routeIs('export.*') ? 'nav-link-active' : '' }}" href="{{ route('export.index') }}" title="Export" @if (request()->routeIs('export.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">file_download</span>
                    <span class="nav-label">Export</span>
                </a>
            </li>
            @endif
            @if (! $publik)
            <li>
                <a class="nav-link {{ request()->routeIs('pengaturan.*') ? 'nav-link-active' : '' }}" href="{{ route('pengaturan.index') }}" title="Pengaturan" @if (request()->routeIs('pengaturan.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">settings</span>
                    <span class="nav-label">Pengaturan</span>
                </a>
            </li>
            @endif
            @if (! $publik)
            <li>
                <a class="nav-link {{ request()->routeIs('backup.*') ? 'nav-link-active' : '' }}" href="{{ route('backup.index') }}" title="Backup & Restore database" @if (request()->routeIs('backup.*')) aria-current="page" @endif>
                    <span class="material-symbols-outlined" aria-hidden="true">backup</span>
                    <span class="nav-label">Backup</span>
                </a>
            </li>
            @endif
            @if (! $publik)
            <li>
                <a class="nav-link" href="{{ route('publik.landing') }}" target="_blank" rel="noopener" title="Lihat Mode Publik (tab baru)">
                    <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                    <span class="nav-label">Lihat Publik</span>
                </a>
            </li>
            @endif
        </ul>

        @if (! $publik)
        <div class="mt-auto border-t border-outline-variant px-2 py-3">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="nav-link w-full text-left hover:text-danger-red" title="Logout">
                        <span class="material-symbols-outlined" aria-hidden="true">logout</span>
                        <span class="nav-label">Logout</span>
                    </button>
                </form>
        </div>
        @endif
    </nav>

    <!-- Main Content Area -->
    <main class="flex min-h-screen flex-1 flex-col md:ml-[240px]">
        <!-- TopNavBar -->
        <header class="sticky top-0 z-10 flex h-16 w-full items-center justify-between gap-3 border-b border-outline-variant bg-surface-container-lowest/85 px-4 backdrop-blur-md md:px-margin-page">
            <div class="flex min-w-0 items-center gap-3">
                <button id="sidebar-toggle" class="shrink-0 rounded p-2 text-on-surface-variant transition-transform hover:bg-surface-container active:scale-95" title="Buka/tutup (mobile) atau minimize (desktop)">
                    <span class="material-symbols-outlined" aria-hidden="true">menu</span>
                </button>
                <div class="hidden truncate text-headline-md font-bold tracking-tight text-primary md:block">
                    @yield('page-title', \App\Models\TokoSetting::utama()->nama_toko)
                </div>
            </div>

            <div class="flex items-center gap-2">
                @yield('topbar-actions')
                @if ($publik)
                    <span class="hidden rounded-full bg-secondary-container px-3 py-1 text-label-caps font-bold text-on-secondary-container sm:block">
                        Mode Publik
                    </span>
                    <div class="ml-2 flex h-8 w-8 items-center justify-center rounded-full border border-outline-variant bg-primary text-on-primary" title="Tanpa login (hanya lihat)">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">public</span>
                    </div>
                @else
                    <span class="hidden rounded-full bg-secondary-container px-3 py-1 text-label-caps font-bold text-on-secondary-container sm:block">
                        Admin
                    </span>
                    <div class="ml-2 flex h-8 w-8 items-center justify-center rounded-full border border-outline-variant bg-primary text-label-caps font-bold text-on-primary">
                        {{ strtoupper(substr(auth()->user()?->name ?? 'A', 0, 1)) }}
                    </div>
                @endif
            </div>
        </header>

        <!-- Content -->
        <div class="mx-auto w-full max-w-[1400px] flex-1 p-4 md:p-margin-page">
            @include('partials.flash')
            <div class="flex flex-col gap-6">
                @yield('content')
            </div>
        </div>

        <footer class="mx-auto w-full max-w-[1400px] px-4 pb-4 md:px-margin-page">
            <p class="border-t border-outline-variant pt-3 text-center text-body-sm text-secondary">
                {{ \App\Models\TokoSetting::utama()->nama_toko }} · ReconPOS{{ $publik ? ' · Mode Publik' : '' }}
            </p>
        </footer>
    </main>

    @stack('scripts')
</body>

</html>
