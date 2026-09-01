@extends('layouts.app')

@section('title', $invoice ? 'Edit Transaksi Pembelian' : 'Transaksi Pembelian')

@php
    $barangs = App\Models\Barang::where('tipe_item', 'barang')->orderBy('kode_barang')->get(['id','kode_barang','nama','satuan','harga_beli_terakhir','harga_jual','stock','sn_atau_non_sn']);
    $barangJson = $barangs->map(fn ($b) => [
        'id' => $b->id, 'kode' => $b->kode_barang, 'nama' => $b->nama, 'satuan' => $b->satuan,
        'harga_beli' => $b->harga_beli_terakhir, 'harga_jual' => $b->harga_jual,
        'stock' => $b->stock, 'sn' => $b->sn_atau_non_sn,
    ])->toJson();

    // Rekonstruksi baris: dari invoice (edit) / old input (error) / kosong
    $itemsData = [];
    if ($invoice) {
        foreach ($invoice->items as $it) {
            $b = $barangs->firstWhere('id', $it->barang_id);
            $itemsData[] = ['barang_id' => $it->barang_id, 'nama' => $b?->nama, 'kode' => $b?->kode_barang,
                'sn' => $b?->sn_atau_non_sn, 'harga_beli' => $it->harga_beli, 'qty' => $it->qty,
                'diskon_item' => $it->diskon_item, 'no_sn' => $it->no_sn, 'harga_jual' => $b?->harga_jual];
        }
    } elseif (old('items')) {
        foreach (old('items') as $it) {
            $b = $barangs->firstWhere('id', $it['barang_id'] ?? 0);
            $itemsData[] = ['barang_id' => $it['barang_id'] ?? '', 'nama' => $b?->nama, 'kode' => $b?->kode_barang,
                'sn' => $b?->sn_atau_non_sn, 'harga_beli' => $it['harga_beli'] ?? $b?->harga_beli_terakhir,
                'qty' => $it['qty'] ?? 1, 'diskon_item' => $it['diskon_item'] ?? 0, 'no_sn' => $it['no_sn'] ?? '',
                'harga_jual' => $b?->harga_jual];
        }
    }

    $jenisAwal = $invoice ? $invoice->jenis : (old('jenis') ?? 'cash');
@endphp

