@extends('layouts.app')

@section('title', 'Generate Transaksi')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-headline-lg text-on-surface">Generate Transaksi Otomatis</h2>
            <p class="mt-1 text-body-md text-secondary">Rekonstruksi penjualan dari target omset & stok masuk — draft direview dulu sebelum disimpan (PRD 6, 7)</p>
        </div>
        <a href="{{ route('generate.riwayat') }}" class="btn btn-secondary btn-sm">
            <span class="material-symbols-outlined text-[16px]">history</span> Riwayat Batch
        </a>
    </div>

    <div id="kapasitas-warning" class="card hidden border-l-4 border-l-danger-red p-5">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-danger-red">warning</span>
            <div class="flex-1">
                <h3 class="text-headline-sm text-danger-red">Kapasitas stok tidak mencukupi target</h3>
                <p class="mt-1 text-body-md" id="kapasitas-pesan"></p>
                <p class="mt-1 text-body-sm text-secondary" id="kapasitas-detail"></p>
                <div class="mt-4 flex gap-2">
                    <form method="POST" action="{{ route('generate.run') }}" id="form-force">
                        @csrf
                        <input type="hidden" name="force" value="1">
                        <div id="form-force-fields"></div>
                        <button class="btn btn-danger">
                            <span class="material-symbols-outlined text-[18px]">auto_awesome</span> Generate Semaksimal Stok
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('kapasitas-warning').classList.add('hidden')">Batalkan</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card p-6">
        <form method="POST" action="{{ route('generate.run') }}" class="grid grid-cols-1 gap-4 md:grid-cols-3">
            @csrf

            <div>
                <label class="label">Periode Mulai <span class="text-danger-red">*</span></label>
                <input type="date" name="periode_mulai" required value="{{ old('periode_mulai', now()->startOfMonth()->format('Y-m-d')) }}" class="input">
            </div>
            <div>
                <label class="label">Periode Selesai <span class="text-danger-red">*</span></label>
                <input type="date" name="periode_selesai" required value="{{ old('periode_selesai', now()->endOfMonth()->format('Y-m-d')) }}" class="input">
            </div>
            <div>
                <label class="label">Target Omset (Rp) <span class="text-danger-red">*</span></label>
                <input type="number" name="target_omset" min="1" required value="{{ old('target_omset') }}" placeholder="mis. 1000000" class="input mono text-right">
                <span class="hint">Sisa target = target − transaksi tersimpan pada periode yang sama (manual + rekonstruksi).</span>
            </div>

            <div>
                <label class="label">Jumlah Nota / Hari (min)</label>
                <input type="number" name="nota_min" min="1" max="100" value="{{ old('nota_min', $params['nota_min']) }}" class="input">
            </div>
            <div>
                <label class="label">Jumlah Nota / Hari (max)</label>
                <input type="number" name="nota_max" min="1" max="100" value="{{ old('nota_max', $params['nota_max']) }}" class="input">
            </div>
            <div>
                <label class="label">Toleransi (%)</label>
                <input type="number" name="toleransi_persen" min="0" max="20" value="{{ old('toleransi_persen', $params['toleransi_persen']) }}" class="input">
                <span class="hint">Default ±{{ $params['toleransi_persen'] }}% dari target.</span>
            </div>

            <div>
                <label class="label">Diskon Maksimum yg Diizinkan (Variasi Min, %)</label>
                <input type="number" name="variasi_min" min="-50" max="0" value="{{ old('variasi_min', $params['variasi_min']) }}" class="input">
                <span class="hint">Batas bawah rentang harga per baris. Nilai negatif = diskon terbesar yang boleh terjadi (mis. −10 → harga termurah 90% dari harga master). Default −10.</span>
            </div>
            <div>
                <label class="label">Diskon Minim / Harga Pas (Variasi Max, %)</label>
                <input type="number" name="variasi_max" min="-50" max="50" value="{{ old('variasi_max', $params['variasi_max']) }}" class="input">
                <span class="hint">Batas atas rentang. 0% = harga master persis; nilai positif = boleh lebih mahal dari master. Default 0.</span>
            </div>
            <div>
                <label class="label">Maks Qty / Item / Nota</label>
                <input type="number" name="maks_qty_item_per_nota" min="1" max="20" value="{{ old('maks_qty_item_per_nota', $params['maks_qty_item_per_nota'] ?? 3) }}" class="input">
                <span class="hint">Batas unit 1 jenis barang dalam 1 nota (default 3) — utk ban setel 4 supaya 1 nota = 1-4 ban.</span>
            </div>
            <div>
                <label class="label">Maks Jenis Barang (SKU) / Nota</label>
                <input type="number" name="maks_sku_per_nota" min="1" max="5" value="{{ old('maks_sku_per_nota', $params['maks_sku_per_nota'] ?? 1) }}" class="input">
                <span class="hint">1 = 1 nota hanya 1 jenis barang (mis. 4 ban sama); 2-5 = campuran (default 1).</span>
            </div>
            <div class="md:col-span-3">
                <p class="mb-1 rounded-lg border border-outline-variant bg-surface-muted px-3 py-2 text-body-sm text-secondary">
                    <span class="material-symbols-outlined text-[16px] align-middle text-primary">info</span>
                    Contoh: variasi −10% s.d. 0% → tiap baris transaksi mendapat harga acak antara 90%–100% dari harga master,
                    dan <strong>tidak pernah di bawah harga beli</strong> (tidak ada transaksi rugi). Harga master di Data Barang tidak berubah.
                </p>
            </div>
            <div class="md:col-span-3">
                <label class="label">Item yang Dikecualikan <span class="text-secondary">(opsional — kosongkan = semua barang ikut)</span></label>
                <x-barang-checklist :barangs="$barangs"
                    :selected="old('excluded_item_ids', $params['excluded_item_ids'] ?? [])"
                    idPrefix="gen" />
                <span class="hint">Barang yang dicentang tidak akan muncul di draft, tidak ikut kapasitas, dan tidak ikut perhitungan target. Bila semua barang dikecualikan, generate ditolak.</span>
            </div>

            <div class="flex items-end md:col-span-3">
                <button class="btn btn-primary py-2.5">
                    <span class="material-symbols-outlined text-[18px]">auto_awesome</span> Generate Draft
                </button>
                <span class="ml-3 text-[11px] text-secondary">
                    Jam operasional {{ $settings->jam_buka }}–{{ $settings->jam_tutup }} & hari libur dari Pengaturan dipatuhi otomatis.
                </span>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="border-b border-outline-variant bg-slate-50 px-4 py-3">
            <h3 class="text-headline-sm">Batch Terbaru</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Periode</th>
                        <th class="text-right">Target</th>
                        <th class="text-right">Total Generate</th>
                        <th class="text-right">Selisih</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($batches as $b)
                        <tr>
                            <td class="mono text-secondary">#{{ $b->id }}</td>
                            <td>{{ $b->periode_mulai->format('d/m/Y') }} – {{ $b->periode_selesai->format('d/m/Y') }}</td>
                            <td class="mono text-right">{{ uang($b->target_omset) }}</td>
                            <td class="mono text-right">{{ $b->total_generate !== null ? uang($b->total_generate) : '-' }}</td>
                            <td class="mono text-right {{ ($b->selisih ?? 0) > 0 ? 'text-danger-red' : 'text-lunas-green' }}">
                                {{ $b->selisih !== null ? ($b->selisih > 0 ? '+' . uang($b->selisih) : uang($b->selisih)) : '-' }}
                            </td>
                            <td>
                                @if ($b->status === 'draft')
                                    <x-badge color="amber">Draft</x-badge>
                                @elseif ($b->status === 'dibatalkan')
                                    <x-badge color="slate">Dibatalkan</x-badge>
                                @else
                                    <x-badge color="green">Tersimpan</x-badge>
                                @endif
                            </td>
                            <td>{{ $b->created_at->format('d/m/Y H:i') }}</td>
                            <td class="text-right">
                                @if ($b->status === 'draft')
                                    <a href="{{ route('generate.review', $b) }}" class="btn btn-primary btn-sm">Review <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
                                @elseif ($b->status === 'tersimpan')
                                    <form method="POST" action="{{ route('generate.batalkan', $b) }}"
                                        data-confirm="Undo batch #{{ $b->id }}? Stok dikembalikan & NO_SN dilepas; baris menjadi 'dibatalkan' (audit).">
                                        @csrf
                                        <button class="btn btn-danger btn-sm"><span class="material-symbols-outlined text-[16px]">undo</span> Undo</button>
                                    </form>
                                @else
                                    <span class="badge badge-slate">dibatalkan</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-secondary">Belum ada batch generate.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // Restore warning kapasitas dari flash session
        const warning = @json(session('kapasitas_warning'));
        if (warning) {
            document.getElementById('kapasitas-pesan').textContent = warning.pesan;
            let detail = 'Opsi 1: generate semaksimal stok (' + 'Rp ' + Number(warning.kapasitas).toLocaleString('id-ID') + '). Opsi 2: batalkan, sesuaikan target atau tambah stok.';
            if (warning.masuk_terakhir > 0) {
                detail += ' Masuk hari terakhir periode: Rp ' + Number(warning.masuk_terakhir).toLocaleString('id-ID') + ' (tak terjualable H+1).';
            }
            document.getElementById('kapasitas-detail').textContent = detail;
            const fields = document.getElementById('form-force-fields');
            for (const [k, v] of Object.entries(warning.input || {})) {
                if (Array.isArray(v)) continue; // array diisi ulang manual oleh user
                fields.insertAdjacentHTML('beforeend', '<input type="hidden" name="' + k + '" value="' + v + '">');
            }
            document.getElementById('kapasitas-warning').classList.remove('hidden');
            document.getElementById('kapasitas-warning').scrollIntoView({ behavior: 'smooth' });
        }
    </script>
@endpush
