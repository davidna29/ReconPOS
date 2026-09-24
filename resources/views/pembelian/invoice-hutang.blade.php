@extends('layouts.app')

@section('title', 'Data Hutang Belum Lunas')

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    @php($label = 'Data Hutang Belum Lunas')
    @include('pembelian.partials.nav-tabs')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">{{ $label }}</h2>
            <p class="page-head-subtitle">Invoice pembelian tab <strong>Hutang</strong> dengan Sisa Hutang lebih dari 0 (belum lunas) — PRD 5.2a</p>
        </div>
    </div>

    @php($hasFilter = request()->filled('bulan') || request()->filled('tahun') || request()->filled('from') || request()->filled('to') || request()->filled('supplier'))
    <div class="mb-4">
        <x-collapse id="filter-hutang" label="Filter Periode" icon="filter_alt" :active="$hasFilter">
            @include('partials.filter-periode', [
                'action' => $r('pembelian.invoice-hutang'),
                'reset' => $r('pembelian.invoice-hutang'),
                'showSupplier' => true,
                'suppliers' => $suppliers ?? collect(),
            ])
        </x-collapse>
    </div>

    @include('pembelian.partials.kartu-pembelian')

    <div class="card animate-rise overflow-hidden" style="--rise-delay:.08s">
        <div class="border-b border-outline-variant p-3">
            <x-collapse id="toolbar-hutang" label="Aksi & Pencarian" icon="list_alt">
                <div class="flex flex-wrap items-center gap-2">
                    @if (! $publik)
                    @include('partials.bulk-print', ['scope' => 'hutang', 'action' => route('pembelian.print-batch')])
                        <a href="{{ route('pembelian.bayar-bulk.index') }}" class="btn btn-success btn-sm"
                            title="Bayar banyak invoice satu supplier sekaligus (tertua dulu)">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">payments</span> Bayar Bulk
                        </a>
                        <button type="button" class="btn btn-danger btn-sm disabled:opacity-50 disabled:cursor-not-allowed" disabled
                            data-bulk-delete-btn="hutang"
                            data-bulk-delete-url="{{ route('pembelian.bulk-delete') }}"
                            title="Hapus semua invoice terpilih">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">delete</span> Hapus Terpilih (<span id="bulk-delete-count-hutang">0</span>)
                        </button>
                    @endif
                    <form method="GET" class="flex flex-wrap items-center gap-2">
                        <select name="entries" class="input w-auto" onchange="this.form.submit()">
                            @foreach ([10, 25, 50, 100] as $n)
                                <option value="{{ $n }}" @selected(request('entries') == $n)>{{ $n }} entri</option>
                            @endforeach
                        </select>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-secondary" aria-hidden="true">search</span>
                            <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari invoice / supplier…" aria-label="Cari invoice / supplier" class="input w-64 pl-9 shadow-sm">
                        </div>
                        <button class="btn btn-secondary btn-sm">Cari</button>
                        <span class="hint mb-0 ml-2 hidden md:inline">Baris merah = jatuh tempo sudah lewat.</span>
                    </form>
                </div>
            </x-collapse>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl tbl-cards">
                <thead>
                    <tr>
                        @if (! $publik)
                        <th class="w-8"><input type="checkbox" id="bulk-all-hutang" class="bulk-all shrink-0 rounded border-outline-variant text-primary focus:ring-primary" title="Pilih semua di halaman ini"></th>
                        @endif
                        <th>No</th>
                        <th>Invoice</th>
                        <th>Tanggal Transaksi</th>
                        <th>Supplier</th>
                        <th>Jatuh Tempo</th>
                        <th class="text-right">Sudah Bayar</th>
                        <th class="text-right">Sisa Hutang</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="rise-rows">
                    @forelse ($invoices as $i => $inv)
                        <tr data-id="{{ $inv->id }}" @class(['row-overdue' => $inv->jatuh_tempo && $inv->jatuh_tempo->isPast()])>
                            @if (! $publik)
                            <td><input type="checkbox" class="bulk-check shrink-0 rounded border-outline-variant text-primary focus:ring-primary" data-bulk="hutang" value="{{ $inv->id }}"></td>
                            @endif
                            <td data-label="No" class="text-secondary">{{ $invoices->firstItem() + $i }}</td>
                            <td data-label="Invoice" class="mono font-semibold text-primary">{{ $inv->no_invoice }}</td>
                            <td data-label="Tanggal">{{ $inv->tanggal_transaksi->format('d/m/Y') }}</td>
                            <td data-label="Supplier" class="font-medium">{{ $inv->supplier?->nama }}</td>
                            <td data-label="Jatuh Tempo">
                                {{ $inv->jatuh_tempo?->format('d/m/Y') ?? '-' }}
                                @if ($inv->jatuh_tempo && $inv->jatuh_tempo->isPast())
                                    <x-badge color="red">Lewat</x-badge>
                                @elseif ($inv->jatuh_tempo && $inv->jatuh_tempo->lte(now()->addDays(7)))
                                    <x-badge color="amber">≤ 7 hr</x-badge>
                                @endif
                            </td>
                            @php($sudahBayar = (int) $inv->bayar_atau_dp + (int) ($inv->cicilan_sum ?? 0))
                            <td data-label="Sudah Bayar" class="mono text-right font-bold text-lunas-green"
                                title="DP {{ uang($inv->bayar_atau_dp) }} + cicilan {{ uang($inv->cicilan_sum ?? 0) }} ({{ (int) ($inv->cicilan_count ?? 0) }}×)">{{ rupiah($sudahBayar) }}</td>
                            <td data-label="Sisa Hutang" class="mono text-right font-bold text-hutang-amber">{{ rupiah($inv->sisa_hutang) }}</td>
                            <td data-label="Aksi" class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ $r('pembelian.show', $inv) }}" class="btn-icon text-special-teal hover:bg-teal-50" title="Detail">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">visibility</span>
                                    </a>
                                    @if (! $publik)
                                    <button type="button" class="btn-icon text-lunas-green hover:bg-green-50" title="Bayar/Lunasi"
                                        onclick="document.getElementById('bayar-{{ $inv->id }}').classList.toggle('hidden')">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">payments</span>
                                    </button>
                                    <a href="{{ route('pembelian.edit', $inv) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Edit">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                                    </a>
                                    @endif
                                    <a href="{{ $r('pembelian.print', $inv) }}" target="_blank" class="btn-icon text-hutang-amber hover:bg-amber-50" title="Print">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">print</span>
                                    </a>
                                    @if (! $publik)
                                    <form method="POST" action="{{ route('pembelian.destroy', $inv) }}" data-confirm="Hapus invoice {{ $inv->no_invoice }}? Efek stok & histori akan dibatalkan." data-delete-ajax>
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @if (! $publik)
                        <tr id="bayar-{{ $inv->id }}" class="hidden bg-slate-50">
                            <td colspan="9">
                                <form method="POST" action="{{ route('pembelian.bayar', $inv) }}" class="flex flex-wrap items-end gap-3 p-3">
                                    @csrf
                                    <div>
                                        <label class="label">Nominal (max {{ uang($inv->sisa_hutang) }})</label>
                                        <input type="number" name="nominal" min="1" max="{{ $inv->sisa_hutang }}" required class="input w-40">
                                    </div>
                                    <div>
                                        <label class="label">Tanggal Bayar</label>
                                        <input type="date" name="tanggal_bayar" value="{{ now()->format('Y-m-d') }}" required class="input">
                                    </div>
                                    <div class="flex-1">
                                        <label class="label">Catatan</label>
                                        <input type="text" name="catatan" placeholder="mis. cicilan ke-2" class="input">
                                    </div>
                                    <button class="btn btn-success"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">payments</span> Simpan Pembayaran</button>
                                </form>
                            </td>
                        </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ $publik ? 8 : 9 }}" class="py-10 text-center text-secondary">Tidak ada hutang belum lunas. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $invoices->withQueryString()->links() }}
        </div>
    </div>

    @include('partials.resolve-stok-warning')
@endsection
