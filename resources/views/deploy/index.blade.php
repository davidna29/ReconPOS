@extends('layouts.app')

@section('title', 'Kirim ke Hosting')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Kirim ke Hosting</h2>
            <p class="page-head-subtitle">Deploy kode lokal ke {{ config('deploy.url') }} — hanya tampil di lingkungan lokal</p>
        </div>
    </div>

    <div class="card-pad">
        <h3 class="mb-3 flex items-center gap-2 text-headline-sm text-on-surface">
            <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">cloud_upload</span>
            Konfirmasi Pengiriman
        </h3>
        <p class="mb-3 text-body-sm text-secondary">
            Menjalankan: cek prasyarat → rsync delta → sambung public → composer install →
            migrate → cache → verifikasi HTTP. Log tampil di bawah. SSH: {{ config('deploy.ssh_user') }}@{{ config('deploy.ssh_host') }}:{{ config('deploy.ssh_port') }}
        </p>
        <form method="POST" action="{{ route('deploy.kirim') }}" class="flex flex-col gap-3" data-no-loading>
            @csrf
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-body-sm">
                    <input type="checkbox" name="dry_run" value="1" class="rounded border-outline-variant text-primary focus:ring-primary">
                    Dry-run dulu (lihat yg berubah tanpa mengirim)
                </label>
                <label class="flex items-center gap-2 text-body-sm">
                    <input type="checkbox" name="force" value="1" class="rounded border-outline-variant text-primary focus:ring-primary">
                    Sertakan perubahan git yg belum di-commit
                </label>
                <label class="flex items-center gap-2 text-body-sm">
                    <input type="checkbox" name="tanpa_test" value="1" class="rounded border-outline-variant text-primary focus:ring-primary">
                    Lewati php artisan test
                </label>
                <label class="flex items-center gap-2 text-body-sm">
                    <input type="checkbox" name="backup" value="1" checked class="rounded border-outline-variant text-primary focus:ring-primary">
                    Cadangkan DB hosting dulu
                </label>
                <label class="flex items-center gap-2 text-body-sm">
                    <input type="checkbox" name="tanpa_maintenance" value="1" class="rounded border-outline-variant text-primary focus:ring-primary">
                    Tanpa maintenance mode
                </label>
            </div>
            <div class="max-w-md">
                <label class="label">Ketik <span class="mono font-bold">KIRIM</span> untuk mengeksekusi</label>
                <input type="text" name="konfirmasi" required autocomplete="off" class="input mono" placeholder="KIRIM">
                @error('konfirmasi')<span class="hint text-danger-red">{{ $message }}</span>@enderror
            </div>
            <div>
                <button class="btn btn-primary shadow-sm">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">rocket_launch</span> Kirim Sekarang
                </button>
            </div>
        </form>
    </div>

    <div class="card-pad">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">terminal</span>
                Log Deploy
            </h3>
            <span id="deploy-status" class="text-body-sm font-medium {{ $running ? 'text-hutang-amber' : 'text-lunas-green' }}">
                {{ $running ? '● Berjalan…' : '● Menganggur' }}
            </span>
        </div>
        <pre id="deploy-log" class="mono max-h-[420px] overflow-y-auto whitespace-pre-wrap rounded-lg border border-outline-variant bg-surface-container-low p-3 text-body-sm">{{ $log }}</pre>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const box = document.getElementById('deploy-log');
            const st = document.getElementById('deploy-status');
            if (!box) return;
            box.scrollTop = box.scrollHeight;
            setInterval(async () => {
                try {
                    const r = await fetch('{{ route('deploy.status') }}', { headers: { 'Accept': 'application/json' } });
                    if (!r.ok) return;
                    const d = await r.json();
                    box.textContent = d.log || '';
                    box.scrollTop = box.scrollHeight;
                    st.textContent = d.running ? '● Berjalan…' : '● Menganggur';
                    st.className = 'text-body-sm font-medium ' + (d.running ? 'text-hutang-amber' : 'text-lunas-green');
                } catch (e) { /* abaikan, coba lagi 3 dtk berikut */ }
            }, 3000);
        })();
    </script>
@endpush
