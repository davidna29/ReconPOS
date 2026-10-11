@extends('layouts.app')

@section('title', 'Data Karyawan')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Data Karyawan</h2>
            <p class="page-head-subtitle">Master karyawan PPh 21 — NIK 16 digit sebagai kunci unik</p>
        </div>
    </div>

    @include('partials.table-toolbar', ['actionsId' => 'toolbar-karyawan-aksi', 'actionsLabel' => 'Tambah / Template', 'actionsIcon' => 'add', 'actions' => '
        <a href="' . route('karyawan.create') . '" class="btn btn-primary btn-sm text-[12px] md:text-body-sm">
            <span class="material-symbols-outlined text-[16px] md:text-[18px]" aria-hidden="true">add</span> Tambah Data
        </a>
        <a href="' . route('karyawan.template.download') . '" class="btn btn-success btn-sm text-[11px] md:text-body-sm">
            <span class="material-symbols-outlined text-[14px] md:text-[16px]" aria-hidden="true">download</span> Template Excel
        </a>
    ', 'filters' => '
        <select name="cabang" aria-label="Filter cabang" class="input w-auto py-1.5 text-[13px] shadow-sm md:py-2 md:text-body-md" onchange="this.form.submit()">
            <option value="">Semua cabang</option>
            ' . $daftarCabang->map(fn ($c) => '<option value="' . e($c) . '"' . (request('cabang') === (string) $c ? ' selected' : '') . '>' . e($c) . '</option>')->implode('') . '
        </select>
    '])

    <div class="card animate-rise p-3 md:p-4 min-w-0" style="--rise-delay:.06s">
        <x-collapse id="collapse-import-karyawan" label="Import / Update via Excel" icon="upload_file">
            <form method="POST" action="{{ route('karyawan.template.import') }}" enctype="multipart/form-data" class="rounded-lg border border-outline-variant p-3 min-w-0">
                @csrf
                <p class="mb-2 text-body-sm text-secondary">NIK yang sudah ada akan <strong>diperbarui</strong>. Satu baris gagal → seluruh file dibatalkan.</p>
                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <label class="btn btn-secondary btn-sm shrink-0 cursor-pointer">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">folder_open</span> Pilih File
                            <input type="file" name="file" accept=".xlsx" class="sr-only" required>
                        </label>
                        <span data-filename class="min-w-0 flex-1 truncate text-body-sm text-secondary">Belum ada file dipilih</span>
                    </div>
                    <button class="btn btn-amber btn-sm w-full">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">upload</span> Upload Template Excel
                    </button>
                </div>
            </form>
        </x-collapse>
    </div>

    <div class="card animate-rise overflow-hidden min-w-0" style="--rise-delay:.12s">
        <div class="overflow-x-auto min-w-0 max-w-full">
            <table class="tbl tbl-cards">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>NIK</th>
                        <th>Cabang</th>
                        <th>PTKP</th>
                        <th>Posisi</th>
                        <th>Sertifikat</th>
                        <th>Kode Objek</th>
                        <th>Berkas</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($karyawans as $k)
                        <tr>
                            <td class="font-semibold">{{ $k->nama }}</td>
                            <td class="mono">{{ $k->nik }}</td>
                            <td>{{ $k->labelCabang() }}</td>
                            <td>{{ $k->ptkp }}</td>
                            <td>{{ $k->posisi }}</td>
                            <td>{{ $k->sertifikat }}</td>
                            <td class="mono">{{ $k->kode_objek }}</td>
                            <td>
                                <div class="flex gap-1">
                                    @foreach (['link_ktp' => 'KTP', 'link_kk' => 'KK', 'link_berkas_1' => 'B1', 'link_berkas_2' => 'B2', 'link_berkas_3' => 'B3'] as $field => $label)
                                        @if ($k->$field)
                                            <a href="{{ $k->$field }}" target="_blank" rel="noopener" class="badge badge-green" title="{{ $k->$field }}">{{ $label }}</a>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                <span class="badge {{ $k->is_aktif ? 'badge-green' : 'badge-red' }}">{{ $k->is_aktif ? 'Aktif' : 'Nonaktif' }}</span>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('karyawan.edit', $k) }}" class="btn-icon text-cash-blue hover:bg-blue-50" title="Edit">
                                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                                </a>
                                <form method="POST" action="{{ route('karyawan.destroy', $k) }}" class="inline" data-confirm="Hapus karyawan {{ $k->nama }}?">
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
                            <td colspan="10" class="text-center text-secondary">Belum ada karyawan — tambah manual atau import template Excel.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($karyawans->hasPages())
            <div class="px-3 py-2">{{ $karyawans->links() }}</div>
        @endif
    </div>
@endsection
