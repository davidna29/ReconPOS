@extends('layouts.print')

@section('title', 'Struk ' . $trx->ref_transaksi)

@section('content')
    @include('penjualan.partials.struk', ['trx' => $trx])
@endsection
