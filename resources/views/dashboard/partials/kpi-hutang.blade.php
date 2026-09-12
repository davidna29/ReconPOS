{{-- KPI Hutang (Batch G). Vars: $totalHutang, $jatuhTempo7Hari. --}}
<div class="kpi-card kpi-accent-red h-32">
    <div class="flex items-start justify-between">
        <h3 class="kpi-card-caption flex items-center gap-1 text-danger-red">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">warning</span>
            Hutang Belum Lunas
        </h3>
    </div>
    <div class="kpi-card-value">{{ rupiah($totalHutang) }}</div>
    <div class="flex w-fit items-center gap-1 rounded bg-error-container px-2 py-0.5 text-body-sm font-medium text-danger-red">
        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">event_busy</span>
        {{ $jatuhTempo7Hari->count() }} tagihan jatuh tempo ≤ 7 hari / lewat
    </div>
</div>
