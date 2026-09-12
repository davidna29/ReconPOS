{{-- Kartu Pembelian ringkas (dashboard & pembelian, admin & publik).
     Mengikuti filter halaman masing-masing (bukan filter sendiri).
     Variabel: $labelBeli, $totalBeli, $sisaBeli, $countBeli. --}}
<div class="mb-4">
    <div class="kpi-card h-32">
        <div class="absolute -bottom-4 -right-4 opacity-5" aria-hidden="true">
            <span class="material-symbols-outlined text-8xl">shopping_cart</span>
        </div>
        <div>
            <h3 class="kpi-card-caption">Total Belanja — {{ $labelBeli }}</h3>
            <div class="mt-2 text-headline-lg font-bold text-primary">{{ rupiah($totalBeli) }}</div>
        </div>
        <div class="kpi-card-foot text-secondary">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">receipt_long</span>
            <span>{{ $countBeli }} invoice · Sisa hutang {{ rupiah($sisaBeli) }} (termasuk PPN)</span>
        </div>
    </div>
</div>
