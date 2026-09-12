{{-- KPI Stok kritis (Batch G). Vars: $stokKritis, $ambang. --}}
<div class="kpi-card kpi-accent-amber h-32">
    <h3 class="kpi-card-caption text-hutang-amber">SKU Stok Menipis / Habis</h3>
    <div class="flex items-baseline gap-2">
        <span class="kpi-card-value">{{ $stokKritis->count() }}</span>
        <span class="text-body-md text-secondary">Item (ambang ≤ {{ $ambang }})</span>
    </div>
    <div class="flex w-fit items-center gap-1 rounded bg-amber-50 px-2 py-0.5 text-body-sm font-medium text-hutang-amber">
        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">inventory_2</span>
        {{ $stokKritis->take(2)->pluck('nama')->join(', ') ?: 'Semua aman' }}{{ $stokKritis->count() > 2 ? '…' : '' }}
    </div>
</div>
