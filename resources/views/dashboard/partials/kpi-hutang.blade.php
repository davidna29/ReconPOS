{{-- KPI Hutang (Batch G). Vars: $totalHutang, $jatuhTempo7Hari. --}}
<div class="kpi-card kpi-accent-red p-3 md:p-4 min-h-[92px] md:min-h-0 md:h-32">
    <div class="flex items-start justify-between">
        <h3 class="kpi-card-caption flex items-center gap-1 text-[10px] text-danger-red md:text-label-caps">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">warning</span>
            Hutang Belum Lunas
        </h3>
    </div>
    <div class="kpi-card-value text-[17px] md:text-headline-lg">{{ rupiah($totalHutang) }}</div>
    <div class="flex w-fit items-center gap-1 rounded bg-error-container px-2 py-0.5 text-[11px] font-medium text-danger-red md:text-body-sm">
        <span class="material-symbols-outlined hidden text-[14px] md:inline" aria-hidden="true">event_busy</span>
        <span class="hidden md:inline">{{ $jatuhTempo7Hari->count() }} tagihan jatuh tempo ≤ 7 hari / lewat</span>
        <span class="md:hidden">{{ $jatuhTempo7Hari->count() }} jatuh tempo</span>
    </div>
</div>
