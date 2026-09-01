@extends('layouts.print')

@section('title', 'Cetak Batch Penjualan (' . $trxs->count() . ' nota)')

@section('content')
    @include('partials.bulk-print-toggle')
    @foreach ($trxs as $trx)
        <div class="print-page">
            @include('penjualan.partials.struk', ['trx' => $trx])
        </div>
    @endforeach
@endsection
