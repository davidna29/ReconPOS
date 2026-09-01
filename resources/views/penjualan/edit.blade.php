@extends('layouts.app')

@section('title', 'Edit Nota ' . $trx->ref_transaksi)

@php
    $barangs = \App\Models\Barang::where('tipe_item', 'barang')->orderBy('kode_barang')->get(['id','kode_barang','nama','satuan','harga_jual','stock','sn_atau_non_sn']);
    $barangJson = $barangs->map(fn ($b) => [
        'id' => $b->id, 'kode' => $b->kode_barang, 'nama' => $b->nama, 'satuan' => $b->satuan,
        'harga_jual' => $b->harga_jual, 'stock' => $b->stock, 'sn' => $b->sn_atau_non_sn,
    ])->toJson();

    $sumberItems = old('items') ?? $trx->items->map(fn ($it) => [
        'barang_id' => $it->barang_id, 'qty' => $it->qty, 'harga_jual' => $it->harga_jual, 'no_sn' => $it->no_sn,
    ])->all();
    $itemsData = [];
    foreach ($sumberItems as $it) {
        $b = $barangs->firstWhere('id', $it['barang_id'] ?? 0);
        $itemsData[] = ['id' => $it['barang_id'] ?? '', 'kode' => $b?->kode_barang, 'nama' => $b?->nama,
            'harga_jual' => (int) ($it['harga_jual'] ?? $b?->harga_jual ?? 0), 'qty' => (int) ($it['qty'] ?? 1),
            'sn' => $b?->sn_atau_non_sn, 'no_sn' => $it['no_sn'] ?? null];
    }
@endphp

