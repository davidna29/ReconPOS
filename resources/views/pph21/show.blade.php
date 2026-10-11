@extends('layouts.app')

@section('title', 'Gaji PPh 21 ' . $periode->label())

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Gaji {{ $periode->label() }}</h2>
            <p class="page-head-subtitle">Tgl potong {{ $periode->tgl_pemotongan->format('Y-m-d') }} · NPWP {{ $periode->npwp_pemotong }} · Tarif kosong = otomatis TER</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('pph21.index') }}" class="btn btn-secondary btn-sm">← Periode</a>
            <form method="POST" action="{{ route('pph21.export', $periode) }}">
                @csrf
                <button class="btn btn-success btn-sm" data-confirm="Tarik Excel + XML periode {{ $periode->label() }}?">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">download</span> Tarik Excel + XML
                </button>
            </form>
        </div>
    </div>

    <div class="card animate-rise p-3 md:p-4 min-w-0" style="--rise-delay:.06s">
        <x-collapse id="collapse-import-gaji" label="Import Gaji via Excel" icon="upload_file">
            <div class="flex flex-col gap-2">
                <a href="{{ route('pph21.gaji.download', $periode) }}" class="btn btn-success btn-sm self-start">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">download</span> Template Gaji {{ $periode->label() }}
                </a>
                <form method="POST" action="{{ route('pph21.gaji.import', $periode) }}" enctype="multipart/form-data" class="rounded-lg border border-outline-variant p-3 min-w-0">
                    @csrf
                    <div class="flex items-center gap-2 min-w-0">
                        <label class="btn btn-secondary btn-sm shrink-0 cursor-pointer">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">folder_open</span> Pilih File
                            <input type="file" name="file" accept=".xlsx" class="sr-only" required>
                        </label>
                        <span data-filename class="min-w-0 flex-1 truncate text-body-sm text-secondary">Belum ada file dipilih</span>
                        <button class="btn btn-amber btn-sm shrink-0">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">upload</span> Upload
                        </button>
                    </div>
                </form>
            </div>
        </x-collapse>
    </div>

    <div class="card animate-rise overflow-hidden min-w-0 mt-4" style="--rise-delay:.12s">
        <div class="overflow-x-auto min-w-0 max-w-full">
            <table class="tbl tbl-cards">
                <thead>
                    <tr>
                        <th>NIK</th>
                        <th>Nama</th>
                        <th>Cabang</th>
                        <th>PTKP</th>
                        <th>Gross</th>
                        <th>Tarif</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($periode->items as $it)
                        <tr>
                            <td class="mono">{{ $it->nik }}</td>
                            <td>{{ $it->karyawan?->nama ?? '-' }}</td>
                            <td>{{ $it->karyawan?->labelCabang() ?? '-' }}</td>
                            <td>{{ $it->ptkp }}</td>
                            <td class="mono text-right">{{ number_format($it->gross, 0, ',', '.') }}</td>
                            <td>
                                <span class="mono">{{ rtrim(rtrim(number_format($it->rate, 2, '.', ''), '0'), '.') }}</span>
                                <span class="badge {{ $it->is_rate_manual ? 'badge-amber' : 'badge-green' }}">{{ $it->is_rate_manual ? 'Manual' : 'TER' }}</span>
                            </td>
                            <td class="text-right">
                                <details class="inline">
                                    <summary class="btn-icon text-cash-blue hover:bg-blue-50 cursor-pointer list-none" title="Edit gross/tarif">
                                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                                    </summary>
                                    <form method="POST" action="{{ route('pph21.item.update', $it) }}" class="mt-2 flex flex-wrap items-end gap-2 rounded-lg border border-outline-variant p-2">
                                        @csrf
                                        @method('PATCH')
                                        <div>
                                            <label class="label">Gross</label>
                                            <input type="number" name="gross" value="{{ $it->gross }}" min="0" step="1" required class="input mono" style="width:10rem">
                                        </div>
                                        <div>
                                            <label class="label">Tarif manual (kosong = TER)</label>
                                            <input type="number" name="rate_manual" value="{{ $it->is_rate_manual ? $it->rate : '' }}" min="0" step="0.01" class="input mono" style="width:8rem">
                                        </div>
                                        <button class="btn btn-primary btn-sm">Simpan</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('pph21.item.destroy', $it) }}" class="inline" data-confirm="Hapus {{ $it->nik }} dari periode ini?">
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
                            <td colspan="7" class="text-center text-secondary">Belum ada data gaji — unduh template, isi GROSS, lalu upload.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
