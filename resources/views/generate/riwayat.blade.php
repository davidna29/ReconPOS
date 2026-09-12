@extends('layouts.app')

@section('title', 'Riwayat Generate')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Riwayat Batch Generate</h2>
            <p class="page-head-subtitle">Audit trail: parameter, waktu generate & status setiap batch (PRD 9)</p>
        </div>
        <a href="{{ route('generate.index') }}" class="btn btn-primary btn-sm">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">auto_awesome</span> Generate Baru
        </a>
    </div>

    <div class="card animate-rise overflow-hidden" style="--rise-delay:.08s">
        <div class="overflow-x-auto">
            <table class="tbl tbl-cards">
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
                <tbody class="rise-rows">
                    @forelse ($batches as $b)
                        <tr>
                            <td data-label="ID" class="mono text-secondary">#{{ $b->id }}</td>
                            <td data-label="Periode">{{ $b->periode_mulai->format('d/m/Y') }} – {{ $b->periode_selesai->format('d/m/Y') }}</td>
                            <td data-label="Target" class="mono text-right">{{ uang($b->target_omset) }}</td>
                            <td data-label="Total Generate" class="mono text-right">{{ $b->total_generate !== null ? uang($b->total_generate) : '-' }}</td>
                            <td data-label="Selisih" class="mono text-right {{ ($b->selisih ?? 0) > 0 ? 'text-danger-red' : 'text-lunas-green' }}">
                                {{ $b->selisih !== null ? ($b->selisih > 0 ? '+' . uang($b->selisih) : uang($b->selisih)) : '-' }}
                            </td>
                            <td data-label="Jml Nota" class="text-center">{{ $b->transaksi_penjualan_count }}</td>
                            <td data-label="Status">
                                @if ($b->status === 'draft')
                                    <x-badge color="amber">Draft</x-badge>
                                @elseif ($b->status === 'dibatalkan')
                                    <x-badge color="slate">Dibatalkan</x-badge>
                                @else
                                    <x-badge color="green">Tersimpan</x-badge>
                                @endif
                            </td>
                            <td data-label="Parameter" class="max-w-[200px]">
                                @php($p = $b->parameter_json ?? [])
                                <span class="text-[11px] text-secondary">
                                    nota {{ $p['nota_min'] ?? '-' }}-{{ $p['nota_max'] ?? '-' }}/hari · tol {{ $p['toleransi_persen'] ?? '-' }}% · variasi {{ $p['variasi_min'] ?? '-' }}..{{ $p['variasi_max'] ?? '-' }}%
                                </span>
                            </td>
                            <td data-label="Dibuat">{{ $b->created_at->format('d/m/Y H:i') }}</td>
                            <td data-label="Aksi" class="text-right">
                                <div class="flex justify-end gap-1">
                                    @if ($b->status === 'draft')
                                        <a href="{{ route('generate.review', $b) }}" class="btn btn-primary btn-sm">Review</a>
                                        <form method="POST" action="{{ route('generate.hapus-batch', $b) }}"
                                            data-confirm="Hapus draft batch #{{ $b->id }}? Tidak ada efek ke stok."
                                            data-delete-ajax>
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger btn-sm">
                                                <span class="material-symbols-outlined text-[16px]">delete</span>
                                            </button>
                                        </form>
                                    @elseif ($b->status === 'tersimpan')
                                        <form method="POST" action="{{ route('generate.batalkan', $b) }}"
                                            data-confirm="Undo batch #{{ $b->id }}? Stok akan dikembalikan & NO_SN dilepas. Baris tetap tersimpan sebagai 'dibatalkan' (audit). Transaksi tidak lagi dihitung di omset/export.">
                                            @csrf
                                            <button class="btn btn-danger btn-sm">
                                                <span class="material-symbols-outlined text-[16px]">undo</span> Undo
                                            </button>
                                        </form>
                                    @elseif ($b->status === 'dibatalkan')
                                        <form method="POST" action="{{ route('generate.hapus-batch', $b) }}"
                                            data-confirm="Hapus batch dibatalkan #{{ $b->id }}? Tidak ada efek ke stok."
                                            data-delete-ajax>
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger btn-sm">
                                                <span class="material-symbols-outlined text-[16px]">delete</span>
                                            </button>
                                        </form>
                                    @endif
                                    <button type="button" class="btn btn-secondary btn-sm" title="Detail Nota"
                                        onclick="document.getElementById('det-b{{ $b->id }}').classList.toggle('hidden')">
                                        <span class="material-symbols-outlined text-[16px]">visibility</span> Detail Nota
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr id="det-b{{ $b->id }}" class="hidden bg-surface-container-low">
                            <td colspan="10">
                                @forelse ($b->transaksiPenjualan as $trx)
                                    <div class="border-b border-slate-200 px-3 py-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="mono font-semibold text-primary">{{ $trx->ref_transaksi }}</span>
                                            <span class="text-body-sm text-secondary">{{ $trx->tanggal_waktu->format('d/m/Y H:i') }}</span>
                                            <span class="mono text-body-sm font-bold ml-auto">{{ rupiah($trx->total) }}</span>
                                        </div>
                                        <ul class="mt-1 space-y-0.5 pl-4 text-body-sm text-on-surface">
                                            @foreach ($trx->items as $it)
                                                <li class="flex gap-2">
                                                    <span class="flex-1">{{ $it->barang->nama }}@if ($it->no_sn) <span class="mono text-[11px] text-secondary">SN {{ $it->no_sn }}</span>@endif</span>
                                                    <span class="mono text-secondary">{{ $it->qty }} × {{ uang($it->harga_jual) }}</span>
                                                    <span class="mono w-24 text-right">{{ uang($it->subtotal) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @empty
                                    <p class="px-3 py-2 text-body-sm text-secondary">Tidak ada nota pada batch ini.</p>
                                @endforelse
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
