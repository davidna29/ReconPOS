@extends('layouts.print')

@section('title', 'Invoice ' . $invoice->no_invoice)

@section('content')
    @include('pembelian.partials.invoice', ['invoice' => $invoice])
@endsection
