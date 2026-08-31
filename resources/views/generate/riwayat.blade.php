@extends('layouts.app')

@section('title', 'Riwayat Generate')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Riwayat Batch Generate</h2>
            <p class="mt-1 text-body-md text-secondary">Audit trail: parameter, waktu generate & status setiap batch (PRD 9)</p>
        </div>
        <a href="{{ route('generate.index') }}" class="btn btn-primary btn-sm">
            <span class="material-symbols-outlined text-[16px]">auto_awesome</span> Generate Baru
        </a>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Periode</th>
                        <th class="text-right">Target</th>
                        <th class="text-right">Total Generate</th>
                        <th class="text-right">Selisih</th>
                        <th>Jml Nota</th>
                        <th>Status</th>
                        <th>Parameter (ringkas)</th>
                        <th>Dibuat</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($batches as $b)
                        <tr>
                            <td class="mono text-secondary">#{{ $b->id }}</td>
                            <td>{{ $b->periode_mulai->format('d/m/Y') }} – {{ $b->periode_selesai->format('d/m/Y') }}</td>
                            <td class="mono text-right">{{ uang($b->target_omset) }}</td>
                            <td class="mono text-right">{{ $b->total_generate !== null ? uang($b->total_generate) : '-' }}</td>
                            <td class="mono text-right {{ ($b->selisih ?? 0) > 0 ? 'text-danger-red' : 'text-lunas-green' }}">
                                {{ $b->selisih !== null ? ($b->selisih > 0 ? '+' . uang($b->selisih) : uang($b->selisih)) : '-' }}
                            </td>
                            <td class="text-center">{{ $b->transaksi_penjualan_count }}</td>
                            <td>
                                @if ($b->status === 'draft')
                                    <x-badge color="amber">Draft</x-badge>
                                @else
                                    <x-badge color="green">Tersimpan</x-badge>
                                @endif
                            </td>
                            <td class="max-w-[200px]">
                                @php($p = $b->parameter_json ?? [])
                                <span class="text-[11px] text-secondary">
                                    nota {{ $p['nota_min'] ?? '-' }}-{{ $p['nota_max'] ?? '-' }}/hari · tol {{ $p['toleransi_persen'] ?? '-' }}% · variasi {{ $p['variasi_min'] ?? '-' }}..{{ $p['variasi_max'] ?? '-' }}%
                                </span>
                            </td>
                            <td>{{ $b->created_at->format('d/m/Y H:i') }}</td>
                            <td class="text-right">
                                @if ($b->status === 'draft')
                                    <a href="{{ route('generate.review', $b) }}" class="btn btn-primary btn-sm">Review</a>
                                @else
                                    <span class="text-[11px] text-secondary">sumber: rekonstruksi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-10 text-center text-secondary">Belum ada batch generate.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $batches->links() }}
        </div>
    </div>
@endsection
