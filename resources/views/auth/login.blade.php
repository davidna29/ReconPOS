<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Mechanic Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="flex min-h-screen items-center justify-center bg-surface-muted p-4">
    <div class="w-full max-w-md">
        <div class="mb-6 flex flex-col items-center text-center">
            <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-xl bg-primary text-on-primary shadow-sm">
                <span class="material-symbols-outlined">build</span>
            </div>
            <h1 class="text-headline-lg font-black text-primary">Mechanic Hub</h1>
            <p class="mt-1 text-body-md text-secondary">Super Kasir Mode — Generate Transaksi Otomatis</p>
        </div>

        <div class="card p-6 shadow-sm">
            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-danger-red/40 bg-error-container p-3 text-body-sm text-on-error-container">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="flex flex-col gap-4">
                @csrf
                <div>
                    <label for="email" class="label">Email / Username</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                        class="input @error('email') input-error @enderror" placeholder="admin@tokosaya.id">
                </div>
                <div>
                    <label for="password" class="label">Password</label>
                    <input id="password" type="password" name="password" required
                        class="input @error('password') input-error @enderror" placeholder="••••••••">
                </div>
                <label class="flex items-center gap-2 text-body-sm text-secondary">
                    <input type="checkbox" name="remember" class="rounded border-outline-variant text-primary focus:ring-primary">
                    Ingat saya
                </label>
                <button type="submit" class="btn btn-primary w-full py-2.5">
                    <span class="material-symbols-outlined text-[18px]">login</span>
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
