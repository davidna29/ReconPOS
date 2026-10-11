@extends('layouts.app')

@section('title', 'PPh 21 Bulanan')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">PPh 21 Bulanan</h2>
            <p class="page-head-subtitle">Tarik Excel + XML Coretax (BPMP) per periode — NPWP {{ $npwp ?: 'belum diisi di Pengaturan' }}</p>
        </div>
    </div>

    @if (strlen(preg_replace('/\D/', '', $npwp)) !== 16)
        <div class="card mb-4 border border-amber-300 bg-amber-50 p-4 text-body-sm text-amber-900">
            NPWP CV di <a href="{{ route('pengaturan.index') }}" class="font-semibold underline">Pengaturan</a> belum 16 digit —
            export akan ditolak pre-validasi sampai NPWP diisi benar.
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="card-pad">
            <h3 class="mb-3 flex items-center gap-2 text-headline-sm text-on-surface">
                <span class="material-symbols-outlined" aria-hidden="true">calendar_add_on</span>
                Buat / Buka Periode
            </h3>
            <form method="POST" action="{{ route('pph21.periode.store') }}" class="flex flex-col gap-3">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Masa Pajak (bulan)</label>
                        <select name="masa" class="input" required>
                            @for ($m = 1; $m <= 12; $m++)
                                <option value="{{ $m }}" @selected((int) old('masa', now()->month) === $m)>{{ sprintf('%02d', $m) }}</option>
                            @endfor
                        </select>
                    </div>
                    <div>
                        <label class="label">Tahun Pajak</label>
                        <input type="number" name="tahun" value="{{ old('tahun', now()->year) }}" min="2000" max="2100" required class="input">
                    </div>
                </div>
                <div>
                    <label class="label">Tgl Pemotongan (default = akhir bulan)</label>
                    <input type="date" name="tgl_pemotongan" value="{{ old('tgl_pemotongan') }}" class="input">
                </div>
                <button class="btn btn-primary self-start shadow-sm">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">folder_open</span> Buka Periode
                </button>
            </form>
        </div>

        <div class="card-pad">
            <h3 class="mb-3 flex items-center gap-2 text-headline-sm text-on-surface">
                <span class="material-symbols-outlined" aria-hidden="true">history</span>
                Riwayat Export Coretax
            </h3>
            @forelse ($riwayat as $b)
                <div class="mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-outline-variant p-2">
                    <span class="mono text-body-sm font-semibold">{{ $b->filter_bulan }}</span>
                    @foreach ($b->file_names ?? [] as $i => $f)
                        <a href="{{ route('export.unduh', [$b, $i]) }}" class="btn btn-secondary btn-sm">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">download</span> {{ $f }}
                        </a>
                    @endforeach
                </div>
            @empty
                <p class="text-body-sm text-secondary">Belum ada file Coretax yang dihasilkan.</p>
            @endforelse
        </div>
    </div>

    <div class="card animate-rise overflow-hidden min-w-0 mt-4">
        <div class="overflow-x-auto min-w-0 max-w-full">
            <table class="tbl tbl-cards">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Tgl Potong</th>
                        <th>NPWP Pemotong</th>
                        <th>Jumlah Karyawan</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($periodes as $p)
                        <tr>
                            <td class="mono font-semibold">{{ $p->label() }}</td>
                            <td>{{ $p->tgl_pemotongan->format('Y-m-d') }}</td>
                            <td class="mono">{{ $p->npwp_pemotong }}</td>
                            <td>{{ $p->items_count }} orang</td>
                            <td class="text-right">
                                <a href="{{ route('pph21.show', $p) }}" class="btn btn-primary btn-sm">Kelola Gaji</a>
                                <form method="POST" action="{{ route('pph21.periode.destroy', $p) }}" class="inline" data-confirm="Hapus periode {{ $p->label() }} beserta datanya?">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-icon text-danger-red hover:bg-red-50" title="Hapus">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary">Belum ada periode — buat periode bulan berjalan dulu.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($periodes->hasPages())
            <div class="px-3 py-2">{{ $periodes->links() }}</div>
        @endif
    </div>
@endsection
