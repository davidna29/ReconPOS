@php
    $aktif = null;
    if (request()->routeIs('pembelian.create') || request()->routeIs('pembelian.edit')) {
        $aktif = 'transaksi';
    } elseif (request()->routeIs('pembelian.invoice-hutang')
        || (request()->routeIs('pembelian.show') && filled($invoice ?? null) && $invoice->status === 'belum_lunas')) {
        $aktif = 'hutang';
    } elseif (request()->routeIs('pembelian.invoice-lunas')
        || (request()->routeIs('pembelian.show') && filled($invoice ?? null) && $invoice->status === 'lunas')) {
        $aktif = 'lunas';
    }
    $jumlahHutang = \App\Models\InvoicePembelian::where('status', 'belum_lunas')->count();
    $jumlahLunas = \App\Models\InvoicePembelian::where('status', 'lunas')->count();
@endphp
<div class="flex flex-wrap gap-2">
    <div class="segmented">
        <a href="{{ route('pembelian.create') }}" class="{{ $aktif === 'transaksi' ? 'active-cash' : '' }}" title="Input pembelian baru (Cash/Hutang)">
            <span class="material-symbols-outlined text-[16px]">add_shopping_cart</span>
            Transaksi Pembelian
        </a>
        <a href="{{ route('pembelian.invoice-hutang') }}" class="{{ $aktif === 'hutang' ? 'active-hutang' : '' }}" title="Invoice hutang dengan sisa > 0">
            <span class="material-symbols-outlined text-[16px]">receipt_long</span>
            Data Hutang Belum Lunas
            @if ($jumlahHutang > 0)<span class="badge badge-red">{{ $jumlahHutang }}</span>@endif
        </a>
        <a href="{{ route('pembelian.invoice-lunas') }}" class="{{ $aktif === 'lunas' ? 'active-lunas' : '' }}" title="Cash lunas + hutang yang sudah dilunasi">
            <span class="material-symbols-outlined text-[16px]">receipt</span>
            Data Pembelian (Lunas/Cash)
            @if ($jumlahLunas > 0)<span class="badge badge-green">{{ $jumlahLunas }}</span>@endif
        </a>
    </div>
</div>
