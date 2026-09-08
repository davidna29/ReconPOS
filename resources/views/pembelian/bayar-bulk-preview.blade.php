@extends('layouts.app')

@section('title', 'Pratinjau Bayar Bulk')

@section('content')
    @include('pembelian.partials.nav-tabs')
    <div>
        <h2 class="text-headline-lg text-on-surface">Pratinjau Bayar Bulk</h2>
        <p class="mt-1 text-body-md text-secondary">Belum tercatat apa pun — periksa alokasi di bawah, lalu Konfirmasi atau Kembali.</p>
    </div>

    <div class="card-pad">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div>
                <p class="label">Supplier</p>
                <p class="font-medium">{{ $supplier->nama }}</p>
            </div>
            <div>
                <p class="label">Nominal Bayar</p>
                <p class="mono font-bold">{{ rupiah($nominal) }}</p>
            </div>
            <div>
                <p class="label">Total Hutang Supplier</p>
                <p class="mono">{{ rupiah($hasil['total_hutang']) }}</p>
            </div>
            <div>
                <p class="label">Dialokasikan</p>
                <p class="mono font-bold text-lunas-green">{{ rupiah($hasil['dialokasi']) }}</p>
            </div>
            <div>
                <p class="label">Kembalian (tidak dicatat)</p>
                <p class="mono font-bold {{ $hasil['kembalian'] > 0 ? 'text-hutang-amber' : '' }}">{{ rupiah($hasil['kembalian']) }}</p>
            </div>
        </div>
        <p class="hint mt-2">Tanggal bayar: {{ \Illuminate\Support\Carbon::parse($tanggalBayar)->format('d/m/Y') }}@if ($catatan) · Catatan: {{ $catatan }}@endif @if (! empty($buktiUrl)) · Bukti: <a href="{{ $buktiUrl }}" target="_blank" rel="noopener" class="text-primary underline">buka link</a>@endif</p>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Invoice (tertua dulu)</th>
                        <th>Tanggal Transaksi</th>
                        <th class="text-right">Sisa Saat Ini</th>
                        <th class="text-right">Dibayar</th>
                        <th class="text-right">Sisa Baru</th>
                        <th>Status Baru</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($hasil['rinci'] as $i => $r)
                        <tr>
                            <td class="text-secondary">{{ $i + 1 }}</td>
                            <td class="mono font-semibold text-primary">{{ $r['invoice']->no_invoice }}</td>
                            <td>{{ $r['invoice']->tanggal_transaksi->format('d/m/Y') }}</td>
                            <td class="mono text-right">{{ uang($r['invoice']->sisa_hutang) }}</td>
                            <td class="mono text-right font-bold text-lunas-green">{{ uang($r['dibayar']) }}</td>
                            <td class="mono text-right font-bold">{{ uang($r['sisa_baru']) }}</td>
                            <td>
                                @if ($r['lunas_baru'])
                                    <x-badge color="green">Lunas</x-badge>
                                @else
                                    <x-badge color="amber">Sisa</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ route('pembelian.bayar-bulk.confirm') }}">
            @csrf
            <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
            <input type="hidden" name="nominal" value="{{ $nominal }}">
            <input type="hidden" name="tanggal_bayar" value="{{ $tanggalBayar }}">
            @if ($catatan)<input type="hidden" name="catatan" value="{{ $catatan }}">@endif
            @if (! empty($buktiUrl))<input type="hidden" name="bukti_url" value="{{ $buktiUrl }}">@endif
            <button class="btn btn-success" onclick="return confirm('Catat pembayaran bulk {{ rupiah($hasil['dialokasi']) }} ke {{ count($hasil['rinci']) }} invoice?');">
                <span class="material-symbols-outlined text-[18px]">check_circle</span> Konfirmasi & Catat Pembayaran
            </button>
        </form>
        <a href="{{ route('pembelian.bayar-bulk.index', ['supplier' => $supplier->id]) }}" class="btn btn-secondary">
            <span class="material-symbols-outlined text-[18px]">arrow_back</span> Kembali (batalkan)
        </a>
    </div>
@endsection
