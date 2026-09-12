{{-- KPI Omset + delta vs periode sebelumnya (Batch G). Vars: $labelPeriode, $omsetPeriode, $awal, $akhir, $omsetDeltaPct. --}}
<div class="kpi-card kpi-live h-32">
    <div class="absolute -bottom-4 -right-4 opacity-5" aria-hidden="true">
        <span class="material-symbols-outlined text-8xl">payments</span>
    </div>
    <div>
        <h3 class="kpi-card-caption">Total Omset — {{ $labelPeriode }}</h3>
        <div class="mt-2 text-headline-lg font-bold text-primary">{{ rupiah($omsetPeriode) }}</div>
    </div>
    <div class="kpi-card-foot flex-wrap text-lunas-green">
        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">trending_up</span>
        <span>{{ $awal->format('d/m/Y') }} – {{ $akhir->format('d/m/Y') }} · manual + rekonstruksi (tersimpan)</span>
        @if ($omsetDeltaPct !== null)
            <span class="badge {{ $omsetDeltaPct >= 0 ? 'badge-green' : 'badge-red' }}">{{ $omsetDeltaPct >= 0 ? '▲' : '▼' }} {{ abs($omsetDeltaPct) }}% vs periode lalu</span>
        @endif
    </div>
</div>
