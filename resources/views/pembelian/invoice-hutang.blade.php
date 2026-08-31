@extends('layouts.app')

@section('title', 'Data Hutang Belum Lunas')

@section('content')
    @php($label = 'Data Hutang Belum Lunas')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">{{ $label }}</h2>
            <p class="mt-1 text-body-md text-secondary">Invoice pembelian tab Hutang dengan Sisa Hutang > 0 (PRD 5.2a)</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pembelian.create') }}" class="btn btn-primary btn-sm">
                <span class="material-symbols-outlined text-[16px]">add</span> Transaksi Pembelian
            </a>
            <a href="{{ route('pembelian.invoice-lunas') }}" class="btn btn-secondary btn-sm">Data Pembelian (Lunas)</a>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-outline-variant p-3">
            <form method="GET" class="flex items-center gap-2">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-secondary">search</span>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari invoice / supplier…" class="input w-64 pl-9">
                </div>
                <button class="btn btn-secondary btn-sm">Cari</button>
                <span class="hint mb-0 ml-2">Baris merah = jatuh tempo sudah lewat.</span>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Invoice</th>
                        <th>Tanggal Transaksi</th>
                        <th>Supplier</th>
                        <th>Jatuh Tempo</th>
                        <th class="text-right">Sisa Hutang</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $i => $inv)
                        <tr @class(['row-overdue' => $inv->jatuh_tempo && $inv->jatuh_tempo->isPast()])>
                            <td class="text-secondary">{{ $invoices->firstItem() + $i }}</td>
                            <td class="mono font-semibold text-primary">{{ $inv->no_invoice }}</td>
                            <td>{{ $inv->tanggal_transaksi->format('d/m/Y') }}</td>
                            <td class="font-medium">{{ $inv->supplier?->nama }}</td>
                            <td>
                                {{ $inv->jatuh_tempo?->format('d/m/Y') ?? '-' }}
                                @if ($inv->jatuh_tempo && $inv->jatuh_tempo->isPast())
                                    <x-badge color="red">Lewat</x-badge>
                                @elseif ($inv->jatuh_tempo && $inv->jatuh_tempo->lte(now()->addDays(7)))
                                    <x-badge color="amber">≤ 7 hr</x-badge>
                                @endif
                            </td>
                            <td class="mono text-right font-bold text-hutang-amber">{{ rupiah($inv->sisa_hutang) }}</td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('pembelian.show', $inv) }}" class="btn-icon text-special-teal hover:bg-teal-50" title="Detail">
                                        <span class="material-symbols-outlined text-[18px]">visibility</span>
                                    </a>
                                    <button type="button" class="btn-icon text-lunas-green hover:bg-green-50" title="Bayar/Lunasi"
                                        onclick="document.getElementById('bayar-{{ $inv->id }}').classList.toggle('hidden')">
                                        <span class="material-symbols-outlined text-[18px]">payments</span>
                                    </button>
                                    <a href="{{ route('pembelian.edit', $inv) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Edit">
                                        <span class="material-symbols-outlined text-[18px]">edit</span>
                                    </a>
                                    <a href="{{ route('pembelian.print', $inv) }}" target="_blank" class="btn-icon text-hutang-amber hover:bg-amber-50" title="Print">
                                        <span class="material-symbols-outlined text-[18px]">print</span>
                                    </a>
                                    <form method="POST" action="{{ route('pembelian.destroy', $inv) }}" data-confirm="Hapus invoice {{ $inv->no_invoice }}? Efek stok & histori akan dibatalkan.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                            <span class="material-symbols-outlined text-[18px]">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr id="bayar-{{ $inv->id }}" class="hidden bg-slate-50">
                            <td colspan="7">
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
                                    <button class="btn btn-success"><span class="material-symbols-outlined text-[18px]">payments</span> Simpan Pembayaran</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-secondary">Tidak ada hutang belum lunas. 🎉</td></tr>
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
