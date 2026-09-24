{{-- Kartu Pembelian ringkas (dashboard & pembelian, admin & publik).
     Mengikuti filter halaman masing-masing (bukan filter sendiri).
     Variabel: $labelBeli, $totalBeli, $sisaBeli, $countBeli. --}}
<div class="mb-4">
    <div class="kpi-card p-3 md:p-4 min-h-[92px] md:min-h-0 md:h-32">
        <div class="absolute -bottom-4 -right-4 hidden opacity-5 md:block" aria-hidden="true">
            <span class="material-symbols-outlined text-8xl">shopping_cart</span>
        </div>
        <div>
            <h3 class="kpi-card-caption text-[10px] md:text-label-caps">Total Belanja — {{ $labelBeli }}</h3>
            <div class="mt-1 md:mt-2 text-[17px] font-bold leading-none text-primary md:text-headline-lg">{{ rupiah($totalBeli) }}</div>
        </div>
        <div class="kpi-card-foot gap-1 text-[11px] text-secondary md:text-body-sm">
            <span class="material-symbols-outlined hidden text-[16px] md:inline" aria-hidden="true">receipt_long</span>
            <span>{{ $countBeli }} invoice · Sisa hutang {{ rupiah($sisaBeli) }} (termasuk PPN)</span>
        </div>
    </div>
</div>
