@php
    $settings = \App\Models\TokoSetting::utama();
@endphp
<div class="head">
    <div>
        <h1>{{ $settings->nama_toko }}</h1>
        <p class="muted">{{ $settings->alamat ?? '' }} · {{ $settings->telepon ?? '' }}</p>
        @if ($settings->npwp)
            <p class="mono muted">NPWP: {{ $settings->npwp }}</p>
        @endif
    </div>
    <div style="text-align:right">
        <div class="mono" style="font-size:16px; font-weight:700;">{{ $invoice->no_invoice }}</div>
        <div class="muted">{{ tanggal_id($invoice->tanggal_transaksi, true) }}</div>
        <div>{{ $invoice->jenis === 'cash' ? 'CASH' : 'HUTANG' }}</div>
    </div>
</div>

<p><strong>Supplier:</strong> {{ $invoice->supplier->nama }} ({{ $invoice->supplier->kontak }})</p>

<table>
    <thead>
        <tr><th>No</th><th>Nama Barang</th><th>Kode</th><th class="right">Harga Beli</th><th class="right">QTY</th><th class="right">Diskon</th><th class="right">Sub Total</th></tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $i => $it)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $it->barang->nama }}@if ($it->no_sn)<br><span class="muted">SN: {{ $it->no_sn }}</span>@endif</td>
                <td class="mono">{{ $it->barang->kode_barang }}</td>
                <td class="mono right">{{ uang($it->harga_beli) }}</td>
                <td class="mono right">{{ $it->qty }}</td>
                <td class="mono right">{{ $it->diskon_item > 0 ? uang($it->diskon_item) : '-' }}</td>
                <td class="mono right">{{ uang($it->sub_total) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td colspan="6" class="right">Subtotal</td><td class="mono right">{{ uang($invoice->subtotal) }}</td></tr>
        <tr><td colspan="6" class="right">Diskon</td><td class="mono right">- {{ uang($invoice->diskon_total) }}</td></tr>
        <tr class="total-row"><td colspan="6" class="right">Total Setelah Diskon</td><td class="mono right">{{ uang($invoice->total_setelah_diskon) }}</td></tr>
        @if ($invoice->jenis === 'cash')
            <tr><td colspan="6" class="right">Bayar</td><td class="mono right">{{ uang($invoice->bayar_atau_dp) }}</td></tr>
            <tr><td colspan="6" class="right">Kembali</td><td class="mono right">{{ uang(max(0, $invoice->bayar_atau_dp - $invoice->total_setelah_diskon)) }}</td></tr>
        @else
            <tr><td colspan="6" class="right">DP</td><td class="mono right">{{ uang($invoice->bayar_atau_dp) }}</td></tr>
            <tr><td colspan="6" class="right">Sisa Hutang</td><td class="mono right">{{ uang($invoice->sisa_hutang) }}</td></tr>
            <tr><td colspan="6" class="right">Jatuh Tempo</td><td class="mono right">{{ $invoice->jatuh_tempo?->format('d/m/Y') ?? '-' }}</td></tr>
        @endif
    </tfoot>
</table>

<p class="muted" style="margin-top:24px">*Harga sudah termasuk PPN.</p>
