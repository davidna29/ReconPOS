@php
    $settings = \App\Models\TokoSetting::utama();
@endphp
<div class="head">
    <div>
        <h1>{{ $settings->nama_toko }}</h1>
        <p class="muted">{{ $settings->alamat ?? '' }}{{ $settings->telepon ? ' · ' . $settings->telepon : '' }}</p>
        @if ($settings->npwp)
            <p class="mono muted">NPWP: {{ $settings->npwp }}</p>
        @endif
    </div>
    <div style="text-align:right">
        <div class="mono" style="font-size:14px; font-weight:700;">{{ $trx->ref_transaksi }}</div>
        <div class="muted">{{ tanggal_id($trx->tanggal_waktu, true) }}</div>
    </div>
</div>

<p><strong>Customer:</strong> Customer Umum (ID 0)</p>

<table>
    <thead>
        <tr><th>No</th><th>Nama Barang</th><th>Kode</th><th class="right">QTY</th><th class="right">Harga</th><th class="right">Subtotal</th></tr>
    </thead>
    <tbody>
        @foreach ($trx->items as $i => $it)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $it->barang->nama }}@if ($it->no_sn)<br><span class="muted">SN: {{ $it->no_sn }}</span>@endif</td>
                <td class="mono">{{ $it->barang->kode_barang }}</td>
                <td class="mono right">{{ $it->qty }}</td>
                <td class="mono right">{{ uang($it->harga_jual) }}</td>
                <td class="mono right">{{ uang($it->subtotal) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row"><td colspan="5" class="right">TOTAL</td><td class="mono right">{{ uang($trx->total) }}</td></tr>
    </tfoot>
</table>

<p class="muted" style="margin-top:24px">*Harga sudah termasuk PPN.</p>
