{{--
    Bulk print: tombol "Cetak Terpilih (N)" + form tersembunyi untuk POST ids ke route print-batch.
    Dipakai via @include di toolbar halaman daftar. Checkbox baris memakai:
        <input type="checkbox" class="bulk-check" data-bulk="{scope}" value="{id}">
    dan checkbox "pilih semua" memakai id "bulk-all-{scope}".
    Props:
      scope  : prefiks id unik per halaman (penjualan | hutang | lunas)
      action : URL route print-batch
--}}
@php($scope = $scope ?? 'bulk')
@php($action = $action ?? '#')

<form id="bulk-print-form-{{ $scope }}" method="POST" action="{{ $action }}" target="_blank" class="hidden">
    @csrf
    <div id="bulk-print-ids-{{ $scope }}"></div>
</form>

<button type="button" id="bulk-print-btn-{{ $scope }}"
    class="btn btn-secondary btn-sm disabled:opacity-50 disabled:cursor-not-allowed" disabled
    title="Buka halaman cetak semua dokumen terpilih (Ctrl+P untuk mencetak)">
    <span class="material-symbols-outlined text-[16px]">print</span> Cetak Terpilih (<span id="bulk-print-count-{{ $scope }}">0</span>)
</button>

@push('scripts')
    <script>
        (function () {
            const scope = '{{ $scope }}';
            const form = document.getElementById('bulk-print-form-' + scope);
            const btn = document.getElementById('bulk-print-btn-' + scope);
            const countEl = document.getElementById('bulk-print-count-' + scope);
            const idsBox = document.getElementById('bulk-print-ids-' + scope);
            const allEl = document.getElementById('bulk-all-' + scope);
            if (!form || !btn || !countEl || !idsBox) return;

            function terpilih() {
                return Array.from(document.querySelectorAll('input.bulk-check[data-bulk="' + scope + '"]:checked'));
            }
            function refresh() {
                const n = terpilih().length;
                countEl.textContent = n;
                btn.disabled = n === 0;
                // juga update tombol bulk delete jika ada di halaman yang sama
                const delCountEl = document.getElementById('bulk-delete-count-' + scope);
                const delBtn = document.querySelector('[data-bulk-delete-btn="' + scope + '"]');
                if (delCountEl) delCountEl.textContent = n;
                if (delBtn) delBtn.disabled = n === 0;
            }
            if (allEl) {
                allEl.addEventListener('change', () => {
                    document.querySelectorAll('input.bulk-check[data-bulk="' + scope + '"]')
                        .forEach(cb => { cb.checked = allEl.checked; });
                    refresh();
                });
            }
            document.querySelectorAll('input.bulk-check[data-bulk="' + scope + '"]')
                .forEach(cb => cb.addEventListener('change', refresh));

            btn.addEventListener('click', () => {
                idsBox.innerHTML = '';
                terpilih().forEach(cb => {
                    const h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = 'ids[]';
                    h.value = cb.value;
                    idsBox.appendChild(h);
                });
                if (terpilih().length > 0) form.submit();
            });
            refresh();
        })();
    </script>
@endpush
