@extends('layouts.app')

@section('title', 'Bayar Bulk Hutang')

@section('content')
    @include('pembelian.partials.nav-tabs')
    <div>
        <h2 class="text-headline-lg text-on-surface">Bayar Bulk Hutang</h2>
        <p class="mt-1 text-body-md text-secondary">Bayar banyak invoice satu supplier sekaligus — dialokasikan otomatis dari yang <strong>terlama</strong>, pratinjau dulu sebelum tercatat.</p>
    </div>

    <div class="card p-4">
        <form method="POST" action="{{ route('pembelian.bayar-bulk.preview') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="label">Supplier <span class="text-danger-red">*</span></label>
                <select name="supplier_id" required class="input w-56">
                    <option value="">-- Pilih Supplier --</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}" @selected((string) old('supplier_id', request('supplier')) === (string) $s->id)>{{ $s->nama }}</option>
                    @endforeach
                </select>
                @error('supplier_id')<span class="hint text-danger-red">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="label text-danger-red">Nominal Bayar (Rp) <span class="text-danger-red">*</span></label>
                <input type="number" name="nominal" min="1" step="1" required value="{{ old('nominal') }}" class="input mono w-44 text-right @error('nominal') input-error @enderror" placeholder="1500000">
                @error('nominal')<span class="hint text-danger-red">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="label">Tanggal Bayar <span class="text-danger-red">*</span></label>
                <input type="date" name="tanggal_bayar" value="{{ old('tanggal_bayar', now()->format('Y-m-d')) }}" required class="input">
            </div>
            <div class="min-w-52 flex-1">
                <label class="label">Link Bukti Transfer (opsional)</label>
                <input type="url" name="bukti_url" maxlength="1000" value="{{ old('bukti_url') }}" placeholder="https://drive.google.com/..." class="input @error('bukti_url') input-error @enderror">
                @error('bukti_url')<span class="hint text-danger-red">{{ $message }}</span>@enderror
            </div>
            <div class="min-w-52 flex-1">
                <label class="label">Catatan (opsional)</label>
                <input type="text" name="catatan" maxlength="255" value="{{ old('catatan') }}" placeholder="mis. transfer BCA" class="input">
            </div>
            <button class="btn btn-success"><span class="material-symbols-outlined text-[18px]">preview</span> Pratinjau Alokasi</button>
        </form>
        <p class="hint mt-2">Mencakup <strong>semua</strong> invoice belum lunas supplier tsb — dialokasikan otomatis dari yang <strong>terlama</strong>. Kelebihan di atas total = kembalian (tidak dicatat).</p>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-outline-variant bg-slate-50 px-4 py-3">
            <h3 class="text-headline-sm">Riwayat Bayar Bulk</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal Bayar</th>
                        <th>Supplier</th>
                        <th class="text-right">Dialokasikan</th>
                        <th class="text-right">Kembalian</th>
                        <th class="text-center">Invoice</th>
                        <th>Bukti</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $i => $h)
                        <tr>
                            <td class="text-secondary">{{ $riwayat->firstItem() + $i }}</td>
                            <td>{{ $h->tanggal_bayar->format('d/m/Y') }}</td>
                            <td class="font-medium">{{ $h->supplier?->nama }}</td>
                            <td class="mono text-right font-bold text-lunas-green">{{ rupiah($h->dialokasi) }}</td>
                            <td class="mono text-right">{{ (int) $h->kembalian > 0 ? rupiah($h->kembalian) : '-' }}</td>
                            <td class="mono text-center">{{ (int) $h->baris_aktif }} invoice</td>
                            <td>
                                @if ($h->bukti_url)
                                    <a href="{{ $h->bukti_url }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" title="{{ $h->bukti_url }}">
                                        <span class="material-symbols-outlined text-[16px]">open_in_new</span> Bukti
                                    </a>
                                @else
                                    <span class="text-secondary">-</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('pembelian.bayar-bulk.cancel', $h->uuid) }}"
                                    onsubmit="return confirm('Batalkan bulk {{ uang($h->dialokasi) }} ke {{ $h->supplier?->nama }}? Semua pembayaran satu paket ini dikembalikan & riwayatnya hilang.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Batalkan bulk ini">
                                        <span class="material-symbols-outlined text-[16px]">restart_alt</span> Batalkan
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-secondary">Belum ada pembayaran bulk.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-outline-variant p-3">
            {{ $riwayat->links() }}
        </div>
    </div>
@endsection
