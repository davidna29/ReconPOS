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
                <input type="number" name="nota_min" min="1" max="30" value="{{ old('nota_min', $params['nota_min']) }}" class="input">
            </div>
            <div>
                <label class="label">Jumlah Nota / Hari (max)</label>
                <input type="number" name="nota_max" min="1" max="30" value="{{ old('nota_max', $params['nota_max']) }}" class="input">
            </div>
            <div>
                <label class="label">Toleransi (%)</label>
                <input type="number" name="toleransi_persen" min="0" max="20" value="{{ old('toleransi_persen', $params['toleransi_persen']) }}" class="input">
                <span class="hint">Default ±{{ $params['toleransi_persen'] }}% dari target.</span>
            </div>

            <div>
                <label class="label">Variasi Harga MIN (%)</label>
                <input type="number" name="variasi_min" min="-50" max="0" value="{{ old('variasi_min', $params['variasi_min']) }}" class="input">
            </div>
            <div>
                <label class="label">Variasi Harga MAX (%)</label>
                <input type="number" name="variasi_max" min="-50" max="50" value="{{ old('variasi_max', $params['variasi_max']) }}" class="input">
                <span class="hint">Simulasi diskon/nego (−10% s.d. 0%). Tidak pernah di bawah harga beli.</span>
            </div>
            <div>
                <label class="label">Item yang Dikecualikan</label>
                <select name="excluded_item_ids[]" multiple class="input h-24">
                    @foreach ($barangs as $b)
                        <option value="{{ $b->id }}" @selected(in_array($b->id, old('excluded_item_ids', [])))>{{ $b->kode_barang }} — {{ $b->nama }}</option>
                    @endforeach
                </select>
                <span class="hint">Ctrl/Cmd + klik untuk pilih lebih dari satu.</span>
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
                                @else
                                    <x-badge color="green">Tersimpan</x-badge>
                                @endif
                            </td>
                            <td>{{ $b->created_at->format('d/m/Y H:i') }}</td>
                            <td class="text-right">
                                @if ($b->status === 'draft')
                                    <a href="{{ route('generate.review', $b) }}" class="btn btn-primary btn-sm">Review <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
                                @else
                                    <span class="badge badge-blue">rekonstruksi</span>
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
            document.getElementById('kapasitas-detail').textContent = 'Opsi 1: generate semaksimal stok (' + 'Rp ' + Number(warning.kapasitas).toLocaleString('id-ID') + '). Opsi 2: batalkan, sesuaikan target atau tambah stok.';
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
