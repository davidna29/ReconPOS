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
            migrate → cache → verifikasi HTTP. Log tampil di bawah. SSH: {{ $sshInfo }}
        </p>
        <p class="mb-3 flex flex-wrap items-center gap-2 text-body-sm text-secondary">
            <span>APP_ENV: <span class="mono font-bold">{{ $appEnv }}</span></span>
            <span>·</span>
            <span>Gate: <span class="font-bold text-lunas-green">terbuka (lokal)</span></span>
            <form method="POST" action="{{ route('deploy.test-koneksi') }}" class="inline" data-no-loading>
                @csrf
                <button class="btn btn-secondary btn-sm">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">wifi_tethering</span> Test Koneksi SSH
                </button>
            </form>
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
            <div class="flex flex-wrap items-center gap-2">
                <button id="btn-kirim" class="btn btn-primary shadow-sm" @if ($running) disabled @endif>
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">rocket_launch</span> Kirim Sekarang
                </button>
                <span class="hint mb-0">Satu klik cukup — log mengalir di bawah, jangan klik ulang.</span>
            </div>
        </form>
        <form method="POST" action="{{ route('deploy.reset') }}" class="mt-2" data-no-loading
            onsubmit="return confirm('Hapus penanda berjalan? Pastikan tak ada deploy yg hidup (cek via terminal: ps aux | grep kirim-hosting).');">
            @csrf
            <button class="btn btn-secondary btn-sm">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">restart_alt</span> Reset penanda
            </button>
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
            const btn = document.getElementById('btn-kirim');
            if (!box) return;
            const aturTombol = (jalan) => {
                if (!btn) return;
                btn.disabled = !!jalan;
                btn.classList.toggle('opacity-50', !!jalan);
            };
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
                    aturTombol(d.running);
                } catch (e) { /* abaikan, coba lagi 3 dtk berikut */ }
            }, 3000);
            aturTombol(@json($running));
            const form = btn?.closest('form');
            form?.addEventListener('submit', () => aturTombol(true));
        })();
    </script>
@endpush
