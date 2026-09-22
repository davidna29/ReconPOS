@extends('layouts.app')

@section('title', 'PIN Mode Publik')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">PIN Mode Publik</h2>
            <p class="page-head-subtitle">Masukkan PIN untuk melihat data toko (read-only). Hubungi admin jika tidak tahu PIN.</p>
        </div>
    </div>

    <div class="mx-auto w-full max-w-md">
        <div class="card-pad">
            <div class="mb-4 flex items-center gap-2 text-secondary">
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">lock</span>
                <span class="text-body-sm">Akses dilindungi PIN — diatur di Pengaturan → Akses Mode Publik.</span>
            </div>

            <form method="POST" action="{{ route('publik.pin.verify') }}" class="flex flex-col gap-4">
                @csrf
                <div>
                    <label class="label">PIN Publik</label>
                    <input type="password" name="pin" required autofocus minlength="4" maxlength="20"
                        class="input @error('pin') input-error @enderror" placeholder="Masukkan PIN (4-20 karakter)">
                    @error('pin')<span class="hint text-danger-red">{{ $message }}</span>@enderror
                    <span class="hint">PIN bersifat case-sensitive (huruf besar/kecil dibedakan).</span>
                </div>
                <button class="btn btn-primary w-full">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">key</span> Buka Mode Publik
                </button>
            </form>

            <p class="mt-4 text-center text-body-sm text-secondary">
                Admin? <a href="{{ route('login') }}" class="font-medium text-primary hover:underline">Login sebagai Admin</a> untuk bypass PIN.
            </p>
        </div>
    </div>
@endsection
