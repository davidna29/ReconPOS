@extends('layouts.app')

@section('title', 'Mode Publik')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">{{ $settings->nama_toko }}</h2>
            <p class="page-head-subtitle">Mode Publik — Lihat Saja (tanpa login). Pilih menu di bawah untuk melihat data. Semua aksi edit/tambah/hapus hanya di mode Admin.</p>
        </div>
        @if ($settings->alamat || $settings->telepon)
            <div class="hidden text-right text-body-sm text-secondary sm:block">
                @if ($settings->alamat)<div>{{ $settings->alamat }}</div>@endif
                @if ($settings->telepon)<div class="mono">{{ $settings->telepon }}</div>@endif
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <a href="{{ route('publik.dashboard') }}" class="card-pad group flex flex-col gap-3 transition hover:border-primary/30 hover:shadow-pop">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-fixed text-on-primary-fixed">
                    <span class="material-symbols-outlined" aria-hidden="true">dashboard</span>
                </div>
                <h3 class="text-headline-sm font-semibold text-on-surface group-hover:text-primary">Dashboard</h3>
            </div>
            <p class="text-body-sm text-secondary">Ringkasan omset periode, hutang jatuh tempo, dan stok kritis — read-only.</p>
            <span class="mt-auto inline-flex items-center gap-1 text-label-caps font-bold text-primary">Buka Dashboard <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_forward</span></span>
        </a>

        <a href="{{ route('publik.barang.index') }}" class="card-pad group flex flex-col gap-3 transition hover:border-primary/30 hover:shadow-pop">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary-container text-on-secondary-container">
                    <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                </div>
                <h3 class="text-headline-sm font-semibold text-on-surface group-hover:text-primary">Data Barang</h3>
            </div>
            <p class="text-body-sm text-secondary">{{ $totalBarang }} SKU terdaftar · {{ $totalStok }} total stok. Lihat detail, kartu stok, dan barcode.</p>
            <span class="mt-auto inline-flex items-center gap-1 text-label-caps font-bold text-primary">Lihat Barang <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_forward</span></span>
        </a>

        <a href="{{ route('publik.stok-tracking.index') }}" class="card-pad group flex flex-col gap-3 transition hover:border-primary/30 hover:shadow-pop">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-tertiary-container text-on-tertiary-container">
                    <span class="material-symbols-outlined" aria-hidden="true">history</span>
                </div>
                <h3 class="text-headline-sm font-semibold text-on-surface group-hover:text-primary">Tracking Stok</h3>
            </div>
            <p class="text-body-sm text-secondary">Aging stok FIFO — prioritas habiskan batch tertua.</p>
            <span class="mt-auto inline-flex items-center gap-1 text-label-caps font-bold text-primary">Buka Tracking <span class="material-symbols-outlined text-[16px]" aria-hidden="true">arrow_forward</span></span>
        </a>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <a href="{{ route('publik.pembelian.invoice-hutang') }}" class="card-pad group flex items-center gap-4 transition hover:border-primary/30 hover:shadow-pop">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-tint/10 text-primary">
                <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
            </div>
            <div class="min-w-0">
                <h3 class="text-title-sm font-semibold text-on-surface group-hover:text-primary">Pembelian</h3>
                <p class="text-body-sm text-secondary">Hutang Belum Lunas & Lunas — dengan filter periode & supplier.</p>
            </div>
            <span class="material-symbols-outlined ml-auto text-secondary" aria-hidden="true">chevron_right</span>
        </a>

        <a href="{{ route('publik.penjualan.index') }}" class="card-pad group flex items-center gap-4 transition hover:border-primary/30 hover:shadow-pop">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-tint/10 text-primary">
                <span class="material-symbols-outlined" aria-hidden="true">point_of_sale</span>
            </div>
            <div class="min-w-0">
                <h3 class="text-title-sm font-semibold text-on-surface group-hover:text-primary">Penjualan</h3>
                <p class="text-body-sm text-secondary">Daftar transaksi + total omset periode — tanpa label sumber.</p>
            </div>
            <span class="material-symbols-outlined ml-auto text-secondary" aria-hidden="true">chevron_right</span>
        </a>
    </div>

    <div class="card-pad flex flex-wrap items-center justify-between gap-3 bg-surface-container/50">
        <p class="text-body-sm text-secondary">
            <span class="material-symbols-outlined mr-1 align-middle text-[16px]" aria-hidden="true">info</span>
            Mode Publik hanya melihat data. Untuk edit, generate, atau export — login sebagai Admin.
        </p>
        <div class="flex items-center gap-2">
            @if (session('publik_authenticated'))
                <form method="POST" action="{{ route('publik.pin.clear') }}">
                    @csrf
                    <button class="btn btn-secondary btn-sm">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">lock_open</span> Keluar Mode Publik
                    </button>
                </form>
            @endif
            <a href="{{ route('login') }}" class="btn btn-primary btn-sm">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">login</span> Login Admin
            </a>
        </div>
    </div>
@endsection
