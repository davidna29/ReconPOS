@extends('layouts.app')

@section('title', $barang ? 'Edit Barang' : 'Tambah Barang')

@section('content')
    @php($isEdit = (bool) $barang)
    <div class="page-head">
        <div>
            <h2 class="page-head-title">{{ $isEdit ? 'Edit Barang' : 'Tambah Barang' }}</h2>
            <p class="page-head-subtitle">Kode Barang terkunci saat edit; Harga Beli & Stock dikelola lewat jalur khusus (PRD 4.3b)</p>
        </div>
    </div>

    <div class="card max-w-3xl p-6">
        <form method="POST" action="{{ $isEdit ? route('barang.update', $barang) : route('barang.store') }}" class="flex flex-col gap-4">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

            <div>
                <label class="label">Barcode / Kode Barang {!! $isEdit ? '' : '<span class="text-danger-red">*</span>' !!}</label>
                <input type="text" name="kode_barang" value="{{ old('kode_barang', $barang?->kode_barang) }}"
                    {{ $isEdit ? 'readonly disabled' : 'required' }} placeholder="mis. BLT-001"
                    class="{{ $isEdit ? 'input-readonly mono' : 'input mono' }}">
                @if ($isEdit)
                    <span class="hint">Read-only — tidak bisa diubah setelah dibuat (kunci referensi transaksi & export).</span>
                @endif
            </div>

            <div>
                <label class="label">Nama Barang <span class="text-danger-red">*</span></label>
                <input type="text" name="nama" value="{{ old('nama', $barang?->nama) }}" required class="input">
            </div>

            <div>
                <label class="label">Kategori</label>
                <input list="kategori-list" name="kategori" value="{{ old('kategori', $barang?->kategori?->nama) }}" placeholder="Pilih / ketik baru" class="input">
                <datalist id="kategori-list">
                    @foreach ($kategoris as $k)
                        <option value="{{ $k->nama }}"></option>
                    @endforeach
                </datalist>
                <span class="hint">Kategori baru otomatis dibuat bila belum ada (PRD 4.3c).</span>
            </div>

            <div>
                <label class="label">Merk</label>
                <input list="merk-list" name="merk" value="{{ old('merk', $barang?->merk) }}" placeholder="mis. Gajah Tunggal (GT)" maxlength="100" class="input">
                <datalist id="merk-list">
                    @foreach (($merks ?? collect()) as $m)
                        <option value="{{ $m }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div>
                <label class="label">Varian</label>
                <input list="varian-list" name="varian" value="{{ old('varian', $barang?->varian) }}" placeholder="mis. SAVERO M/T PRO" maxlength="100" class="input">
                <datalist id="varian-list">
                    @foreach (($varians ?? collect()) as $v)
                        <option value="{{ $v }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div>
                <label class="label">Ukuran Ban</label>
                <input list="ukuran-list" name="ukuran_ban" value="{{ old('ukuran_ban', $barang?->ukuran_ban) }}" placeholder="mis. 265/70 R17" maxlength="50" class="input">
                <datalist id="ukuran-list">
                    @foreach (($ukurans ?? collect()) as $u)
                        <option value="{{ $u }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div>
                <label class="label">Satuan</label>
                <input type="text" name="satuan" value="{{ old('satuan', $barang?->satuan ?? 'pcs') }}" class="input">
            </div>

            <div>
                <label class="label">SN or Non-SN <span class="text-danger-red">*</span></label>
                <select name="sn_atau_non_sn" required class="input" {{ ($terkunci ?? false) ? 'disabled' : '' }}>
                    <option value="NON_SN" @selected(old('sn_atau_non_sn', $barang?->sn_atau_non_sn ?? 'NON_SN') === 'NON_SN')>NON_SN</option>
                    <option value="SN" @selected(old('sn_atau_non_sn', $barang?->sn_atau_non_sn) === 'SN')>SN</option>
                </select>
                @if ($terkunci ?? false)
                    <input type="hidden" name="sn_atau_non_sn" value="{{ $barang->sn_atau_non_sn }}">
                    <span class="hint">Terkunci — sudah ada transaksi (PRD 4.3b).</span>
                @endif
            </div>

            <div>
                <label class="label">Margin Override (%)</label>
                <input type="number" name="margin_override" min="0" max="1000" value="{{ old('margin_override', $barang?->margin_override) }}" placeholder="kosong = ikut margin global ({{ $settings?->margin_default }}%)" class="input">
            </div>

            <div>
                <label class="label">Mode Harga Jual (PRD 4.1c)</label>
                <div class="segmented w-full" id="mode-toggle">
                    <button type="button" data-mode="otomatis" class="flex-1 {{ old('mode_harga_jual', $barang?->mode_harga_jual ?? 'otomatis') === 'otomatis' ? 'active-cash' : '' }}">Hitung Ulang Otomatis</button>
                    <button type="button" data-mode="manual_terkunci" class="flex-1 {{ old('mode_harga_jual', $barang?->mode_harga_jual) === 'manual_terkunci' ? 'active-hutang' : '' }}">Kunci Harga Jual Manual</button>
                </div>
                <input type="hidden" name="mode_harga_jual" id="mode_harga_jual" value="{{ old('mode_harga_jual', $barang?->mode_harga_jual ?? 'otomatis') }}">
            </div>

            <div>
                <label class="label">Harga Beli Terakhir</label>
                <input type="text" value="{{ $barang ? rupiah($barang->harga_beli_terakhir) : 'Terisi setelah pembelian pertama' }}" class="input-readonly" readonly>
                <span class="hint">Read-only — berubah otomatis dari Transaksi Pembelian (PRD 4.3b).</span>
            </div>

            <div>
                <label class="label" id="harga-jual-label">Harga Jual {!! $barang && $barang->mode_harga_jual === 'otomatis' ? '' : '(Kunci Manual: wajib)' !!}</label>
                <input type="number" name="harga_jual" min="0" value="{{ old('harga_jual', $barang?->harga_jual) }}"
                    id="harga-jual-input"
                    class="{{ $barang && $barang->mode_harga_jual === 'otomatis' ? 'input-readonly' : 'input' }}"
                    {{ $barang && $barang->mode_harga_jual === 'otomatis' ? 'readonly' : 'required' }}>
                <span class="hint" id="harga-jual-hint">
                    @if ($barang && $barang->mode_harga_jual === 'otomatis')
                        Otomatis: harga beli {{ rupiah($barang->harga_beli_terakhir) }} × margin {{ $settings?->margin_default }}% = {{ rupiah($barang->harga_jual) }}
                    @else
                        Diisi manual pada mode Kunci Manual.
                    @endif
                </span>
            </div>

            <div>
                <label class="label">Lokasi Rak</label>
                <input type="text" name="lokasi_rak" value="{{ old('lokasi_rak', $barang?->lokasi_rak) }}" placeholder="mis. Rak A1" class="input">
            </div>

            <div>
                <label class="label">Status <span class="text-danger-red">*</span></label>
                <select name="status" class="input" required>
                    @foreach (['Dijual', 'Untuk Servis', 'Dijual & Untuk Servis'] as $st)
                        <option value="{{ $st }}" @selected(old('status', $barang?->status ?? 'Dijual & Untuk Servis') === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label">Deskripsi</label>
                <textarea name="deskripsi" rows="2" class="input">{{ old('deskripsi', $barang?->deskripsi) }}</textarea>
            </div>

            @if (! $isEdit)
                <div>
                    <label class="label">Stok Awal (jalur khusus barang baru)</label>
                    <input type="number" name="stock_awal" min="0" value="{{ old('stock_awal', 0) }}" class="input">
                    <span class="hint">Tercatat sebagai baris "Stok Awal" di Kartu Stok (PRD 4.3b).</span>
                </div>
            @endif
            </div>

            <div class="form-actions-sticky flex flex-col gap-3 sm:flex-row sm:items-center">
                <button type="submit" class="btn btn-primary shadow-sm max-sm:w-full">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">save</span>
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Barang' }}
                </button>
                <a href="{{ route('barang.index') }}" class="btn btn-secondary max-sm:w-full">Batal</a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggle = document.getElementById('mode-toggle');
            const hidden = document.getElementById('mode_harga_jual');
            const hargaInput = document.getElementById('harga-jual-input');
            const hargaHint = document.getElementById('harga-jual-hint');
            const hargaLabel = document.getElementById('harga-jual-label');

            if (!toggle) return;
            toggle.querySelectorAll('button').forEach(btn => {
                btn.addEventListener('click', () => {
                    toggle.querySelectorAll('button').forEach(b => {
                        b.classList.remove('active-cash', 'active-hutang');
                    });
                    const mode = btn.dataset.mode;
                    hidden.value = mode;
                    if (mode === 'otomatis') {
                        btn.classList.add('active-cash');
                        hargaInput.readOnly = true;
                        hargaInput.classList.remove('input');
                        hargaInput.classList.add('input-readonly');
                        hargaInput.removeAttribute('required');
                        hargaLabel.innerHTML = 'Harga Jual';
                        hargaHint.textContent = 'Otomatis: dihitung dari Harga Beli + margin saat pembelian disimpan.';
                    } else {
                        btn.classList.add('active-hutang');
                        hargaInput.readOnly = false;
                        hargaInput.classList.remove('input-readonly');
                        hargaInput.classList.add('input');
                        hargaInput.setAttribute('required', 'required');
                        hargaLabel.innerHTML = 'Harga Jual (Kunci Manual: wajib)';
                        hargaHint.textContent = 'Diisi manual — tidak berubah saat restock.';
                    }
                });
            });
        });
    </script>
@endpush
