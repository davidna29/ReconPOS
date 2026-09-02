@extends('layouts.app')

@section('title', 'Penjualan')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Penjualan</h2>
            <p class="mt-1 text-body-md text-secondary">Penjualan manual + hasil generate transaksi — baris rekonstruksi berlabel <span class="badge badge-blue">Rekonstruksi</span></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('generate.index') }}" class="btn btn-secondary btn-sm">
                <span class="material-symbols-outlined text-[16px]">auto_awesome</span> Generate Transaksi
            </a>
            <a href="{{ route('penjualan.create') }}" class="btn btn-primary btn-sm">
                <span class="material-symbols-outlined text-[16px]">add</span> Transaksi Penjualan Baru
            </a>
        </div>
    </div>

    <!-- Filter Periode + Kartu Omset -->
    <form method="GET" action="{{ route('penjualan.index') }}" class="flex flex-wrap items-end gap-2 mb-4">
        @if ($sumber)<input type="hidden" name="sumber" value="{{ $sumber }}">@endif
        <div>
            <label class="label mb-0.5">Bulan</label>
            <input type="month" name="bulan" value="{{ request('bulan') }}" class="input w-40">
        </div>
        <div>
            <label class="label mb-0.5">Tahun</label>
            <input type="number" name="tahun" min="2000" max="2099" value="{{ request('tahun') }}" placeholder="{{ now()->year }}" class="input w-24">
        </div>
        <div>
            <label class="label mb-0.5">Dari</label>
            <input type="date" name="from" value="{{ request('from') }}" class="input">
        </div>
        <div>
            <label class="label mb-0.5">Sampai</label>
            <input type="date" name="to" value="{{ request('to') }}" class="input">
        </div>
        <button class="btn btn-primary btn-sm">
            <span class="material-symbols-outlined text-[16px]">filter_alt</span> Terapkan
        </button>
        <a href="{{ route('penjualan.index') }}{{ $sumber ? '?sumber='.$sumber : '' }}" class="btn btn-secondary btn-sm">Reset</a>
    </form>

    <div class="relative mb-4 flex h-32 flex-col justify-between overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest p-4">
        <div class="absolute -bottom-4 -right-4 opacity-5">
            <span class="material-symbols-outlined text-8xl">payments</span>
        </div>
        <div>
            <h3 class="text-label-caps font-bold uppercase tracking-wider text-secondary">Total Omset — {{ $labelPeriode }}</h3>
            <div class="mt-2 text-headline-lg font-bold text-primary">{{ rupiah($omsetPeriode) }}</div>
        </div>
        <div class="flex items-center gap-3 text-body-sm">
            <span class="text-lunas-green">
                <span class="material-symbols-outlined text-[16px]">trending_up</span>
                {{ $awal->format('d/m/Y') }} – {{ $akhir->format('d/m/Y') }}
            </span>
            @if ($targetOmset !== null)
                <span class="text-secondary">Target: {{ rupiah($targetOmset) }}</span>
                <span class="font-semibold {{ $sisaTarget > 0 ? 'text-hutang-amber' : 'text-lunas-green' }}">
                    {{ $sisaTarget > 0 ? 'Sisa: ' . rupiah($sisaTarget) : '✓ Tercapai' }}
                </span>
                @if ($targetOmset > 0)
                    <span class="text-secondary">{{ round(($omsetPeriode / $targetOmset) * 100) }}%</span>
                @endif
            @else
                <span class="text-secondary">manual + rekonstruksi (tersimpan)</span>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-outline-variant p-3">
            <div class="segmented">
                <a href="{{ route('penjualan.index') }}" class="{{ ! $sumber ? 'active-cash' : '' }}">Semua @if ($jumlah['semua'])>0<span class="badge badge-slate">{{ $jumlah['semua'] }}</span>@endif</a>
                <a href="{{ route('penjualan.index', ['sumber' => 'manual']) }}" class="{{ $sumber === 'manual' ? 'active-cash' : '' }}">Manual @if ($jumlah['manual'])>0<span class="badge badge-green">{{ $jumlah['manual'] }}</span>@endif</a>
                <a href="{{ route('penjualan.index', ['sumber' => 'rekonstruksi']) }}" class="{{ $sumber === 'rekonstruksi' ? 'active-lunas' : '' }}">Rekonstruksi @if ($jumlah['rekonstruksi'])>0<span class="badge badge-blue">{{ $jumlah['rekonstruksi'] }}</span>@endif</a>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('partials.bulk-print', ['scope' => 'penjualan', 'action' => route('penjualan.print-batch')])
                <button type="button" class="btn btn-danger btn-sm disabled:opacity-50 disabled:cursor-not-allowed" disabled
                    data-bulk-delete-btn="penjualan"
                    data-bulk-delete-url="{{ route('penjualan.bulk-delete') }}"
                    title="Hapus semua item terpilih dari halaman ini">
                    <span class="material-symbols-outlined text-[16px]">delete</span> Hapus Terpilih (<span id="bulk-delete-count-penjualan">0</span>)
                </button>
                <form method="GET" class="flex items-center gap-2">
                    @if ($sumber)<input type="hidden" name="sumber" value="{{ $sumber }}">@endif
                    <select name="entries" class="input w-auto" onchange="this.form.submit()">
                        @foreach ([10, 25, 50, 100] as $n)
                            <option value="{{ $n }}" @selected(request('entries') == $n)>{{ $n }} entri</option>
                        @endforeach
                    </select>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-secondary">search</span>
                        <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari ref transaksi…" class="input w-52 pl-9">
                    </div>
                    <button class="btn btn-secondary btn-sm">Cari</button>
                </form>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="w-8"><input type="checkbox" id="bulk-all-penjualan" class="bulk-all shrink-0 rounded border-outline-variant text-primary focus:ring-primary" title="Pilih semua di halaman ini"></th>
                        <th>No</th>
                        <th>Ref Transaksi</th>
                        <th>Tanggal</th>
                        <th class="text-right">Total</th>
                        <th>Sumber</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transaksis as $i => $t)
                        <tr data-id="{{ $t->id }}">
                            <td><input type="checkbox" class="bulk-check shrink-0 rounded border-outline-variant text-primary focus:ring-primary" data-bulk="penjualan" value="{{ $t->id }}"></td>
                            <td class="text-secondary">{{ $transaksis->firstItem() + $i }}</td>
                            <td class="mono font-semibold text-primary">{{ $t->ref_transaksi }}</td>
                            <td>{{ $t->tanggal_waktu->format('d/m/Y H:i') }}</td>
                            <td class="mono text-right">{{ rupiah($t->total) }}</td>
                            <td>
                                @if ($t->sumber === 'rekonstruksi')
                                    <x-badge color="blue">Rekonstruksi</x-badge>
                                @else
                                    <x-badge color="green">Manual</x-badge>
                                @endif
                                @if ($t->diedit_at)
                                    <span class="badge badge-amber">diedit</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="btn-icon hover:bg-slate-100" title="Detail"
                                        onclick="document.getElementById('det-{{ $t->id }}').classList.toggle('hidden')">
                                        <span class="material-symbols-outlined text-[18px]">visibility</span>
                                    </button>
                                    <a href="{{ route('penjualan.edit', $t) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Edit nota">
                                        <span class="material-symbols-outlined text-[18px]">edit</span>
                                    </a>
                                    <a href="{{ route('penjualan.print', $t) }}" target="_blank" class="btn-icon text-hutang-amber hover:bg-amber-50" title="Cetak Struk">
                                        <span class="material-symbols-outlined text-[18px]">print</span>
                                    </a>
                                    @if ($t->sumber !== 'draft')
                                        <form method="POST" action="{{ route('penjualan.destroy', $t) }}" data-confirm="Hapus penjualan {{ $t->ref_transaksi }}? Stok akan dikembalikan." data-delete-ajax>
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                                <span class="material-symbols-outlined text-[18px]">delete</span>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        <tr id="det-{{ $t->id }}" class="hidden bg-slate-50">
                            <td colspan="7">
                                <table class="tbl">
                                    <thead><tr><th>Item</th><th class="text-center">Qty</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>
                                    <tbody>
                                        @foreach ($t->items as $it)
                                            <tr>
                                                <td>{{ $it->barang->nama }}@if ($it->no_sn) <span class="mono text-[11px] text-secondary">SN {{ $it->no_sn }}</span>@endif</td>
                                                <td class="mono text-center">{{ $it->qty }}</td>
                                                <td class="mono text-right">{{ uang($it->harga_jual) }}</td>
                                                <td class="mono text-right">{{ uang($it->subtotal) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-secondary">Belum ada penjualan aktif (manual / rekonstruksi).</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $transaksis->withQueryString()->links() }}
        </div>
    </div>
@endsection
