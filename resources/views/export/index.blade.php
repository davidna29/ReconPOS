@extends('layouts.app')

@section('title', 'Export Transaksi')

@section('content')
    <div class="flex items-end justify-between">
        <div>
            <h2 class="text-headline-lg text-on-surface">Export Transaksi</h2>
            <p class="mt-1 text-body-md text-secondary">Format A: siap import ke POS Bengkel (Super Kasir) · Format B: laporan internal (PRD 8)</p>
        </div>
    </div>

    @if (session('success') && str_contains(session('success'), 'terpecah'))
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-cash-blue/40 bg-blue-50 p-4 text-body-sm text-blue-800">
            <span class="material-symbols-outlined text-cash-blue">info</span>
            {{ session('success') }}
            @foreach (App\Models\ExportBatch::where('format', 'A')->latest()->first()?->file_names ?? [] as $i => $f)
                <a href="{{ route('export.unduh', [App\Models\ExportBatch::where('format', 'A')->latest()->first()->id, $i]) }}" class="btn btn-secondary btn-sm">
                    <span class="material-symbols-outlined text-[16px]">download</span> {{ $f }}
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- Format A -->
        <div class="card-pad">
            <div class="mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-cash-blue">swap_vert</span>
                <h3 class="text-headline-sm">Format A — Siap Import ke POS Bengkel</h3>
            </div>
            <p class="mb-4 text-body-sm text-secondary">
                Mengikuti template Super Kasir (sheet <span class="mono">IMPORT_PENJUALAN</span>), kolom dari <strong>Pengaturan → Template Export POS</strong> (konfigurabel). Pre-validasi sebelum unduh; otomatis dipecah tiap 5.000 baris.
            </p>
            <form method="POST" action="{{ route('export.format-a') }}" data-export-form class="flex flex-col gap-3">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Dari Tanggal</label>
                        <input type="date" name="periode_mulai" value="{{ now()->startOfMonth()->format('Y-m-d') }}" class="input">
                    </div>
                    <div>
                        <label class="label">Sampai Tanggal</label>
                        <input type="date" name="periode_selesai" value="{{ now()->endOfMonth()->format('Y-m-d') }}" class="input">
                    </div>
                </div>
                <div>
                    <label class="label">…atau shortcut Bulan</label>
                    <input type="month" name="bulan" class="input">
                    <span class="hint">Isi salah satu: rentang tanggal ATAU shortcut bulan — memilih yang satu mengosongkan yang lain.</span>
                </div>
                <button class="btn btn-primary self-start">
                    <span class="material-symbols-outlined text-[18px]">download</span> Unduh Format A (XLSX)
                </button>
            </form>
        </div>

        <!-- Format B -->
        <div class="card-pad">
            <div class="mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-lunas-green">description</span>
                <h3 class="text-headline-sm">Format B — Laporan / Arsip Internal</h3>
            </div>
            <p class="mb-4 text-body-sm text-secondary">
                Laporan manusiawi dengan baris ringkasan (total omset, jumlah nota, jumlah item). Excel (.xlsx).
            </p>
            <form method="POST" action="{{ route('export.format-b') }}" data-export-form class="flex flex-col gap-3">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Dari Tanggal</label>
                        <input type="date" name="periode_mulai" value="{{ now()->startOfMonth()->format('Y-m-d') }}" class="input">
                    </div>
                    <div>
                        <label class="label">Sampai Tanggal</label>
                        <input type="date" name="periode_selesai" value="{{ now()->endOfMonth()->format('Y-m-d') }}" class="input">
                    </div>
                </div>
                <div>
                    <label class="label">…atau shortcut Bulan</label>
                    <input type="month" name="bulan" class="input">
                    <span class="hint">Isi salah satu: rentang tanggal ATAU shortcut bulan — memilih yang satu mengosongkan yang lain.</span>
                </div>
                <div>
                    <label class="label">Sumber</label>
                    <select name="sumber" class="input">
                        <option value="">Semua (manual + rekonstruksi)</option>
                        <option value="manual">Manual saja</option>
                        <option value="rekonstruksi">Rekonstruksi saja</option>
                    </select>
                </div>
                <button class="btn btn-success self-start">
                    <span class="material-symbols-outlined text-[18px]">download</span> Unduh Format B (XLSX)
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="border-b border-outline-variant bg-slate-50 px-4 py-3">
            <h3 class="text-headline-sm">Riwayat Export & Status Upload ke POS (jejak 4.2)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Format</th>
                        <th>Filter</th>
                        <th class="text-center">Jumlah File</th>
                        <th>File</th>
                        <th class="text-center">Sudah Diupload ke POS</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($batches as $b)
                        <tr>
                            <td>{{ $b->created_at->format('d/m/Y H:i') }}</td>
                            <td><x-badge :color="$b->format === 'A' ? 'blue' : 'green'">Format {{ $b->format }}</x-badge></td>
                            <td class="text-body-sm">
                                @if ($b->filter_bulan)
                                    {{ \Carbon\Carbon::parse($b->filter_bulan . '-01')->translatedFormat('F Y') }}
                                @else
                                    {{ $b->filter_mulai?->format('d/m/Y') }} – {{ $b->filter_selesai?->format('d/m/Y') }}
                                @endif
                            </td>
                            <td class="text-center">{{ $b->jumlah_file }}</td>
                            <td>
                                @foreach ($b->file_names ?? [] as $i => $f)
                                    <a href="{{ route('export.unduh', [$b, $i]) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="{{ $f }}">
                                        <span class="material-symbols-outlined text-[18px]">download</span>
                                    </a>
                                @endforeach
                            </td>
                            <td class="text-center">
                                <form method="POST" action="{{ route('export.tandai', $b) }}">
                                    @csrf
                                    @if ($b->status_upload_manual)
                                        <button class="badge badge-green" title="Klik untuk membatalkan tanda"><span class="material-symbols-outlined text-[14px]">check_circle</span> Sudah diupload</button>
                                    @else
                                        <button class="badge badge-slate" title="Klik untuk menandai sudah diupload">Belum diupload</button>
                                    @endif
                                </form>
                            </td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('export.destroy', $b) }}"
                                    data-confirm="Hapus riwayat export Format {{ $b->format }} ({{ $b->created_at->format('d/m/Y H:i') }}, {{ $b->jumlah_file }} file)? Catatan & file dihapus permanen.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-icon text-danger-red hover:bg-red-50" title="Hapus riwayat">
                                        <span class="material-symbols-outlined text-[18px]">delete</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-secondary">Belum ada export.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-pad">
        <h3 class="mb-2 text-headline-sm">Mapping Kolom Aktif (Template Export POS — v{{ \App\Models\TokoSetting::utama()->template_version }})</h3>
        <p class="mb-3 text-body-sm text-secondary">Urutan & default dipakai saat generate Format A. Ubah di Pengaturan → Template Export POS.</p>
        <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
            @foreach ($mapping as $m)
                <div class="rounded-lg border border-outline-variant bg-surface-muted p-2 text-center">
                    <div class="mono text-mono-data font-bold text-primary">{{ $m['nama_kolom'] }}</div>
                    <div class="text-[11px] text-secondary">
                        {{ $m['wajib_opsional'] === 'wajib' ? 'wajib' : 'opsional' }}@if ($m['nilai_default']) · default {{ $m['nilai_default'] }}@endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            // Filter periode saling eksklusif: pilih rentang tanggal ATAU shortcut bulan.
            document.querySelectorAll('form[data-export-form]').forEach(function (form) {
                var mulai = form.querySelector('input[name="periode_mulai"]');
                var selesai = form.querySelector('input[name="periode_selesai"]');
                var bulan = form.querySelector('input[name="bulan"]');
                var kosongkanBulan = function () { if (bulan) bulan.value = ''; };
                if (mulai) mulai.addEventListener('change', kosongkanBulan);
                if (selesai) selesai.addEventListener('change', kosongkanBulan);
                if (bulan) {
                    bulan.addEventListener('change', function () {
                        if (mulai) mulai.value = '';
                        if (selesai) selesai.value = '';
                    });
                }
            });
        })();
    </script>
@endpush
