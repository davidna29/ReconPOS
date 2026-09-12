@extends('layouts.app')

@section('title', $supplier ? 'Edit Supplier' : 'Tambah Supplier')

@section('content')
    <div class="max-w-2xl">
        <h2 class="page-head-title">{{ $supplier ? 'Edit Supplier' : 'Tambah Supplier' }}</h2>
        <p class="page-head-subtitle">Master supplier pembelian (PRD 4.4)</p>

        <div class="card mt-4 p-6">
            <form method="POST" action="{{ $supplier ? route('supplier.update', $supplier) : route('supplier.store') }}" class="grid grid-cols-1 gap-4">
                @csrf
                @if ($supplier)
                    @method('PUT')
                @endif

                <div>
                    <label class="label">Nama Supplier <span class="text-danger-red">*</span></label>
                    <input type="text" name="nama" value="{{ old('nama', $supplier?->nama) }}" required class="input">
                </div>

                <div>
                    <label class="label">No. Telepon / Kontak <span class="text-danger-red">*</span></label>
                    <input type="text" name="kontak" value="{{ old('kontak', $supplier?->kontak) }}" required class="input" placeholder="0812-3456-7890">
                    <span class="hint">Dipakai juga untuk konfirmasi saat jatuh tempo hutang mendekat.</span>
                </div>

                <div>
                    <label class="label">Email</label>
                    <input type="email" name="email" value="{{ old('email', $supplier?->email) }}" class="input">
                </div>

                <div>
                    <label class="label">Alamat</label>
                    <textarea name="alamat" rows="2" class="input">{{ old('alamat', $supplier?->alamat) }}</textarea>
                </div>

                <div>
                    <label class="label">Catatan</label>
                    <textarea name="catatan" rows="2" class="input" placeholder="mis. termin pembayaran khusus, kontak person">{{ old('catatan', $supplier?->catatan) }}</textarea>
                </div>

                <div class="flex gap-3">
                    <button class="btn btn-primary shadow-sm">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">save</span>
                        {{ $supplier ? 'Simpan Perubahan' : 'Simpan Supplier' }}
                    </button>
                    <a href="{{ route('supplier.index') }}" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