@section('content')
    @include('pembelian.partials.nav-tabs')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">{{ $invoice ? 'Edit Invoice ' . $invoice->no_invoice : 'Transaksi Pembelian' }}</h2>
            <p class="mt-1 text-body-md text-secondary">Input pembelian baru — stok otomatis bertambah & harga jual dihitung ulang mengikuti margin saat disimpan (PRD 5.1, 5.4)</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pembelian.template.download') }}" class="btn btn-success btn-sm">
                <span class="material-symbols-outlined text-[16px]">download</span> Download Template Excel
            </a>
            <button type="button" class="btn btn-amber btn-sm" onclick="document.getElementById('import-row').classList.toggle('hidden')">
                <span class="material-symbols-outlined text-[16px]">upload</span> Upload Transaksi Excel
            </button>
        </div>
    </div>

    <div id="import-row" class="card hidden items-center gap-3 p-4">
        <form method="POST" action="{{ route('pembelian.template.import') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
            @csrf
            <input type="file" name="file" accept=".xlsx" class="input w-auto" required>
            <button class="btn btn-amber btn-sm"><span class="material-symbols-outlined text-[16px]">upload</span> Import</button>
        </form>
        <span class="text-[11px] text-secondary">Format: REF_PEMBELIAN, TANGGAL_WAKTU, SUPPLIER, JENIS_TRANSAKSI, BAYAR_DP, JATUH_TEMPO, KODE_BARANG, QTY, HARGA_BELI, DISKON_ITEM, DISKON_TOTAL.</span>
    </div>

    <form method="POST" action="{{ $invoice ? route('pembelian.update', $invoice) : route('pembelian.store') }}" id="form-pembelian">
        @csrf
        @if ($invoice)
            @method('PUT')
        @endif

        <div class="flex flex-col gap-4 lg:flex-row">
            <!-- Kiri: header + tabel item -->
            <div class="flex-1">
                <!-- Toggle Cash / Hutang -->
                <div class="segmented mb-4">
                    <button type="button" data-target="cash" class="{{ $jenisAwal === 'cash' ? 'active-cash' : '' }}">Cash</button>
                    <button type="button" data-target="hutang" class="{{ $jenisAwal === 'hutang' ? 'active-hutang' : '' }}">Hutang</button>
                    <input type="hidden" name="jenis" id="jenis" value="{{ $jenisAwal }}">
                </div>

                <div class="card-pad flex flex-col gap-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="label">No. Invoice <span class="text-secondary">(auto — bisa diedit)</span></label>
                            <div class="flex items-center gap-2">
                                <input type="text" name="no_invoice" value="{{ old('no_invoice', $invoice?->no_invoice ?? '') }}" placeholder="Otomatis (PB-YYYYMMDD-0001)" class="input mono flex-1">
                            </div>
                        </div>
                        <div>
                            <label class="label">Tanggal dan Waktu Transaksi <span class="text-danger-red">*</span></label>
                            <input type="datetime-local" name="tanggal_transaksi" required
                                value="{{ old('tanggal_transaksi', $invoice?->tanggal_transaksi?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}" class="input">
                        </div>
                        <div>
                            <label class="label">Supplier <span class="text-danger-red">*</span></label>
                            <select name="supplier_id" required class="input">
                                <option value="">-- Pilih Supplier --</option>
                                @foreach ($suppliers as $s)
                                    <option value="{{ $s->id }}" @selected((int) old('supplier_id', $invoice?->supplier_id ?? request('supplier_id')) === $s->id)>{{ $s->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="button" class="btn btn-secondary btn-sm text-primary" onclick="document.getElementById('modal-supplier').classList.remove('hidden')">
                                <span class="material-symbols-outlined text-[16px]">person_add</span> Tambah Supplier +
                            </button>
                        </div>
                    </div>

                    <!-- Pencarian barang -->
                    <div class="relative">
                        <label class="label">Barcode / Kode Barang</label>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-secondary">search</span>
                            <input type="text" id="cari-barang" autocomplete="off" placeholder="Scan / ketik KODE_BARANG lalu pilih dari daftar…"
                                class="input pl-10">
                        </div>
                        <div id="hasil-cari" class="absolute z-20 mt-1 hidden max-h-64 w-full overflow-y-auto rounded-lg border border-outline-variant bg-white shadow-lg"></div>
                    </div>

                    <!-- Tabel item -->
                    <div class="overflow-x-auto">
                        <table class="tbl" id="tabel-item">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama</th>
                                    <th class="text-right">Harga Beli</th>
                                    <th class="text-center">QTY</th>
                                    <th class="text-right">Diskon Item</th>
                                    <th class="text-right">Sub Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="tbody-item"></tbody>
                        </table>
                    </div>
                    <p class="hint" id="summary-kosong">Belum ada item. Cari barang di atas untuk menambah baris.</p>
                    <p class="hint text-danger-red" id="error-area"></p>
                </div>
            </div>

            <!-- Kanan: ringkasan biaya -->
            <div class="w-full lg:w-[340px]">
                <div class="card-pad">
                    <h3 class="mb-3 text-headline-sm">Ringkasan Biaya</h3>
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between">
                            <span class="text-body-sm text-secondary">Subtotal</span>
                            <span class="mono text-body-md font-bold" id="sum-subtotal">Rp 0</span>
                        </div>
                        <div>
                            <label class="label">Diskon / Potongan (Rp)</label>
                            <input type="number" name="diskon_total" id="diskon-total" min="0" value="{{ old('diskon_total', $invoice?->diskon_total ?? 0) }}" class="input mono text-right">
                            <span class="hint">Nominal rupiah, bukan persen (level invoice).</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-outline-variant pt-2">
                            <span class="text-body-sm font-semibold">Total Setelah Diskon</span>
                            <span class="mono text-body-md font-bold text-primary" id="sum-total">Rp 0</span>
                        </div>

                        <!-- Tab Cash -->
                        <div id="panel-cash" class="flex flex-col gap-3 {{ $jenisAwal === 'cash' ? '' : 'hidden' }}">
                            <div>
                                <label class="label text-danger-red">Bayar (Rp)</label>
                                <input type="number" name="bayar_atau_dp" id="bayar-cash" min="0" value="{{ old('bayar_atau_dp', $invoice && $invoice->jenis === 'cash' ? $invoice->bayar_atau_dp : '') }}" class="input mono text-right">
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-body-sm text-secondary">Kembali</span>
                                <span class="mono text-body-md font-bold text-lunas-green" id="kembali-cash">Rp 0</span>
                            </div>
                            <button type="submit" id="btn-simpan-cash" class="btn btn-primary w-full py-2.5">
                                <span class="material-symbols-outlined text-[18px]">shopping_cart_checkout</span> Simpan Payment
                            </button>
                            <p class="hint text-danger-red hidden" id="cash-error">Bayar masih kurang dari total — tab Cash mengasumsikan lunas penuh.</p>
                        </div>

                        <!-- Tab Hutang -->
                        <div id="panel-hutang" class="flex flex-col gap-3 {{ $jenisAwal === 'hutang' ? '' : 'hidden' }}">
                            <div>
                                <label class="label">Jatuh Tempo</label>
                                <input type="date" name="jatuh_tempo" id="jatuh-tempo" value="{{ old('jatuh_tempo', $invoice?->jatuh_tempo?->format('Y-m-d')) }}" class="input">
                                <span class="hint">Kosongkan = otomatis tanggal terakhir bulan ketiga setelah bulan transaksi.</span>
                            </div>
                            <div>
                                <label class="label text-danger-red">DP (Rp)</label>
                                <input type="number" name="bayar_atau_dp" id="dp-hutang" min="0" value="{{ old('bayar_atau_dp', $invoice && $invoice->jenis === 'hutang' ? $invoice->bayar_atau_dp : '') }}" class="input mono text-right">
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-body-sm text-secondary">Sisa Hutang</span>
                                <span class="mono text-body-md font-bold text-hutang-amber" id="sisa-hutang">Rp 0</span>
                            </div>
                            <button type="submit" class="btn btn-primary w-full py-2.5">
                                <span class="material-symbols-outlined text-[18px]">shopping_cart_checkout</span> Simpan Payment
                            </button>
                            <p class="hint">Stok tetap bertambah walau hutang belum lunas (barang sudah diterima).</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Modal Tambah Supplier ringkas -->
    <div id="modal-supplier" class="fixed inset-0 z-40 hidden items-center justify-center bg-black/40 p-4 backdrop-blur-sm">
        <div class="card w-full max-w-md p-6">
            <h3 class="mb-4 text-headline-sm">Tambah Supplier (ringkas)</h3>
            <form method="POST" action="{{ route('supplier.store') }}">
                @csrf
                <input type="hidden" name="redirect_to" value="pembelian">
                <div class="flex flex-col gap-3">
                    <div>
                        <label class="label">Nama Supplier <span class="text-danger-red">*</span></label>
                        <input type="text" name="nama" required class="input">
                    </div>
                    <div>
                        <label class="label">No. Telepon <span class="text-danger-red">*</span></label>
                        <input type="text" name="kontak" required class="input">
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-supplier').classList.add('hidden')">Batal</button>
                        <button class="btn btn-primary">Simpan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const DATA_BARANG = {!! $barangJson !!};
        const EDIT_ITEMS = {!! json_encode($itemsData) !!};
        let baris = [];

        // Normalizer nominal: tahan "50.000", "50 000", "50,000" -> 50000
        function num(v) {
            if (v === null || v === undefined || v === '') return 0;
            const n = Number(String(v).replace(/[.\s,]/g, ''));
            return Number.isFinite(n) ? n : 0;
        }

        function fmtRp(n) { return 'Rp ' + Number(n).toLocaleString('id-ID'); }

        function renderBaris() {
            const tbody = document.getElementById('tbody-item');
            tbody.innerHTML = '';
            let subtotal = 0;
            baris.forEach((it, idx) => {
                const sub = (it.harga_beli * it.qty) - it.diskon_item;
                it.subtotal = sub;
                subtotal += sub;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="text-secondary">${idx + 1}</td>
                    <td>
                        <input type="hidden" name="items[${idx}][barang_id]" value="${it.id}">
                        <div class="font-medium">${it.nama}</div>
                        <div class="mono text-[11px] text-secondary">${it.kode}${it.sn === 'SN' ? ' · SN' : ''}</div>
                    </td>
                    <td><input type="number" name="items[${idx}][harga_beli]" step="1" min="1" required value="${it.harga_beli}" data-idx="${idx}" data-field="harga_beli" class="input mono w-28 text-right"></td>
                    <td><input type="number" name="items[${idx}][qty]" step="1" min="1" required value="${it.qty}" data-idx="${idx}" data-field="qty" class="input mono w-16 text-center"></td>
                    <td><input type="number" name="items[${idx}][diskon_item]" step="1" min="0" value="${it.diskon_item}" data-idx="${idx}" data-field="diskon_item" class="input mono w-24 text-right"></td>
                    <td class="mono text-right font-bold">${fmtRp(sub)}</td>
                    <td class="text-right"><button type="button" class="btn-icon text-danger-red" data-hapus="${idx}"><span class="material-symbols-outlined text-[18px]">delete</span></button></td>
                `;
                if (it.sn === 'SN') {
                    const td = document.createElement('td');
                    td.innerHTML = `<input type="text" name="items[${idx}][no_sn]" value="${it.no_sn || ''}" placeholder="No. SN (pisah spasi/koma)" class="input mono w-44 text-[11px]">`;
                    const filter = tr.querySelector('td:nth-child(5)');
                    tr.insertBefore(td, filter);
                }
                tbody.appendChild(tr);
            });

            document.querySelectorAll('input[data-field]').forEach(inp => {
                // re-render hanya saat blur (change) — hindari kehilangan fokus tiap ketikan
                inp.addEventListener('change', () => {
                    const it = baris[inp.dataset.idx];
                    it[inp.dataset.field] = num(inp.value);
                    renderBaris();
                });
            });
            document.querySelectorAll('button[data-hapus]').forEach(btn => {
                btn.addEventListener('click', () => {
                    baris.splice(parseInt(btn.dataset.hapus), 1);
                    renderBaris();
                });
            });

            hitungRingkasan(subtotal);
        }

        function hitungRingkasan(subtotal, reRenderTabel = true) {
            const diskon = num(document.getElementById('diskon-total').value);
            const total = Math.max(0, subtotal - diskon);
            document.getElementById('sum-subtotal').textContent = fmtRp(subtotal);
            document.getElementById('sum-total').textContent = fmtRp(total);
            document.getElementById('summary-kosong').style.display = baris.length ? 'none' : 'block';

            const bayar = num(document.getElementById('bayar-cash').value);
            document.getElementById('kembali-cash').textContent = fmtRp(Math.max(0, bayar - total));
            const dp = num(document.getElementById('dp-hutang').value);
            document.getElementById('sisa-hutang').textContent = fmtRp(Math.max(0, total - dp));

            const btnCash = document.getElementById('btn-simpan-cash');
            const errCash = document.getElementById('cash-error');
            if (bayar < total) {
                btnCash.disabled = true;
                errCash.classList.remove('hidden');
            } else {
                btnCash.disabled = false;
                errCash.classList.add('hidden');
            }
        }

        ['diskon-total', 'bayar-cash', 'dp-hutang'].forEach(id => {
            const el = document.getElementById(id);
            if (el) ['input', 'change'].forEach(evt => el.addEventListener(evt, () => hitungRingkasan(sumBaris(), false)));
        });

        // Aktifkan hanya input pembayaran pada tab yg aktif (hindari 2 nama sama ikut terkirim)
        function sinkronPanelPembayaran(aktif) {
            const bayar = document.getElementById('bayar-cash');
            const dp = document.getElementById('dp-hutang');
            bayar.disabled = aktif !== 'cash';
            dp.disabled = aktif !== 'hutang';
            hitungRingkasan(sumBaris(), false);
        }

        function sumBaris() {
            return baris.reduce((acc, it) => acc + ((num(it.harga_beli) * num(it.qty)) - num(it.diskon_item)), 0);
        }

        // Toggle Cash/Hutang
        document.querySelectorAll('.segmented button[data-target]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('jenis').value = btn.dataset.target;
                document.querySelectorAll('.segmented button[data-target]').forEach(b => b.classList.remove('active-cash', 'active-hutang'));
                btn.classList.add(btn.dataset.target === 'cash' ? 'active-cash' : 'active-hutang');
                document.getElementById('panel-cash').classList.toggle('hidden', btn.dataset.target !== 'cash');
                document.getElementById('panel-hutang').classList.toggle('hidden', btn.dataset.target !== 'hutang');
                sinkronPanelPembayaran(btn.dataset.target);
            });
        });

        // Pencarian barang
        const inputCari = document.getElementById('cari-barang');
        const hasil = document.getElementById('hasil-cari');
        inputCari.addEventListener('input', () => {
            const q = inputCari.value.trim().toLowerCase();
            hasil.innerHTML = '';
            if (!q) { hasil.classList.add('hidden'); return; }
            const cocok = DATA_BARANG.filter(b => b.kode.toLowerCase().includes(q) || b.nama.toLowerCase().includes(q)).slice(0, 12);
            if (!cocok.length) {
                hasil.innerHTML = '<div class="p-3 text-body-sm text-secondary">Tidak ditemukan.</div>';
                hasil.classList.remove('hidden');
                return;
            }
            cocok.forEach(b => {
                const div = document.createElement('div');
                div.className = 'cursor-pointer px-3 py-2 hover:bg-surface-container-high flex items-center justify-between';
                div.innerHTML = `<div><div class="mono text-mono-data">${b.kode}</div><div class="text-body-sm">${b.nama}</div></div>
                    <div class="text-right text-[11px] text-secondary">stok ${b.stock} · ${fmtRp(b.harga_jual)}</div>`;
                div.addEventListener('click', () => {
                    if (baris.some(x => x.id === b.id)) return;
                    baris.push({ id: b.id, kode: b.kode, nama: b.nama, harga_beli: b.harga_beli || 0, qty: 1, diskon_item: 0, no_sn: '', sn: b.sn });
                    renderBaris();
                    inputCari.value = '';
                    hasil.classList.add('hidden');
                });
                hasil.appendChild(div);
            });
            hasil.classList.remove('hidden');
        });
        inputCari.addEventListener('blur', () => setTimeout(() => hasil.classList.add('hidden'), 150));

        // Inisialisasi & sinkronisasi awal (jenis dari server: cash / hutang)
        sinkronPanelPembayaran(document.getElementById('jenis').value);
        EDIT_ITEMS.forEach(it => {
            baris.push({ id: it.barang_id, kode: it.kode, nama: it.nama, harga_beli: it.harga_beli, qty: it.qty, diskon_item: it.diskon_item, no_sn: it.no_sn || '', sn: it.sn });
        });
        renderBaris();

        // Pakai helper yg sama utk re-render dari input event
        window.sumBaris = sumBaris;
    </script>
@endpush
