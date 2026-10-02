@extends('layouts.app')

@section('title', 'Data Pembelian (Lunas/Cash)')

@section('content')
    @php($publik = $publik ?? false)
    @php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))
    @include('pembelian.partials.nav-tabs')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Data Pembelian (Lunas/Cash)</h2>
            <p class="page-head-subtitle">Transaksi <strong>Cash</strong> (langsung lunas) + Invoice <strong>Hutang yang sudah dilunasi</strong> penuh — PRD 5.2b</p>
        </div>
    </div>

    @php($hasFilter = request()->filled('bulan') || request()->filled('tahun') || request()->filled('from') || request()->filled('to') || request()->filled('supplier') || request()->filled('cabang'))
    <div class="mb-4">
        <x-collapse id="filter-lunas" label="Filter Periode" icon="filter_alt" :active="$hasFilter">
            @include('partials.filter-periode', [
                'action' => $r('pembelian.invoice-lunas'),
                'reset' => $r('pembelian.invoice-lunas'),
                'showSupplier' => true,
                'suppliers' => $suppliers ?? collect(),
                'showCabang' => true,
                'daftarCabang' => $daftarCabang ?? collect(),
            ])
        </x-collapse>
    </div>

    @include('pembelian.partials.kartu-pembelian')

    <div class="card animate-rise overflow-hidden" style="--rise-delay:.08s">
        <div class="border-b border-outline-variant p-3">
            <x-collapse id="toolbar-lunas" label="Aksi & Pencarian" icon="list_alt">
                <div class="flex flex-wrap items-center gap-2">
                    @if (! $publik)
                    @include('partials.bulk-print', ['scope' => 'lunas', 'action' => route('pembelian.print-batch')])
                        <a href="{{ route('pembelian.lunas.export.xlsx', request()->only(['bulan', 'tahun', 'from', 'to', 'supplier', 'cabang', 'q'])) }}" class="btn btn-secondary btn-sm"
                            title="Unduh Excel tab ini (dibatasi 1 bulan)">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">table_view</span> Export Excel
                        </a>
                        <a href="{{ route('pembelian.lunas.export.pdf', request()->only(['bulan', 'tahun', 'from', 'to', 'supplier', 'cabang', 'q'])) }}" class="btn btn-secondary btn-sm"
                            title="Unduh PDF tab ini (dibatasi 1 bulan)">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">picture_as_pdf</span> Export PDF
                        </a>
                        <button type="button" class="btn btn-danger btn-sm disabled:opacity-50 disabled:cursor-not-allowed" disabled
                            data-bulk-delete-btn="lunas"
                            data-bulk-delete-url="{{ route('pembelian.bulk-delete') }}"
                            title="Hapus semua invoice terpilih">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">delete</span> Hapus Terpilih (<span id="bulk-delete-count-lunas">0</span>)
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
                    </form>
                </div>
            </x-collapse>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl tbl-cards">
                <thead>
                    <tr>
                        @if (! $publik)
                        <th class="w-8"><input type="checkbox" id="bulk-all-lunas" class="bulk-all shrink-0 rounded border-outline-variant text-primary focus:ring-primary" title="Pilih semua di halaman ini"></th>
                        @endif
                        <th>No</th>
                        <th>Invoice</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Cabang</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Bayar</th>
                        <th class="text-right">PPN</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="rise-rows">
                    @forelse ($invoices as $i => $inv)
                        <tr data-id="{{ $inv->id }}">
                            @if (! $publik)
                            <td><input type="checkbox" class="bulk-check shrink-0 rounded border-outline-variant text-primary focus:ring-primary" data-bulk="lunas" value="{{ $inv->id }}"></td>
                            @endif
                            <td data-label="No" class="text-secondary">{{ $invoices->firstItem() + $i }}</td>
                            <td data-label="Invoice" class="mono font-semibold text-primary">{{ $inv->no_invoice }}
                                @if ($inv->sumber === 'import_excel')<x-badge color="slate" class="ml-1">import</x-badge>@endif
                            </td>
                            <td data-label="Tanggal">{{ $inv->tanggal_transaksi->format('d/m/Y') }}</td>
                            <td data-label="Supplier" class="font-medium">{{ $inv->supplier?->nama }}</td>
                            <td data-label="Cabang">{{ $inv->cabang ?? '—' }}</td>
                            <td data-label="Total" class="mono text-right">{{ rupiah($inv->total_setelah_diskon) }}</td>
                            <td data-label="Bayar" class="mono text-right">{{ rupiah($inv->bayar_atau_dp) }}</td>
                            <td data-label="PPN" class="mono text-right text-secondary">{{ (int) $inv->ppn > 0 ? rupiah($inv->ppn) : '-' }}</td>
                            <td data-label="Aksi" class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ $r('pembelian.show', $inv) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Detail">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">visibility</span>
                                    </a>
                                    @if (! $publik)
                                    <a href="{{ route('pembelian.edit', $inv) }}" class="btn-icon text-hutang-amber hover:bg-amber-50" title="Edit">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                                    </a>
                                    @endif
                                    <a href="{{ $r('pembelian.print', $inv) }}" target="_blank" class="btn-icon text-lunas-green hover:bg-green-50" title="Print">
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
                    @empty
                        <tr><td colspan="{{ $publik ? 9 : 10 }}" class="py-10 text-center text-secondary">Belum ada pembelian lunas.</td></tr>
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
