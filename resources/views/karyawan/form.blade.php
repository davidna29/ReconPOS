@extends('layouts.app')

@section('title', $karyawan ? 'Edit Karyawan' : 'Tambah Karyawan')

@section('content')
    <div class="max-w-2xl">
        <h2 class="page-head-title">{{ $karyawan ? 'Edit Karyawan' : 'Tambah Karyawan' }}</h2>
        <p class="page-head-subtitle">Master karyawan PPh 21 (NPWP CV tunggal dari Pengaturan)</p>

        <div class="card mt-4 p-6">
            <form method="POST" action="{{ $karyawan ? route('karyawan.update', $karyawan) : route('karyawan.store') }}" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @csrf
                @if ($karyawan)
                    @method('PUT')
                @endif

                <div>
                    <label class="label">Nama <span class="text-danger-red">*</span></label>
                    <input type="text" name="nama" value="{{ old('nama', $karyawan?->nama) }}" required maxlength="100" class="input">
                </div>
                <div>
                    <label class="label">NIK (16 digit) <span class="text-danger-red">*</span></label>
                    <input type="text" name="nik" value="{{ old('nik', $karyawan?->nik) }}" required pattern="\d{16}" maxlength="16" inputmode="numeric" class="input mono" placeholder="3275xxxxxxxxxxxx">
                </div>
                <div>
                    <label class="label">Status Pegawai</label>
                    <select name="status_pegawai" class="input">
                        @foreach ($ref['status_pegawai'] as $s)
                            <option value="{{ $s }}" @selected(old('status_pegawai', $karyawan?->status_pegawai ?? 'Resident') === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Nomor Passport (WNA)</label>
                    <input type="text" name="passport" value="{{ old('passport', $karyawan?->passport) }}" maxlength="50" class="input">
                </div>
                <div>
                    <label class="label">Status PTKP</label>
                    <select name="ptkp" class="input">
                        @foreach ($ref['ptkp'] as $p)
                            <option value="{{ $p }}" @selected(old('ptkp', $karyawan?->ptkp ?? 'TK/0') === $p)>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Posisi</label>
                    <input type="text" name="posisi" value="{{ old('posisi', $karyawan?->posisi ?? 'Staff') }}" required maxlength="60" class="input">
                </div>
                <div>
                    <label class="label">Cabang (kategori internal, tidak ikut export)</label>
                    <input type="text" name="cabang" value="{{ old('cabang', $karyawan?->cabang) }}" maxlength="50" class="input" list="daftar-cabang" placeholder="mis. TERMINAL BAN 1">
                    <datalist id="daftar-cabang">
                        @foreach ($daftarCabang as $c)
                            <option value="{{ $c }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <div>
                    <label class="label">Sertifikat / Fasilitas</label>
                    <select name="sertifikat" class="input">
                        @foreach ($ref['sertifikat'] as $s)
                            <option value="{{ $s }}" @selected(old('sertifikat', $karyawan?->sertifikat ?? 'N/A') === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Kode Objek Pajak</label>
                    <select name="kode_objek" class="input">
                        @foreach ($ref['kode_objek'] as $k)
                            <option value="{{ $k }}" @selected(old('kode_objek', $karyawan?->kode_objek ?? '21-100-01') === $k)>{{ $k }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="label">ID TKU (22 digit, kosongkan = otomatis NPWP + 000000)</label>
                    <input type="text" name="id_tku" value="{{ old('id_tku', $karyawan?->id_tku) }}" pattern="\d{22}" maxlength="22" inputmode="numeric" class="input mono">
                </div>
                <div>
                    <label class="label">Link KTP</label>
                    <input type="url" name="link_ktp" value="{{ old('link_ktp', $karyawan?->link_ktp) }}" maxlength="500" class="input" placeholder="https://...">
                </div>
                <div>
                    <label class="label">Link Kartu Keluarga</label>
                    <input type="url" name="link_kk" value="{{ old('link_kk', $karyawan?->link_kk) }}" maxlength="500" class="input" placeholder="https://...">
                </div>
                @for ($i = 1; $i <= 3; $i++)
                    <div>
                        <label class="label">Link Berkas {{ $i }}</label>
                        <input type="url" name="link_berkas_{{ $i }}" value="{{ old('link_berkas_' . $i, $karyawan?->{'link_berkas_' . $i}) }}" maxlength="500" class="input" placeholder="https://...">
                    </div>
                @endfor
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_aktif" value="1" @checked(old('is_aktif', $karyawan?->is_aktif ?? true)) class="h-4 w-4" id="is_aktif">
                    <label for="is_aktif" class="text-body-sm">Aktif (ikut export bulanan)</label>
                </div>

                <div class="flex gap-3 md:col-span-2">
                    <button class="btn btn-primary shadow-sm">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">save</span>
                        {{ $karyawan ? 'Simpan Perubahan' : 'Simpan Karyawan' }}
                    </button>
                    <a href="{{ route('karyawan.index') }}" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
