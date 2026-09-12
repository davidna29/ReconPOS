{{--
    Checklist barang dengan pencarian — untuk "Item yang Dikecualikan".
    Props:
      barangs   : koleksi App\Models\Barang
      selected  : array id yang tercentang (dari old() atau default)
      name      : nama field form (default excluded_item_ids[])
      idPrefix  : prefiks id unik (bila ada >1 instans di satu halaman)
--}}
@props([
    'barangs' => collect(),
    'selected' => [],
    'name' => 'excluded_item_ids[]',
    'idPrefix' => 'exc',
    'placeholder' => 'Cari kode / nama barang…',
])

<div id="{{ $idPrefix }}-barang-checklist" class="flex h-52 flex-col overflow-hidden rounded-xl border border-outline-variant bg-white shadow-sm transition-shadow focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
    <div class="flex items-center gap-2 border-b border-outline-variant bg-surface-muted px-2.5 py-1.5">
        <span class="material-symbols-outlined text-[18px] text-secondary">search</span>
        <input id="{{ $idPrefix }}-cari" type="text" placeholder="{{ $placeholder }}"
            class="w-full border-0 bg-transparent px-1 py-1 text-body-sm placeholder:text-slate-400 focus:outline-none focus:ring-0"
            autocomplete="off">
        <span id="{{ $idPrefix }}-count" class="badge badge-blue shrink-0">0 dipilih</span>
    </div>

    <div id="{{ $idPrefix }}-list" class="flex-1 overflow-y-auto p-1.5">
        @forelse ($barangs as $b)
            <label data-item="{{ $b->kode_barang }} {{ $b->nama }}" class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-body-sm transition-colors hover:bg-surface-container">
                <input type="checkbox" name="{{ $name }}" value="{{ $b->id }}" class="shrink-0 rounded border-outline-variant text-primary focus:ring-primary"
                    @checked(in_array($b->id, (array) $selected))>
                <span class="mono text-mono-data shrink-0 text-secondary">{{ $b->kode_barang }}</span>
                <span class="truncate text-on-surface">{{ $b->nama }}</span>
            </label>
        @empty
            <p class="p-3 text-body-sm text-secondary">Tidak ada barang.</p>
        @endforelse
    </div>
    <p id="{{ $idPrefix }}-noresult" class="hidden px-3 py-2 text-body-sm text-secondary">Tidak ditemukan.</p>

    <div class="flex items-center gap-2 border-t border-outline-variant bg-surface-muted px-2.5 py-1.5">
        <button type="button" id="{{ $idPrefix }}-semua" class="btn btn-secondary btn-sm">
            <span class="material-symbols-outlined text-[15px]">select_all</span> Pilih Semua
        </button>
        <button type="button" id="{{ $idPrefix }}-kosong" class="btn btn-secondary btn-sm">
            <span class="material-symbols-outlined text-[15px]">deselect</span> Bersihkan
        </button>
        <span class="hint mb-0 ml-auto">Kosongkan = semua barang ikut di-generate.</span>
    </div>
</div>

<script>
    (function () {
        const root = document.getElementById('{{ $idPrefix }}-barang-checklist');
        if (!root) return;
        const list = root.querySelector('#{{ $idPrefix }}-list');
        const cari = root.querySelector('#{{ $idPrefix }}-cari');
        const countEl = root.querySelector('#{{ $idPrefix }}-count');
        const noResult = root.querySelector('#{{ $idPrefix }}-noresult');
        const labels = Array.from(list.querySelectorAll('label[data-item]'));
        const kosongBtn = root.querySelector('#{{ $idPrefix }}-kosong');
        const semuaBtn = root.querySelector('#{{ $idPrefix }}-semua');

        function catat() {
            const n = list.querySelectorAll('input:checked').length;
            countEl.textContent = n + ' dipilih';
        }

        function filter() {
            const q = (cari.value || '').trim().toLowerCase();
            let terlihat = 0;
            labels.forEach(l => {
                const cocok = !q || (l.dataset.item || '').toLowerCase().includes(q);
                l.hidden = !cocok;
                if (cocok) terlihat++;
            });
            noResult.classList.toggle('hidden', terlihat > 0 || labels.length === 0);
        }

        catat();
        cari.addEventListener('input', filter);
        list.addEventListener('change', catat);
        if (kosongBtn) kosongBtn.addEventListener('click', () => {
            labels.forEach(l => { const c = l.querySelector('input'); if (c) c.checked = false; });
            catat();
        });
        if (semuaBtn) semuaBtn.addEventListener('click', () => {
            labels.forEach(l => { const c = l.querySelector('input'); if (c) c.checked = true; });
            catat();
        });
    })();
</script>
