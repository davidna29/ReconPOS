// Aplikasi GenTOt — sidebar mobile (drawer) & desktop (minimize), AJAX delete, & perilaku global.

document.addEventListener('DOMContentLoaded', () => {
    // ============================
    // SIDEBAR (mobile + desktop)
    // ============================
    const btnToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('app-sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const mqDesktop = window.matchMedia('(min-width: 768px)');

    if (localStorage.getItem('sidebar-minimized') === '1') {
        document.body.classList.add('sidebar-minimized');
    }

    if (btnToggle && sidebar) {
        btnToggle.addEventListener('click', () => {
            if (mqDesktop.matches) {
                const minimized = document.body.classList.toggle('sidebar-minimized');
                localStorage.setItem('sidebar-minimized', minimized ? '1' : '0');
            } else {
                sidebar.classList.toggle('-translate-x-full');
                if (overlay) overlay.classList.toggle('hidden');
            }
        });
    }
    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        });
    }

    // ============================
    // AJAX SINGLE DELETE
    // ============================
    function removeRow(tr) {
        if (!tr) return;
        const next = tr.nextElementSibling;
        if (next && (next.classList.contains('bg-slate-50') || next.id?.startsWith('det-') || next.id?.startsWith('bayar-'))) {
            next.remove();
        }
        tr.style.transition = 'opacity 0.2s';
        tr.style.opacity = '0';
        setTimeout(() => tr.remove(), 200);
    }

    function showToast(msg) {
        const t = document.createElement('div');
        t.className = 'fixed bottom-4 right-4 z-50 rounded-lg bg-lunas-green px-4 py-2 text-sm font-medium text-white shadow-lg';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => { t.style.transition = 'opacity 0.3s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
    }

    document.querySelectorAll('form[data-delete-ajax]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            if (!window.confirm(form.dataset.confirm)) return;

            const tr = form.closest('tr');
            const csrf = form.querySelector('input[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content;

            fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new URLSearchParams({ _method: 'DELETE', _token: csrf }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    removeRow(tr);
                    showToast(data.message || 'Berhasil dihapus.');
                } else {
                    alert(data.error || 'Gagal menghapus.');
                }
            })
            .catch(() => alert('Gagal menghapus. Periksa koneksi.'));
        });
    });

    // ============================
    // AJAX BULK DELETE
    // ============================
    document.querySelectorAll('[data-bulk-delete-btn]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const scope = btn.dataset.bulkDeleteBtn;
            const url = btn.dataset.bulkDeleteUrl;
            const checks = document.querySelectorAll('input.bulk-check[data-bulk="' + scope + '"]:checked');
            const ids = Array.from(checks).map(cb => cb.value);
            if (!ids.length) return;

            if (!window.confirm('Hapus ' + ids.length + ' item terpilih? Tindakan ini tidak bisa dibatalkan.')) return;

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const body = new URLSearchParams({ _token: csrf });
            ids.forEach(id => body.append('ids[]', id));

            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: body,
            })
            .then(r => r.json())
            .then(data => {
                if (data.deleted?.length) {
                    data.deleted.forEach(id => {
                        const tr = document.querySelector('tr[data-id="' + id + '"]');
                        removeRow(tr);
                        const cb = document.querySelector('input.bulk-check[data-bulk="' + scope + '"][value="' + id + '"]');
                        if (cb) cb.closest('tr') || cb.remove();
                    });
                }
                showToast(data.message || 'Selesai.');
                // reset count badge
                const countEl = document.getElementById('bulk-delete-count-' + scope);
                if (countEl) countEl.textContent = '0';
                const allEl = document.getElementById('bulk-all-' + scope);
                if (allEl) allEl.checked = false;
                // cek tabel kosong
                checks.forEach(cb => cb.remove());
            })
            .catch(() => alert('Gagal menghapus. Periksa koneksi.'));
        });
    });
});