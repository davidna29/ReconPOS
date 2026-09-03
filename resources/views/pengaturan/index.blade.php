@extends('layouts.app')

@section('title', 'Pengaturan Toko')

@section('content')
    <div class="flex items-end justify-between">
        <div>
            <h2 class="text-headline-lg text-on-surface">Pengaturan Toko</h2>
            <p class="mt-1 text-body-md text-secondary">Semua konfigurasi dalam 1 halaman — 1 baris per toko, bukan hardcode (PRD 4.5)</p>
        </div>
    </div>

    @php($pg = $settings->parameterGenerate())
    @php($libur = $settings->hari_libur ?? ['dates' => [], 'weekly' => []])

    <form method="POST" action="{{ route('pengaturan.update') }}" class="flex flex-col gap-6">
        @csrf

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Info Toko -->
            <div class="card-pad">
                <h3 class="mb-4 text-headline-sm">Info Toko</h3>
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
                <h3 class="mb-4 text-headline-sm">Harga & Margin</h3>
                <div>
                    <label class="label">Margin Default (%) <span class="text-danger-red">*</span></label>
                    <input type="number" name="margin_default" min="0" max="1000" value="{{ $settings->margin_default }}" class="input">
                    <span class="hint">harga_jual = harga_beli × (1 + margin%). SKU dengan margin_override memakai nilai sendiri (PRD 4.1).</span>
                </div>
            </div>

            <!-- Jam & Hari Operasional -->
            <div class="card-pad">
                <h3 class="mb-4 text-headline-sm">Jam & Hari Operasional (dipatuhi Generate)</h3>
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
                <h3 class="mb-4 text-headline-sm">Parameter Generate Transaksi</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Nota / Hari (min)</label>
                        <input type="number" name="nota_min" min="1" max="30" value="{{ $pg['nota_min'] }}" class="input">
                    </div>
                    <div>
                        <label class="label">Nota / Hari (max)</label>
                        <input type="number" name="nota_max" min="1" max="30" value="{{ $pg['nota_max'] }}" class="input">
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
            <button class="btn btn-primary">
                <span class="material-symbols-outlined text-[18px]">save</span> Simpan Semua Pengaturan
            </button>
        </div>
    </form>

    <!-- Terapkan Margin ke Semua SKU (form TERPISAH — jangan pernah diletakkan di dalam form utama) -->
    <div class="card-pad">
        <div class="mb-3">
            <h3 class="text-headline-sm">Terapkan Margin ke Semua SKU Otomatis</h3>
            <p class="text-body-sm text-secondary">Setelah mengubah Margin Default & menekan <strong>Simpan Semua Pengaturan</strong>, klik tombol ini utk menghitung ulang harga jual semua SKU mode otomatis yang tidak punya override (histori harga lama tetap). SKU manual_terkunci & ber-override tidak disentuh.</p>
        </div>
        <form method="POST" action="{{ route('pengaturan.terapkan-margin') }}"
            onsubmit="return confirm('Terapkan margin {{ $settings->margin_default }}% ke SEMUA SKU mode otomatis (tanpa override)? Histori harga lama tetap.');">
            @csrf
            <button class="btn btn-secondary">
                <span class="material-symbols-outlined text-[18px]">calculate</span>
                Terapkan ke Semua SKU Otomatis ({{ $settings->margin_default }}%)
            </button>
        </form>
    </div>

    <!-- Keamanan: Ubah Password Admin -->
    <div class="card-pad">
        <div class="mb-4">
            <h3 class="text-headline-sm">Keamanan — Ubah Password Admin</h3>
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
                <button class="btn btn-amber">
                    <span class="material-symbols-outlined text-[18px]">lock_reset</span> Ubah Password
                </button>
            </div>
        </form>
    </div>

    <!-- Template Export POS (PRD 4.2) -->
    <div class="card-pad">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-headline-sm">Template Export POS (konfigurabel)</h3>
                <p class="text-body-sm text-secondary">
                    Nama & urutan kolom, wajib/opsional, format, nilai default — dipakai Format A. Versi aktif: <span class="mono font-bold">{{ $settings->template_version }}</span>.
                </p>
            </div>
            <form method="POST" action="{{ route('pengaturan.template.reset') }}" class="flex items-center gap-2">
                @csrf
                <button class="btn btn-secondary btn-sm">
                    <span class="material-symbols-outlined text-[16px]">restart_alt</span> Reset ke Default v1
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
                <table class="tbl">
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
                    <tbody id="tbody-template">
                        @foreach ($mapping as $i => $m)
                            <tr>
                                <td><input type="number" name="kolom[{{ $i }}][urutan]" value="{{ $m->urutan }}" min="1" class="input mono w-16 text-center" required></td>
                                <td><input type="text" name="kolom[{{ $i }}][nama_kolom]" value="{{ $m->nama_kolom }}" class="input mono" required></td>
                                <td>
                                    <select name="kolom[{{ $i }}][wajib_opsional]" class="input">
                                        <option value="wajib" @selected($m->wajib_opsional === 'wajib')>Wajib</option>
                                        <option value="opsional" @selected($m->wajib_opsional === 'opsional')>Opsional</option>
                                    </select>
                                </td>
                                <td><input type="text" name="kolom[{{ $i }}][format]" value="{{ $m->format }}" class="input mono" placeholder="Y-m-d H:i"></td>
                                <td><input type="text" name="kolom[{{ $i }}][nilai_default]" value="{{ $m->nilai_default }}" class="input" placeholder="mis. CASH"></td>
                                <td><input type="text" name="kolom[{{ $i }}][sumber_data]" value="{{ $m->sumber_data }}" class="input" placeholder="mis. kode_barang"></td>
                                <td class="text-right"><button type="button" class="btn-icon text-danger-red" data-hapus-template><span class="material-symbols-outlined text-[18px]">delete</span></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between">
                <button type="button" class="btn btn-secondary btn-sm" id="tambah-kolom">
                    <span class="material-symbols-outlined text-[16px]">add</span> Tambah Kolom
                </button>
                <button class="btn btn-primary">
                    <span class="material-symbols-outlined text-[18px]">save</span> Simpan Template
                </button>
            </div>
        </form>
        <p class="mt-3 text-[11px] text-secondary">
            Sumber data valid: ref_transaksi, tanggal_waktu, customer_id, jenis_transaksi, tipe_pembayaran, jatuh_tempo, kode_barang, qty, harga_jual, no_sn — selain itu dipakai nilai_default.
        </p>
    </div>
@endsection

@push('scripts')
    <script>
        const tbody = document.getElementById('tbody-template');
        let idx = tbody.querySelectorAll('tr').length;

        document.getElementById('tambah-kolom').addEventListener('click', () => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input type="number" name="kolom[${idx}][urutan]" value="${idx + 1}" min="1" class="input mono w-16 text-center" required></td>
                <td><input type="text" name="kolom[${idx}][nama_kolom]" class="input mono" required></td>
                <td><select name="kolom[${idx}][wajib_opsional]" class="input"><option value="opsional">Opsional</option><option value="wajib">Wajib</option></select></td>
                <td><input type="text" name="kolom[${idx}][format]" class="input mono" placeholder="Y-m-d H:i"></td>
                <td><input type="text" name="kolom[${idx}][nilai_default]" class="input" placeholder="mis. CASH"></td>
                <td><input type="text" name="kolom[${idx}][sumber_data]" class="input" placeholder="mis. kode_barang"></td>
                <td class="text-right"><button type="button" class="btn-icon text-danger-red" data-hapus-template><span class="material-symbols-outlined text-[18px]">delete</span></button></td>
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
