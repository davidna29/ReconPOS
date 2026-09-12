<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — ReconPOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="relative flex min-h-screen items-center justify-center overflow-hidden bg-surface-muted p-4">
    <!-- Dekorasi tonal (murni CSS, tanpa gambar eksternal) -->
    <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-primary/10 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-24 -left-24 h-72 w-72 rounded-full bg-special-teal/10 blur-3xl" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">
        <div class="mb-6 flex flex-col items-center text-center">
            <div class="brand-tile mb-3 h-14 w-14">
                <span class="material-symbols-outlined text-[28px]" aria-hidden="true">build</span>
            </div>
            <h1 class="text-headline-lg font-black tracking-tight text-primary">ReconPOS</h1>
            <p class="mt-1 text-body-md text-secondary">Automated Sales Reconstruction</p>
        </div>

        <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-6 shadow-pop sm:p-8">
            @if ($errors->any())
                <div role="alert" class="mb-4 rounded-lg border border-danger-red/40 bg-error-container p-3 text-body-sm text-on-error-container">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="flex flex-col gap-4">
                @csrf
                <div>
                    <label for="email" class="label">Email / Username</label>
                    <div class="relative">
                        <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-secondary" aria-hidden="true">mail</span>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                            class="input pl-10 @error('email') input-error @enderror" placeholder="admin@tokosaya.id">
                    </div>
                </div>
                <div>
                    <label for="password" class="label">Password</label>
                    <div class="relative">
                        <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-secondary" aria-hidden="true">lock</span>
                        <input id="password" type="password" name="password" required autocomplete="current-password"
                            class="input pl-10 @error('password') input-error @enderror" placeholder="••••••••">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-body-sm text-secondary">
                    <input type="checkbox" name="remember" class="rounded border-outline-variant text-primary focus:ring-primary">
                    Ingat saya
                </label>
                <button type="submit" class="btn btn-primary w-full py-2.5 text-body-md font-semibold shadow-sm">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">login</span>
                    Login
                </button>
            </form>
        </div>

        <p class="mt-4 text-center text-[11px] text-secondary">
            Akses tunggal admin — akun dibuat saat instalasi (lihat README).
        </p>
    </div>
</body>

</html>
