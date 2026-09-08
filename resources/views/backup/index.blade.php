@extends('layouts.app')

@section('title', 'Backup & Restore')

@section('content')
    <div>
        <h2 class="text-headline-lg text-on-surface">Backup & Restore</h2>
        <p class="mt-1 text-body-md text-secondary">Backup database (portabel SQLite ↔ MySQL) — unduh di satu sisi, unggah-restore di sisi lain.</p>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- Buat backup -->
        <div class="card-pad">
            <h3 class="mb-2 text-headline-sm">Buat Backup Baru</h3>
            <p class="mb-3 text-body-sm text-secondary">Menyimpan seluruh data transaksi, stok, hutang & pengaturan ke file <span class="mono">.json.gz</span>. Otomatis hanya menyimpan {{ \App\Services\BackupService::RETENSI }} file terakhir.</p>
            <form method="POST" action="{{ route('backup.store') }}"
                onsubmit="return confirm('Buat backup dari kondisi database saat ini?');">
                @csrf
                <button class="btn btn-primary">
                    <span class="material-symbols-outlined text-[18px]">backup</span> Buat Backup Sekarang
                </button>
            </form>
        </div>

        <!-- Restore langkah 1: unggah -->
        <div class="card-pad border-l-4 border-l-danger-red">
            <h3 class="mb-2 text-headline-sm text-danger-red">Restore dari File (3 langkah)</h3>
            <p class="mb-3 text-body-sm text-secondary"><strong>Langkah 1 dari 3 — Unggah.</strong> File akan divalidasi dulu; eksekusi hanya setelah pratinjau + ketik <span class="mono">RESTORE</span>. Sebelum menimpa, sistem otomatis membuat pra-backup kondisi saat ini.</p>
            <form method="POST" action="{{ route('backup.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="label">File backup (.json.gz, maks 20 MB)</label>
                    <input type="file" name="file" accept=".gz" required class="input @error('file') input-error @enderror">
                    @error('file')<span class="hint text-danger-red">{{ $message }}</span>@enderror
                </div>
                <button class="btn btn-amber btn-sm"><span class="material-symbols-outlined text-[16px]">upload</span> Unggah & Validasi</button>
            </form>
        </div>
    </div>

    <!-- Daftar file -->
    <div class="card overflow-hidden">
        <div class="border-b border-outline-variant bg-slate-50 px-4 py-3">
            <h3 class="text-headline-sm">File Backup di Server Ini</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama File</th>
                        <th>Dibuat (isi file)</th>
                        <th class="text-right">Baris</th>
                        <th class="text-right">Ukuran</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $i => $f)
                        <tr>
                            <td class="text-secondary">{{ $i + 1 }}</td>
                            <td class="mono font-medium">{{ $f['nama'] }}
                                @if (str_starts_with($f['nama'], 'pra-restore-'))<x-badge color="amber">pra-restore</x-badge>@endif
                                @if ($f['rusak'])<x-badge color="red">rusak</x-badge>@endif
                            </td>
                            <td>{{ $f['created_at'] ?? '-' }}</td>
                            <td class="mono text-right">{{ $f['baris_total'] !== null ? number_format($f['baris_total'], 0, ',', '.') : '-' }}</td>
                            <td class="mono text-right">{{ number_format((int) ($f['ukuran'] / 1024), 0, ',', '.') }} KB</td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('backup.download', $f['nama']) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Unduh">
                                        <span class="material-symbols-outlined text-[18px]">download</span>
                                    </a>
                                    <form method="POST" action="{{ route('backup.destroy', $f['nama']) }}"
                                        onsubmit="return confirm('Hapus file {{ $f['nama'] }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                            <span class="material-symbols-outlined text-[18px]">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-secondary">Belum ada file backup. Buat backup pertama di atas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-pad">
        <h3 class="mb-2 text-headline-sm">Cara pindah lokal → hosting</h3>
        <ol class="list-decimal pl-5 text-body-sm text-secondary">
            <li>Di <strong>lokal</strong>: buat backup → unduh file-nya.</li>
            <li>Di <strong>hosting</strong>: buka laman ini → unggah file → periksa pratinjau → ketik <span class="mono">RESTORE</span>.</li>
            <li>Setelah restore: login ulang bila sesi berakhir; pra-backup kondisi hosting sebelumnya tersimpan otomatis.</li>
        </ol>
    </div>
@endsection
