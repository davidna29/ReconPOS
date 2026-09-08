@extends('layouts.app')

@section('title', 'Pratinjau Restore')

@section('content')
    <div>
        <h2 class="text-headline-lg text-on-surface">Pratinjau Restore</h2>
        <p class="mt-1 text-body-md text-secondary"><strong>Langkah 2 dari 3 — Periksa.</strong> Belum ada yang berubah. Lanjut hanya bila semua baris di bawah sudah benar.</p>
    </div>

    <div class="card-pad">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div>
                <p class="label">Dibuat</p>
                <p class="font-medium">{{ $hasil['manifest']['created_at'] ?? '-' }}</p>
            </div>
            <div>
                <p class="label">Sumber</p>
                <p class="mono">{{ $hasil['manifest']['source_driver'] ?? '-' }}</p>
            </div>
            <div>
                <p class="label">Total Baris di File</p>
                <p class="mono font-bold">{{ number_format((int) ($hasil['manifest']['total_baris'] ?? 0), 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="label">Status Validasi</p>
                @if ($hasil['ok'])
                    <x-badge color="green">Valid</x-badge>
                @else
                    <x-badge color="red">Ditolak</x-badge>
                @endif
            </div>
        </div>
        @if (count($hasil['warnings']))
            <div class="mt-3 flex flex-col gap-1">
                @foreach ($hasil['warnings'] as $w)
                    <p class="text-body-sm text-hutang-amber">⚠ {{ $w }}</p>
                @endforeach
            </div>
        @endif
        @if (count($hasil['errors']))
            <div class="mt-3 flex flex-col gap-1">
                @foreach ($hasil['errors'] as $e)
                    <p class="text-body-sm font-medium text-danger-red">⛔ {{ $e }}</p>
                @endforeach
            </div>
        @endif
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Tabel</th>
                        <th class="text-right">Di File</th>
                        <th class="text-right">Di Database Saat Ini</th>
                        <th class="text-right">Selisih</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($hasil['per_tabel'] as $p)
                        <tr>
                            <td class="mono">{{ $p['tabel'] }}
                                @if ($p['hilang_di_file'])<x-badge color="red">hilang di file</x-badge>@endif
                            </td>
                            <td class="mono text-right">{{ $p['di_file'] !== null ? number_format($p['di_file'], 0, ',', '.') : '-' }}</td>
                            <td class="mono text-right">{{ $p['di_db'] !== null ? number_format($p['di_db'], 0, ',', '.') : '-' }}</td>
                            <td class="mono text-right">{{ $p['selisih'] !== null ? ($p['selisih'] > 0 ? '+' : '') . number_format($p['selisih'], 0, ',', '.') : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($hasil['ok'])
        <div class="card-pad border-l-4 border-l-danger-red">
            <h3 class="mb-2 text-headline-sm text-danger-red">Langkah 3 dari 3 — Konfirmasi (tidak bisa dibatalkan selain via pra-backup)</h3>
            <p class="mb-3 text-body-sm text-secondary">Restore akan <strong>mengganti seluruh data</strong> dengan isi file. Pra-backup kondisi saat ini dibuat otomatis dulu. Ketik persis <span class="mono font-bold">RESTORE</span> lalu tekan tombol.</p>
            <form method="POST" action="{{ route('backup.restore') }}" class="flex flex-wrap items-end gap-2"
                onsubmit="return confirm('YAKIN restore? Seluruh data saat ini akan diganti isi file backup.');">
                @csrf
                <input type="hidden" name="file" value="{{ $token }}">
                <div>
                    <label class="label">Ketik RESTORE untuk konfirmasi</label>
                    <input type="text" name="konfirmasi" required autocomplete="off" placeholder="RESTORE" class="input mono w-44">
                </div>
                <button class="btn btn-danger"><span class="material-symbols-outlined text-[18px]">warning</span> Pulihkan Sekarang</button>
                <a href="{{ route('backup.index') }}" class="btn btn-secondary">Batalkan</a>
            </form>
        </div>
    @else
        <div class="flex">
            <a href="{{ route('backup.index') }}" class="btn btn-secondary">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Kembali (file ditolak)
            </a>
        </div>
    @endif
@endsection
