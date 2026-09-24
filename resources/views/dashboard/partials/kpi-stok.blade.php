{{-- KPI Stok kritis (Batch G). Vars: $stokKritis, $ambang. --}}
<div class="kpi-card kpi-accent-amber p-3 md:p-4 min-h-[92px] md:min-h-0 md:h-32">
    <h3 class="kpi-card-caption text-[10px] text-hutang-amber md:text-label-caps">SKU Stok Menipis / Habis</h3>
    <div class="flex items-baseline gap-1.5 md:gap-2">
        <span class="kpi-card-value text-[17px] md:text-headline-lg">{{ $stokKritis->count() }}</span>
        <span class="text-[11px] text-secondary md:text-body-md">Item (≤ {{ $ambang }})</span>
    </div>
    <div class="flex w-fit items-center gap-1 rounded bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-hutang-amber md:text-body-sm">
        <span class="material-symbols-outlined hidden text-[14px] md:inline" aria-hidden="true">inventory_2</span>
        <span class="hidden md:inline">{{ $stokKritis->take(2)->pluck('nama')->join(', ') ?: 'Semua aman' }}{{ $stokKritis->count() > 2 ? '…' : '' }}</span>
        <span class="md:hidden">{{ $stokKritis->take(1)->pluck('nama')->first() ?: 'Aman' }}</span>
    </div>
</div>
