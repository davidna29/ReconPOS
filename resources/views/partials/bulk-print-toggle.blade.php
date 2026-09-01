{{--
    Toggle "Halaman baru per nota" untuk halaman cetak batch.
    Dipakai via @include di dalam @section('content') view print-batch.
    CSS pendukung ada di layouts/print.blade.php (.print-area.pb-off mematikan page-break).
    Tanpa JS: default tetap page break per nota (kelas pb-off tidak pernah dipasang).
--}}
<div class="bulk-controls">
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
        <input type="checkbox" id="pb-toggle" checked>
        <span>Halaman baru per nota</span>
    </label>
</div>
<script>
    (function () {
        var root = document.querySelector('.print-area');
        var cb = document.getElementById('pb-toggle');
        if (!root || !cb) return;
        root.classList.toggle('pb-off', !cb.checked);
        cb.addEventListener('change', function () {
            root.classList.toggle('pb-off', !cb.checked);
        });
    })();
</script>