@section('content')
    <div class="max-w-4xl">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-headline-lg text-on-surface">Edit Nota <span class="mono text-primary">{{ $trx->ref_transaksi }}</span></h2>
                <p class="mt-1 text-body-md text-secondary">
                    @if ($trx->sumber === 'rekonstruksi')
                        <span class="badge badge-blue">Rekonstruksi</span> — mengedit nota ini akan <strong>mengunci batch</strong>: undo batch tidak bisa dijalankan lagi.
                    @else
                        <span class="badge badge-green">Manual</span> — Customer Umum (CUSTOMER_ID 0).
                    @endif
                </p>
            </div>
            <a href="{{ route('penjualan.index') }}" class="btn btn-secondary btn-sm">Kembali</a>
        </div>

        @if ($trx->sumber === 'rekonstruksi')
            <div class="mt-4 flex items-start gap-3 rounded-lg border border-hutang-amber/40 bg-amber-50 p-4">
                <span class="material-symbols-outlined text-hutang-amber">lock</span>
                <p class="text-body-sm text-amber-800">
                    Setelah disimpan, nota ini ditandai <strong>diedit</strong> dan batch rekonstruksinya <strong>terkunci</strong>
                    (tidak bisa di-undo). Stok & NO_SN akan disesuaikan sesuai isi baru.
                </p>
            </div>
        @endif

        <form method="POST" action="{{ route('penjualan.update', $trx) }}" class="mt-4 flex flex-col gap-4">
            @csrf
            @method('PUT')

            <div class="card-pad flex flex-col gap-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="label">Tanggal dan Waktu <span class="text-danger-red">*</span></label>
                        <input type="datetime-local" name="tanggal_waktu" required
                            value="{{ old('tanggal_waktu', $trx->tanggal_waktu->format('Y-m-d\TH:i')) }}" class="input">
                    </div>
                </div>

                <div class="relative">
                    <label class="label">Cari Barang (Kode / Nama)</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-secondary">search</span>
                        <input type="text" id="cari-jual" autocomplete="off" placeholder="Scan / ketik untuk menambah item…" class="input pl-10">
                    </div>
                    <div id="hasil-jual" class="absolute z-20 mt-1 hidden max-h-64 w-full overflow-y-auto rounded-lg border border-outline-variant bg-white shadow-lg"></div>
                </div>

                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead>
                            <tr><th>No</th><th>Nama</th><th class="text-right">Harga Jual</th><th class="text-center">QTY</th><th class="text-right">Subtotal</th><th></th></tr>
                        </thead>
                        <tbody id="tbody-jual"></tbody>
                    </table>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="text-body-md">
                    Total: <span class="mono text-headline-md font-bold text-primary" id="total-jual">Rp 0</span>
                </div>
                <button class="btn btn-primary py-2.5">
                    <span class="material-symbols-outlined text-[18px]">save</span> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        const DATA = {!! $barangJson !!};
        const OLD = {!! json_encode($itemsData) !!};
        const rows = [];

        function fmtRp(n) { return 'Rp ' + Number(n).toLocaleString('id-ID'); }

        function renderJual() {
            const tbody = document.getElementById('tbody-jual');
            tbody.innerHTML = '';
            let total = 0;
            rows.forEach((it, idx) => {
                it.sub = it.qty * it.harga_jual;
                total += it.sub;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="text-secondary">${idx + 1}</td>
                    <td>
                        <input type="hidden" name="items[${idx}][barang_id]" value="${it.id}">
                        <div class="font-medium">${it.nama}</div>
                        <div class="mono text-[11px] text-secondary">${it.kode}${it.sn === 'SN' ? ' · SN ' + (it.no_sn || '(SN tetap)') : ''}</div>
                    </td>
                    <td><input type="number" name="items[${idx}][harga_jual]" min="1" required value="${it.harga_jual}" data-idx="${idx}" class="input mono w-32 text-right harga-jual"></td>
                    <td><input type="number" name="items[${idx}][qty]" min="1" required value="${it.qty}" data-idx="${idx}" class="input mono w-16 text-center qty" ${it.sn === 'SN' ? 'readonly' : ''}></td>
                    <td class="mono text-right font-bold">${fmtRp(it.sub)}</td>
                    <td class="text-right"><button type="button" class="btn-icon text-danger-red" data-hapus="${idx}"><span class="material-symbols-outlined text-[18px]">delete</span></button></td>
                `;
                tbody.appendChild(tr);
            });
            document.getElementById('total-jual').textContent = fmtRp(total);

            tbody.querySelectorAll('input').forEach(inp => {
                inp.addEventListener('change', () => {
                    const it = rows[inp.dataset.idx];
                    if (inp.classList.contains('qty')) it.qty = parseInt(inp.value || 1);
                    if (inp.classList.contains('harga-jual')) it.harga_jual = parseInt(inp.value || 0);
                    renderJual();
                });
            });
            tbody.querySelectorAll('button[data-hapus]').forEach(btn => {
                btn.addEventListener('click', () => { rows.splice(parseInt(btn.dataset.hapus), 1); renderJual(); });
            });
        }

        const inputCari = document.getElementById('cari-jual');
        const hasil = document.getElementById('hasil-jual');
        inputCari.addEventListener('input', () => {
            const q = inputCari.value.trim().toLowerCase();
            hasil.innerHTML = '';
            if (!q) { hasil.classList.add('hidden'); return; }
            const cocok = DATA.filter(b => b.kode.toLowerCase().includes(q) || b.nama.toLowerCase().includes(q)).slice(0, 12);
            if (!cocok.length) { hasil.innerHTML = '<div class="p-3 text-body-sm text-secondary">Tidak ditemukan.</div>'; hasil.classList.remove('hidden'); return; }
            cocok.forEach(b => {
                const div = document.createElement('div');
                div.className = 'cursor-pointer px-3 py-2 flex items-center justify-between hover:bg-surface-container-high';
                div.innerHTML = `<div><div class="mono text-mono-data">${b.kode}</div><div class="text-body-sm">${b.nama}</div></div>
                    <div class="text-right text-[11px] text-secondary">stok ${b.stock} · ${fmtRp(b.harga_jual)}</div>`;
                div.addEventListener('click', () => {
                    if (rows.some(x => x.id === b.id)) return;
                    rows.push({ id: b.id, kode: b.kode, nama: b.nama, harga_jual: b.harga_jual || 0, qty: b.sn === 'SN' ? 1 : 1, sn: b.sn, no_sn: b.sn === 'SN' ? null : null });
                    renderJual();
                    inputCari.value = '';
                    hasil.classList.add('hidden');
                });
                hasil.appendChild(div);
            });
            hasil.classList.remove('hidden');
        });
        inputCari.addEventListener('blur', () => setTimeout(() => hasil.classList.add('hidden'), 150));

        OLD.forEach(it => rows.push({ id: it.id, kode: it.kode, nama: it.nama, harga_jual: it.harga_jual, qty: it.qty, sn: it.sn, no_sn: it.no_sn || null }));
        renderJual();
    </script>
@endpush
