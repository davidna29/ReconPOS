@extends('layouts.app')

@section('title', 'Invoice ' . $invoice->no_invoice)

@section('content')
    @include('pembelian.partials.nav-tabs')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Invoice {{ $invoice->no_invoice }}</h2>
            <p class="mt-1 text-body-md text-secondary">
                {{ tanggal_id($invoice->tanggal_transaksi, true) }}
                @if ($invoice->status === 'lunas')
                    <x-badge color="green">Lunas</x-badge>
                @else
                    <x-badge color="amber">Hutang</x-badge>
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('pembelian.edit', $invoice) }}" class="btn btn-secondary btn-sm">
                <span class="material-symbols-outlined text-[16px]">edit</span> Edit
            </a>
            <a href="{{ route('pembelian.print', $invoice) }}" target="_blank" class="btn btn-secondary btn-sm">
                <span class="material-symbols-outlined text-[16px]">print</span> Print
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card overflow-hidden lg:col-span-2">
            <div class="border-b border-outline-variant bg-slate-50 px-4 py-3">
                <h3 class="text-headline-sm">Data barang Pembelian</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama</th>
                            <th>Kode</th>
                            <th class="text-right">Harga Beli</th>
                            <th class="text-center">QTY</th>
                            <th class="text-right">Diskon Item</th>
                            <th class="text-right">Sub Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $i => $it)
                            <tr>
                                <td class="text-secondary">{{ $i + 1 }}</td>
                                <td class="font-medium">{{ $it->barang->nama }}
                                    @if ($it->no_sn)<div class="mono text-[11px] text-secondary">SN: {{ $it->no_sn }}</div>@endif
                                </td>
                                <td class="mono text-secondary">{{ $it->barang->kode_barang }}</td>
                                <td class="mono text-right">{{ uang($it->harga_beli) }}</td>
                                <td class="mono text-center">{{ $it->qty }}</td>
                                <td class="mono text-right">{{ $it->diskon_item > 0 ? '-' . uang($it->diskon_item) : '-' }}</td>
                                <td class="mono text-right font-bold">{{ uang($it->sub_total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col items-end gap-1 border-t border-outline-variant bg-slate-50 p-4">
                <div class="flex w-full max-w-sm justify-between text-body-sm text-secondary"><span>Subtotal</span><span class="mono">{{ rupiah($invoice->subtotal) }}</span></div>
                <div class="flex w-full max-w-sm justify-between text-body-sm text-secondary"><span>Diskon / Potongan</span><span class="mono">- {{ rupiah($invoice->diskon_total) }}</span></div>
                <div class="flex w-full max-w-sm justify-between text-body-md font-bold"><span>Total Setelah Diskon</span><span class="mono text-primary">{{ rupiah($invoice->total_setelah_diskon) }}</span></div>
                @if ($invoice->jenis === 'cash')
                    <div class="flex w-full max-w-sm justify-between text-body-sm text-secondary"><span>Bayar</span><span class="mono">{{ rupiah($invoice->bayar_atau_dp) }}</span></div>
                    <div class="flex w-full max-w-sm justify-between text-body-sm text-lunas-green"><span>Kembali</span><span class="mono">{{ rupiah(max(0, $invoice->bayar_atau_dp - $invoice->total_setelah_diskon)) }}</span></div>
                @else
                    <div class="flex w-full max-w-sm justify-between text-body-sm text-secondary"><span>DP</span><span class="mono">{{ rupiah($invoice->bayar_atau_dp) }}</span></div>
                    <div class="flex w-full max-w-sm justify-between text-body-sm text-hutang-amber"><span>Sisa Hutang</span><span class="mono">{{ rupiah($invoice->sisa_hutang) }}</span></div>
                    <div class="flex w-full max-w-sm justify-between text-body-sm text-secondary"><span>Jatuh Tempo</span><span class="mono">{{ $invoice->jatuh_tempo?->format('d/m/Y') ?? '-' }}</span></div>
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-4">
            <div class="card-pad">
                <h3 class="mb-2 text-headline-sm">Supplier</h3>
                <p class="font-medium">{{ $invoice->supplier?->nama }}</p>
                <p class="text-body-sm text-secondary">{{ $invoice->supplier?->kontak }}<br>{{ $invoice->supplier?->alamat }}</p>
            </div>

            @if ($invoice->pembayaranHutang->count())
                <div class="card-pad">
                    <h3 class="mb-2 text-headline-sm">Histori Pembayaran</h3>
                    @foreach ($invoice->pembayaranHutang as $p)
                        <div class="flex items-center justify-between border-b border-slate-100 py-1.5">
                            <span>{{ $p->tanggal_bayar->format('d/m/Y') }}</span>
                            <span class="mono font-bold text-lunas-green">{{ rupiah($p->nominal) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($invoice->status === 'belum_lunas')
                <div class="card-pad border-l-4 border-l-hutang-amber">
                    <h3 class="mb-2 text-headline-sm text-hutang-amber">Sisa Hutang: {{ rupiah($invoice->sisa_hutang) }}</h3>
                    <form method="POST" action="{{ route('pembelian.bayar', $invoice) }}" class="flex flex-col gap-2">
                        @csrf
                        <input type="number" name="nominal" min="1" max="{{ $invoice->sisa_hutang }}" required class="input mono" placeholder="Nominal pembayaran">
                        <input type="date" name="tanggal_bayar" value="{{ now()->format('Y-m-d') }}" class="input" required>
                        <input type="text" name="catatan" placeholder="Catatan (opsional)" class="input">
                        <button class="btn btn-success w-full"><span class="material-symbols-outlined text-[18px]">payments</span> Bayar / Lunasi</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    @include('partials.resolve-stok-warning')
@endsection
