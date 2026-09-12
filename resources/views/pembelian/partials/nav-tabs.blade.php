@php
    $publik = $publik ?? false;
    $r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p);
    $aktif = null;
    if (! $publik && (request()->routeIs('pembelian.create') || request()->routeIs('pembelian.edit'))) {
        $aktif = 'transaksi';
    } elseif (request()->routeIs('pembelian.invoice-hutang', 'publik.pembelian.hutang')
        || (request()->routeIs('pembelian.show', 'publik.pembelian.show') && filled($invoice ?? null) && $invoice->status === 'belum_lunas')) {
        $aktif = 'hutang';
    } elseif (request()->routeIs('pembelian.invoice-lunas', 'publik.pembelian.lunas')
        || (request()->routeIs('pembelian.show', 'publik.pembelian.show') && filled($invoice ?? null) && $invoice->status === 'lunas')) {
        $aktif = 'lunas';
    } elseif (! $publik && request()->routeIs('pembelian.bayar-bulk.*')) {
        $aktif = 'bulk';
    }
    $jumlahHutang = \App\Models\InvoicePembelian::where('status', 'belum_lunas')->count();
    $jumlahLunas = \App\Models\InvoicePembelian::where('status', 'lunas')->count();
@endphp
<div class="seg-bar flex flex-wrap gap-2">
    <div class="segmented">
        @if (! $publik)
        <a href="{{ route('pembelian.create') }}" class="{{ $aktif === 'transaksi' ? 'active-cash' : '' }}" title="Input pembelian baru (Cash/Hutang)">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">add_shopping_cart</span>
            Transaksi Pembelian
        </a>
        @endif
        <a href="{{ $r('pembelian.invoice-hutang') }}" class="{{ $aktif === 'hutang' ? 'active-hutang' : '' }}" title="Invoice hutang dengan sisa > 0">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">receipt_long</span>
            Data Hutang Belum Lunas
            @if ($jumlahHutang > 0)<span class="badge badge-red">{{ $jumlahHutang }}</span>@endif
        </a>
        <a href="{{ $r('pembelian.invoice-lunas') }}" class="{{ $aktif === 'lunas' ? 'active-lunas' : '' }}" title="Cash lunas + hutang yang sudah dilunasi">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">receipt</span>
            Data Pembelian (Lunas/Cash)
            @if ($jumlahLunas > 0)<span class="badge badge-green">{{ $jumlahLunas }}</span>@endif
        </a>
        @if (! $publik)
        <a href="{{ route('pembelian.bayar-bulk.index') }}" class="{{ $aktif === 'bulk' ? 'active-lunas' : '' }}" title="Bayar banyak invoice sekaligus + riwayat">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">payments</span>
            Bayar Bulk
        </a>
        @endif
    </div>
</div>
