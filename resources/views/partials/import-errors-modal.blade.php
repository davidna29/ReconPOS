{{--
    Modal rincian error validasi file Excel (import template / update harga / export).
    PERSISTEN: tanpa atribut data-toast sehingga TIDAK auto-dismiss (app.js hanya
    menghapus [data-toast]). Auto-terbuka saat server mem-flash session 'import_errors';
    user menutup eksplisit via tombol Tutup / klik backdrop / tombol ESC.
    Interaktif: kolom Cari (filter client-side) + Salin Semua (clipboard).
--}}
@php($ie = session('import_errors'))
@if (is_array($ie) && ! empty($ie['errors']))
    @php($ieJudul = (string) ($ie['judul'] ?? 'Validasi file gagal'))
    @php($ieRingkasan = (string) ($ie['ringkasan'] ?? 'Perbaiki baris berikut lalu upload ulang.'))
    @php($ieDaftar = array_values(array_filter(array_map('strval', (array) $ie['errors']), fn ($x) => $x !== '')))
    @if (count($ieDaftar) > 0)
        <div id="import-errors-backdrop" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="import-errors-title">
            <div class="modal-card flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden" role="document" style="max-width: 42rem;">
                <div class="flex items-start gap-3 p-5 pb-3">
                    <span class="material-symbols-outlined text-[28px] text-danger-red" aria-hidden="true">error</span>
                    <div class="min-w-0 flex-1">
                        <h3 id="import-errors-title" class="text-headline-sm text-on-surface">{{ $ieJudul }}</h3>
                        <p class="mt-0.5 text-body-sm text-secondary">{{ $ieRingkasan }}</p>
                        <p class="mt-1 text-body-sm font-semibold text-danger-red">
                            {{ count($ieDaftar) }} masalah ditemukan — tidak ada data yang diubah.
                            @if (count($ieDaftar) >= 50)
                                (ditampilkan 50 pertama — perbaiki dulu lalu upload ulang)
                            @endif
                        </p>
                    </div>
                    <button type="button" data-modal-close class="btn-icon shrink-0" title="Tutup" aria-label="Tutup">
                        <span class="material-symbols-outlined" aria-hidden="true">close</span>
                    </button>
                </div>
                <div class="px-5 pb-2">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-secondary" aria-hidden="true">search</span>
                        <input id="import-errors-search" type="text" placeholder="Cari baris / kode / kata…" aria-label="Cari dalam daftar error" class="input pl-9" autocomplete="off">
                    </div>
                </div>
                <ul id="import-errors-list" class="mx-5 mb-2 flex max-h-[46dvh] flex-col gap-1.5 overflow-y-auto rounded-lg border border-outline-variant bg-surface-muted p-3 md:max-h-[40vh]">
                    @foreach ($ieDaftar as $err)
                        <li data-import-error-item class="flex gap-2 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-body-sm text-on-surface">
                            <span class="mono mt-0.5 shrink-0 font-bold text-danger-red">{{ $loop->iteration }}.</span>
                            <span>{{ $err }}</span>
                        </li>
                    @endforeach
                    <li id="import-errors-empty" class="hidden py-6 text-center text-body-sm text-secondary">Tidak ada yang cocok dengan pencarian.</li>
                </ul>
                <div class="flex flex-wrap items-center gap-2 border-t border-outline-variant p-4">
                    <span id="import-errors-count" class="text-body-sm text-secondary" aria-live="polite">Menampilkan {{ count($ieDaftar) }} dari {{ count($ieDaftar) }} masalah</span>
                    <span class="flex-1"></span>
                    <button type="button" data-copy-import-errors class="btn btn-secondary btn-sm">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">content_copy</span> Salin Semua
                    </button>
                    <button type="button" data-modal-close class="btn btn-primary btn-sm">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">check</span> Mengerti
                    </button>
                </div>
            </div>
        </div>
    @endif
@endif
