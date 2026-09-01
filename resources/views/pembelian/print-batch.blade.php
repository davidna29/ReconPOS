@extends('layouts.print')

@section('title', 'Cetak Batch Pembelian (' . $invoices->count() . ' invoice)')

@section('content')
    @include('partials.bulk-print-toggle')
    @foreach ($invoices as $invoice)
        <div class="print-page">
            @include('pembelian.partials.invoice', ['invoice' => $invoice])
        </div>
    @endforeach
@endsection
