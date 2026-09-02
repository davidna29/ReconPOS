<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — Mechanic Hub</title>
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
    <nav id="app-sidebar"
        class="fixed left-0 top-0 z-40 flex h-screen w-[240px] -translate-x-full flex-col border-r border-outline-variant bg-surface-container-low transition-all duration-200 md:z-20 md:translate-x-0">
        <div class="sidebar-brand mb-4 flex items-center gap-3 px-4 py-4">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-primary text-lg font-bold text-on-primary">
                <span class="material-symbols-outlined" data-icon="build">build</span>
            </div>
            <div class="sidebar-brand-text">
                <h1 class="text-headline-sm font-black text-primary">Mechanic Hub</h1>
                <p class="text-body-sm text-secondary">Super Kasir Mode</p>
            </div>
            <button id="theme-toggle" type="button" class="btn-icon ml-auto" title="Toggle gelap/terang">
                <span class="material-symbols-outlined text-[20px] dark-icon hidden">light_mode</span>
                <span class="material-symbols-outlined text-[20px] light-icon">dark_mode</span>
            </button>
        </div>

        <ul class="flex flex-1 flex-col gap-1 overflow-y-auto">
            <li>
                <a class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}" href="{{ route('dashboard') }}" title="Dashboard">
                    <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">dashboard</span>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('barang.*') ? 'nav-link-active' : '' }}" href="{{ route('barang.index') }}" title="Data Barang">
                    <span class="material-symbols-outlined">inventory_2</span>
                    <span class="nav-label">Data Barang</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('stok-tracking.*') ? 'nav-link-active' : '' }}" href="{{ route('stok-tracking.index') }}" title="Tracking Stok">
                    <span class="material-symbols-outlined">history</span>
                    <span class="nav-label">Tracking Stok</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('supplier.*') ? 'nav-link-active' : '' }}" href="{{ route('supplier.index') }}" title="Data Supplier">
                    <span class="material-symbols-outlined">local_shipping</span>
                    <span class="nav-label">Data Supplier</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('pembelian.*') ? 'nav-link-active' : '' }}" href="{{ route('pembelian.invoice-hutang') }}" title="Pembelian">
                    <span class="material-symbols-outlined">shopping_cart</span>
                    <span class="nav-label">Pembelian</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('penjualan.*') ? 'nav-link-active' : '' }}" href="{{ route('penjualan.index') }}" title="Penjualan">
                    <span class="material-symbols-outlined">point_of_sale</span>
                    <span class="nav-label">Penjualan</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('generate.*') ? 'nav-link-active' : '' }}" href="{{ route('generate.index') }}" title="Generate Transaksi">
                    <span class="material-symbols-outlined">auto_awesome</span>
                    <span class="nav-label">Generate Transaksi</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('export.*') ? 'nav-link-active' : '' }}" href="{{ route('export.index') }}" title="Export">
                    <span class="material-symbols-outlined">file_download</span>
                    <span class="nav-label">Export</span>
                </a>
            </li>
            <li>
                <a class="nav-link {{ request()->routeIs('pengaturan.*') ? 'nav-link-active' : '' }}" href="{{ route('pengaturan.index') }}" title="Pengaturan">
                    <span class="material-symbols-outlined">settings</span>
                    <span class="nav-label">Pengaturan</span>
                </a>
            </li>
        </ul>

        <div class="mt-auto px-2 pb-4">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-link w-full text-left hover:text-danger-red" title="Logout">
                    <span class="material-symbols-outlined">logout</span>
                    <span class="nav-label">Logout</span>
                </button>
            </form>
        </div>
    </nav>

    <!-- Main Content Area -->
    <main class="flex min-h-screen flex-1 flex-col md:ml-[240px]">
        <!-- TopNavBar -->
        <header class="sticky top-0 z-10 flex h-16 w-full items-center justify-between border-b border-outline-variant bg-surface-container-lowest px-4 md:px-margin-page">
            <div class="flex items-center gap-4">
                <button id="sidebar-toggle" class="rounded p-2 text-on-surface-variant transition-transform hover:bg-surface-container active:scale-95" title="Buka/tutup (mobile) atau minimize (desktop)">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <div class="hidden text-headline-md font-bold text-primary md:block">
                    {{ \App\Models\TokoSetting::utama()->nama_toko }}
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span class="hidden rounded-full bg-secondary-container px-3 py-1 text-label-caps font-bold text-on-secondary-container sm:block">
                    Admin
                </span>
                <div class="ml-2 flex h-8 w-8 items-center justify-center rounded-full border border-outline-variant bg-primary text-label-caps font-bold text-on-primary">
                    {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 p-4 md:p-margin-page">
            @include('partials.flash')
            <div class="flex flex-col gap-6">
                @yield('content')
            </div>
        </div>
    </main>

    @stack('scripts')
</body>

</html>
