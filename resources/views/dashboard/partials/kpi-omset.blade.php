{{-- KPI Omset + delta vs periode sebelumnya (Batch G). Vars: $labelPeriode, $omsetPeriode, $awal, $akhir, $omsetDeltaPct. --}}
<div class="kpi-card kpi-live p-3 md:p-4 min-h-[92px] md:min-h-0 md:h-32">
    <div class="absolute -bottom-4 -right-4 hidden opacity-5 md:block" aria-hidden="true">
        <span class="material-symbols-outlined text-8xl">payments</span>
    </div>
    <div>
        <h3 class="kpi-card-caption text-[10px] md:text-label-caps">Total Omset — {{ $labelPeriode }}</h3>
        <div class="mt-1 md:mt-2 text-[17px] font-bold leading-none text-primary md:text-headline-lg">{{ rupiah($omsetPeriode) }}</div>
    </div>
    <div class="kpi-card-foot flex-wrap gap-1 text-[11px] text-lunas-green md:text-body-sm">
        <span class="material-symbols-outlined hidden text-[16px] md:inline" aria-hidden="true">trending_up</span>
        <span class="hidden md:inline">{{ $awal->format('d/m/Y') }} – {{ $akhir->format('d/m/Y') }} · manual + rekonstruksi (tersimpan)</span>
        <span class="md:hidden">{{ $awal->format('d/m') }}–{{ $akhir->format('d/m/y') }}</span>
        @if ($omsetDeltaPct !== null)
            <span class="badge text-[10px] md:text-label-caps {{ $omsetDeltaPct >= 0 ? 'badge-green' : 'badge-red' }}">{{ $omsetDeltaPct >= 0 ? '▲' : '▼' }} {{ abs($omsetDeltaPct) }}%</span>
        @endif
    </div>
</div>
