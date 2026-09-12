@extends('layouts.app')

@section('title', 'Pengaturan Toko')

@section('content')
    <div class="page-head animate-rise">
        <div>
            <h2 class="page-head-title">Pengaturan Toko</h2>
            <p class="page-head-subtitle">Semua konfigurasi dalam 1 halaman — 1 baris per toko, bukan hardcode (PRD 4.5)</p>
        </div>
    </div>

    @php($pg = $settings->parameterGenerate())
    @php($libur = $settings->hari_libur ?? ['dates' => [], 'weekly' => []])

    <form method="POST" action="{{ route('pengaturan.update') }}" class="flex flex-col gap-6">
        @csrf

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Info Toko -->
            <div class="card-pad">
                <h3 class="mb-4 flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">store</span>
                    Info Toko
                </h3>
                <div class="flex flex-col gap-3">
                    <div>
                        <label class="label">Nama Toko / Bengkel</label>
                        <input type="text" name="nama_toko" value="{{ $settings->nama_toko }}" class="input">
                    </div>
                    <div>
                        <label class="label">Alamat</label>
                        <textarea name="alamat" rows="2" class="input">{{ $settings->alamat }}</textarea>
                    </div>
                    <div>
                        <label class="label">No. Telepon</label>
                        <input type="text" name="telepon" value="{{ $settings->telepon }}" class="input">
                    </div>
                    <div>
                        <label class="label">NPWP</label>
                        <input type="text" name="npwp" value="{{ $settings->npwp }}" class="input" placeholder="00.000.000.0-000.000">
                        <span class="hint">Tampil di header struk penjualan & invoice pembelian (bawah alamat).</span>
                    </div>
                </div>
            </div>

            <!-- Harga & Margin -->
            <div class="card-pad">
                <h3 class="mb-4 flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">payments</span>
                    Harga & Margin
                </h3>
                <div>
                    <label class="label">Margin Default (%) <span class="text-danger-red">*</span></label>
                    <input type="number" name="margin_default" min="0" max="1000" value="{{ $settings->margin_default }}" class="input">
                    <span class="hint">harga_jual = harga_beli × (1 + margin%). SKU dengan margin_override memakai nilai sendiri (PRD 4.1).</span>
                </div>
                <div class="mt-3">
                    <label class="label">PPN Otomatis Pembelian (%)</label>
                    <input type="number" name="ppn_persen" min="0" max="100" value="{{ $settings->ppn_persen ?? 11 }}" class="input">
                    <span class="hint">Tarif utk hitung PPN otomatis = round((Subtotal − Diskon) × tarif). Berlaku untuk pembelian baru (form centang otomatis & import Excel yang PPN-nya kosong semua); invoice lama tidak diubah ulang.</span>
                </div>
            </div>

            <!-- Jam & Hari Operasional -->
            <div class="card-pad">
                <h3 class="mb-4 flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">schedule</span>
                    Jam & Hari Operasional (dipatuhi Generate)
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Jam Buka</label>
                        <input type="time" name="jam_buka" value="{{ $settings->jam_buka }}" class="input">
                    </div>
                    <div>
                        <label class="label">Jam Tutup</label>
                        <input type="time" name="jam_tutup" value="{{ $settings->jam_tutup }}" class="input">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="label">Hari Tutup Mingguan</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ([['Mon','Senin'],['Tue','Selasa'],['Wed','Rabu'],['Thu','Kamis'],['Fri','Jumat'],['Sat','Sabtu'],['Sun','Minggu']] as [$v, $lbl])
                            <label class="flex items-center gap-1.5 rounded-lg border border-outline-variant px-3 py-1.5 text-body-sm">
                                <input type="checkbox" name="libur_weekly[]" value="{{ $v }}" @checked(in_array($v, $libur['weekly'] ?? [])) class="rounded border-outline-variant text-primary focus:ring-primary">
                                {{ $lbl }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="mt-3">
                    <label class="label">Tanggal Tutup Khusus</label>
                    <input type="date" name="libur_dates[]" value="{{ $libur['dates'][0] ?? '' }}" class="input">
                    <input type="date" name="libur_dates[]" value="{{ $libur['dates'][1] ?? '' }}" class="input mt-2">
                    <span class="hint">Contoh: tanggal merah / tutup toko. Kosongkan bila tidak ada.</span>
                </div>
            </div>

            <!-- Parameter Generate -->
            <div class="card-pad">
                <h3 class="mb-4 flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">auto_awesome</span>
                    Parameter Generate Transaksi
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Nota / Hari (min)</label>
                        <input type="number" name="nota_min" min="1" max="100" value="{{ $pg['nota_min'] }}" class="input">
                    </div>
                    <div>
                        <label class="label">Nota / Hari (max)</label>
                        <input type="number" name="nota_max" min="1" max="100" value="{{ $pg['nota_max'] }}" class="input">
                    </div>
                    <div>
                        <label class="label">Toleransi (%)</label>
                        <input type="number" name="toleransi_persen" min="0" max="20" value="{{ $pg['toleransi_persen'] }}" class="input">
                    </div>
                    <div>
                        <label class="label">Diskon Maksimum yg Diizinkan (Variasi Min, %)</label>
                        <input type="number" name="variasi_min" min="-50" max="0" value="{{ $pg['variasi_min'] }}" class="input">
                        <span class="hint">Negatif = diskon terbesar yang boleh terjadi (mis. −10 → termurah 90% harga master).</span>
                    </div>
                    <div>
                        <label class="label">Diskon Minim / Harga Pas (Variasi Max, %)</label>
                        <input type="number" name="variasi_max" min="-50" max="50" value="{{ $pg['variasi_max'] }}" class="input">
                        <span class="hint">0% = harga master persis; positif = boleh lebih mahal dari master. Tidak pernah di bawah harga beli.</span>
                    </div>
                    <div>
                        <label class="label">Maks Qty / Item / Nota</label>
                        <input type="number" name="maks_qty_item_per_nota" min="1" max="20" value="{{ $pg['maks_qty_item_per_nota'] }}" class="input">
                        <span class="hint">Batas unit 1 jenis barang dalam 1 nota hasil generate (mis. 4 = 1 nota bisa 1-4 ban) (default 3).</span>
                    </div>
                    <div>
                        <label class="label">Maks Jenis Barang (SKU) / Nota</label>
                        <input type="number" name="maks_sku_per_nota" min="1" max="5" value="{{ $pg['maks_sku_per_nota'] ?? 1 }}" class="input">
                        <span class="hint">1 = tiap nota hanya berisi 1 jenis barang (disarankan utk toko ban — 1 nota = 1-4 ban dari 1 SKU). 2-5 = maksimal jenis campuran per nota (default 1).</span>
                    </div>
                    <div>
                        <label class="label">Ambang Stok Menipis</label>
                        <input type="number" name="ambang_stok_menipis" min="0" value="{{ $settings->ambang_stok_menipis }}" class="input">
                    </div>
                </div>

                <div class="mt-4">
                    <label class="label">Item Dikecualikan Default <span class="text-secondary">(opsional — kosongkan = semua barang ikut)</span></label>
                    <x-barang-checklist :barangs="$barangs"
                        :selected="$pg['excluded_item_ids'] ?? []"
                        idPrefix="set"
                        placeholder="Cari barang dikecualikan…" />
                    <span class="hint">Default ini tercantum di halaman Generate sebagai kondisi awal (tetap bisa diubah per generate).</span>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button class="btn btn-primary shadow-sm">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">save</span> Simpan Semua Pengaturan
            </button>
        </div>
    </form>

    <!-- Terapkan Margin ke Semua SKU (form TERPISAH — jangan pernah diletakkan di dalam form utama) -->
    <div class="card-pad">
        <div class="mb-3">
            <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">calculate</span>
                Terapkan Margin ke Semua SKU Otomatis
            </h3>
            <p class="text-body-sm text-secondary">Setelah mengubah Margin Default & menekan <strong>Simpan Semua Pengaturan</strong>, klik tombol ini utk menghitung ulang harga jual semua SKU mode otomatis yang tidak punya override (histori harga lama tetap). SKU manual_terkunci & ber-override tidak disentuh.</p>
        </div>
        <form method="POST" action="{{ route('pengaturan.terapkan-margin') }}"
            onsubmit="return confirm('Terapkan margin {{ $settings->margin_default }}% ke SEMUA SKU mode otomatis (tanpa override)? Histori harga lama tetap.');">
            @csrf
            <button class="btn btn-secondary">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">calculate</span>
                Terapkan ke Semua SKU Otomatis ({{ $settings->margin_default }}%)
            </button>
        </form>
    </div>

    <!-- Keamanan: Ubah Password Admin -->
    <div class="card-pad">
        <div class="mb-4">
            <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">lock</span>
                Keamanan — Ubah Password Admin
            </h3>
            <p class="text-body-sm text-secondary">Ganti password akun login ({{ auth()->user()->email }}). Minimal 8 karakter.</p>
        </div>
        <form method="POST" action="{{ route('pengaturan.password') }}" class="max-w-md">
            @csrf
            <div class="flex flex-col gap-3">
                <div>
                    <label class="label">Password Lama</label>
                    <input type="password" name="password_lama" required autocomplete="current-password" class="input @error('password_lama') input-error @enderror">
                    @error('password_lama')<span class="hint text-danger-red">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="label">Password Baru</label>
                    <input type="password" name="password" required minlength="8" autocomplete="new-password" class="input @error('password') input-error @enderror">
                    @error('password')<span class="hint text-danger-red">{{ $message }}</span>@enderror
                    <span class="hint">Minimal 8 karakter.</span>
                </div>
                <div>
                    <label class="label">Ulangi Password Baru</label>
                    <input type="password" name="password_confirmation" required autocomplete="new-password" class="input">
                </div>
            </div>
            <div class="mt-4">
                <button class="btn btn-amber shadow-sm">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">lock_reset</span> Ubah Password
                </button>
            </div>
        </form>
    </div>

    <!-- Template Export POS (PRD 4.2) -->
    <div class="card-pad">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">view_column</span>
                    Template Export POS (konfigurabel)
                </h3>
                <p class="text-body-sm text-secondary">
                    Nama & urutan kolom, wajib/opsional, format, nilai default — dipakai Format A. Versi aktif: <span class="mono font-bold">{{ $settings->template_version }}</span>.
                </p>
            </div>
            <form method="POST" action="{{ route('pengaturan.template.reset') }}" class="flex items-center gap-2">
                @csrf
                <button class="btn btn-secondary btn-sm">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">restart_alt</span> Reset ke Default v1
                </button>
            </form>
        </div>

        <form method="POST" action="{{ route('pengaturan.template') }}" class="flex flex-col gap-3">
            @csrf
            <div class="flex items-center gap-2">
                <label class="label mb-0 w-40">Versi Template</label>
                <input type="text" name="template_version" value="{{ $settings->template_version }}" class="input mono w-40" required>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl tbl-cards">
                    <thead>
                        <tr>
                            <th>Urutan</th>
                            <th>Nama Kolom</th>
                            <th>Wajib/Opsional</th>
                            <th>Format</th>
                            <th>Nilai Default</th>
                            <th>Sumber Data</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="tbody-template" class="rise-rows">
                        @foreach ($mapping as $i => $m)
                            <tr>
                                <td data-label="Urutan" data-stack="1"><input type="number" name="kolom[{{ $i }}][urutan]" value="{{ $m->urutan }}" min="1" class="input mono w-16 text-center" required></td>
                                <td data-label="Nama Kolom" data-stack="1"><input type="text" name="kolom[{{ $i }}][nama_kolom]" value="{{ $m->nama_kolom }}" class="input mono" required></td>
                                <td data-label="Wajib/Opsional" data-stack="1">
                                    <select name="kolom[{{ $i }}][wajib_opsional]" class="input">
                                        <option value="wajib" @selected($m->wajib_opsional === 'wajib')>Wajib</option>
                                        <option value="opsional" @selected($m->wajib_opsional === 'opsional')>Opsional</option>
                                    </select>
                                </td>
                                <td data-label="Format" data-stack="1"><input type="text" name="kolom[{{ $i }}][format]" value="{{ $m->format }}" class="input mono" placeholder="Y-m-d H:i"></td>
                                <td data-label="Nilai Default" data-stack="1"><input type="text" name="kolom[{{ $i }}][nilai_default]" value="{{ $m->nilai_default }}" class="input" placeholder="mis. CASH"></td>
                                <td data-label="Sumber Data" data-stack="1"><input type="text" name="kolom[{{ $i }}][sumber_data]" value="{{ $m->sumber_data }}" class="input" placeholder="mis. kode_barang"></td>
                                <td data-label="Aksi" class="text-right"><button type="button" class="btn-icon text-danger-red" data-hapus-template><span class="material-symbols-outlined text-[18px]">delete</span></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between">
                <button type="button" class="btn btn-secondary btn-sm" id="tambah-kolom">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">add</span> Tambah Kolom
                </button>
                <button class="btn btn-primary shadow-sm">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">save</span> Simpan Template
                </button>
            </div>
        </form>
        <p class="mt-3 text-[11px] text-secondary">
            Sumber data valid: ref_transaksi, tanggal_waktu, customer_id, jenis_transaksi, tipe_pembayaran, jatuh_tempo, kode_barang, qty, harga_jual, no_sn — selain itu dipakai nilai_default.
        </p>
    </div>

    <!-- Status Lingkungan Excel (diagnosa upload/import — read-only, di luar semua form) -->
    <div class="card-pad">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="flex items-center gap-2 text-headline-sm text-on-surface">
                    <span class="material-symbols-outlined text-[18px] text-secondary" aria-hidden="true">table_view</span>
                    Status Lingkungan Excel
                </h3>
                <p class="text-body-sm text-secondary">
                    Diagnosa cepat utk masalah upload/import Excel. Di shared hosting, ekstensi web PHP-FPM bisa beda dengan SSH CLI —
                    kalau <span class="mono">simplexml/dom/xmlreader</span> tidak aktif, <strong>download & export jalan tapi upload 500</strong>.
                </p>
            </div>
            @if (count($excelEnv['ekstensi']) === 0)
                <span class="rounded-lg bg-green-50 px-3 py-1 text-body-sm font-medium text-lunas-green">✓ Semua ekstensi Excel siap</span>
            @else
                <span class="rounded-lg bg-red-50 px-3 py-1 text-body-sm font-medium text-danger-red">⚠ {{ count($excelEnv['ekstensi']) }} ekstensi belum aktif</span>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div>
                <p class="label mb-1">Ekstensi PHP wajib (baca & tulis .xlsx)</p>
                <ul class="flex flex-col gap-1">
                    @foreach (\App\Services\ExcelImportService::EKSTENSI_EXCEL as $ext => $ket)
                        <li class="flex items-center gap-2 text-body-sm">
                            @if (extension_loaded($ext))
                                <span class="material-symbols-outlined text-[16px] text-lunas-green">check_circle</span>
                                <span class="mono font-medium">{{ $ext }}</span>
                                <span class="text-secondary">{{ $ket }}</span>
                            @else
                                <span class="material-symbols-outlined text-[16px] text-danger-red">error</span>
                                <span class="mono font-medium text-danger-red">{{ $ext }}</span>
                                <span class="text-danger-red">{{ $ket }} — aktifkan di hPanel → PHP Configuration</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
            <div>
                <p class="label mb-1">Nilai PHP aktif (server ini)</p>
                <ul class="flex flex-col gap-1">
                    @foreach ($excelEnv['ini'] as $nama => $nilai)
                        <li class="flex items-center gap-2 text-body-sm">
                            <span class="mono font-medium w-48">{{ $nama }}</span>
                            <span class="mono">{{ $nilai }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="hint mt-2">
                    File Excel yang terlalu besar (mis. &gt; {{ $excelEnv['ini']['upload_max_filesize'] }}) ditolak saat upload —
                    naikkan <span class="mono">upload_max_filesize</span> &amp; <span class="mono">post_max_size</span> di hPanel bila perlu.
                </p>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const tbody = document.getElementById('tbody-template');
        let idx = tbody.querySelectorAll('tr').length;

        document.getElementById('tambah-kolom').addEventListener('click', () => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="Urutan" data-stack="1"><input type="number" name="kolom[${idx}][urutan]" value="${idx + 1}" min="1" class="input mono w-16 text-center" required></td>
                <td data-label="Nama Kolom" data-stack="1"><input type="text" name="kolom[${idx}][nama_kolom]" class="input mono" required></td>
                <td data-label="Wajib/Opsional" data-stack="1"><select name="kolom[${idx}][wajib_opsional]" class="input"><option value="opsional">Opsional</option><option value="wajib">Wajib</option></select></td>
                <td data-label="Format" data-stack="1"><input type="text" name="kolom[${idx}][format]" class="input mono" placeholder="Y-m-d H:i"></td>
                <td data-label="Nilai Default" data-stack="1"><input type="text" name="kolom[${idx}][nilai_default]" class="input" placeholder="mis. CASH"></td>
                <td data-label="Sumber Data" data-stack="1"><input type="text" name="kolom[${idx}][sumber_data]" class="input" placeholder="mis. kode_barang"></td>
                <td data-label="Aksi" class="text-right"><button type="button" class="btn-icon text-danger-red" data-hapus-template><span class="material-symbols-outlined text-[18px]">delete</span></button></td>
            `;
            tbody.appendChild(tr);
            idx++;
        });

        tbody.addEventListener('click', (e) => {
            if (e.target.closest('[data-hapus-template]')) {
                e.target.closest('tr').remove();
            }
        });
    </script>
@endpush
