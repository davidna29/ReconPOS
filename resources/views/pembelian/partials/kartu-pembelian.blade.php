{{-- Kartu Pembelian ringkas (dashboard & pembelian, admin & publik).
     Mengikuti filter halaman masing-masing (bukan filter sendiri).
     Variabel: $labelBeli, $totalBeli, $sisaBeli, $countBeli. --}}
<div class="mb-4">
    <div class="relative flex h-32 flex-col justify-between overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest p-4">
        <div class="absolute -bottom-4 -right-4 opacity-5">
            <span class="material-symbols-outlined text-8xl">shopping_cart</span>
        </div>
        <div>
            <h3 class="text-label-caps font-bold uppercase tracking-wider text-secondary">Total Belanja — {{ $labelBeli }}</h3>
            <div class="mt-2 text-headline-lg font-bold text-primary">{{ rupiah($totalBeli) }}</div>
        </div>
        <div class="flex items-center gap-1 text-body-sm text-secondary">
            <span class="material-symbols-outlined text-[16px]">receipt_long</span>
            <span>{{ $countBeli }} invoice · Sisa hutang {{ rupiah($sisaBeli) }} (termasuk PPN)</span>
        </div>
    </div>
</div>
