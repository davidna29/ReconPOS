// ReconPOS — sidebar mobile (drawer) & desktop (minimize), AJAX delete, & perilaku global.

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

            // kirim(false) = hapus biasa; kirim(true) = force (lanjut walau stok minus).
            const kirim = (force) => {
                const body = new URLSearchParams({ _method: 'DELETE', _token: csrf });
                if (force) body.append('force', '1');
                return fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: body,
                });
            };

            kirim(false)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    removeRow(tr);
                    showToast(data.message || 'Berhasil dihapus.');
                    return;
                }
                if (data.stok_warning) {
                    // Hapus invoice pembelian akan membuat stok minus — beri 2 opsi
                    // (Lanjutkan = force, seperti modal non-JS resolve-stok-warning).
                    const lanjut = window.confirm(
                        (data.error || 'Menghapus akan membuat stok minus.') +
                        '\n\nLanjutkan hapus dengan menandai anomali stok?'
                    );
                    if (!lanjut) return; // Batalkan — baris tetap utuh.
                    return kirim(true)
                        .then(r => r.json())
                        .then(d2 => {
                            if (d2.success) {
                                removeRow(tr);
                                showToast(d2.message || 'Berhasil dihapus (stok minus).');
                            } else {
                                alert(d2.error || 'Gagal menghapus.');
                            }
                        })
                        .catch(() => alert('Gagal menghapus. Periksa koneksi.'));
                }
                alert(data.error || 'Gagal menghapus.');
            })
            .catch(() => alert('Gagal menghapus. Periksa koneksi.'));
        });
    });

    // ============================
    // DASHBOARD CHARTS (Batch G — Chart.js code-split, hanya bila ada canvas)
    // Data dari window.DASHBOARD_CHARTS (inline script dashboard/index).
    // ============================
    const chartData = window.DASHBOARD_CHARTS;
    if (chartData && (document.getElementById('chart-trend') || document.getElementById('chart-aging') || document.getElementById('chart-kritik'))) {
        const cssVar = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
        const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
        let charts = [];

        async function initDashboardCharts() {
            const { Chart, LineController, BarController, CategoryScale, LinearScale, PointElement, LineElement, BarElement, Filler, Tooltip, Legend } = await import('chart.js');
            Chart.register(LineController, BarController, CategoryScale, LinearScale, PointElement, LineElement, BarElement, Filler, Tooltip, Legend);
            charts.forEach((c) => c.destroy());
            charts = [];

            Chart.defaults.font.family = "'Inter', sans-serif";
            Chart.defaults.color = cssVar('--color-secondary') || '#666';
            const grid = cssVar('--color-outline-variant') || '#e5e7eb';
            const primary = cssVar('--color-primary') || '#6d28d9';
            const danger = cssVar('--color-danger-red') || '#dc2626';
            const amber = cssVar('--color-hutang-amber') || '#d97706';
            const slate = cssVar('--color-secondary') || '#666';
            const rp = (v) => 'Rp ' + Number(v).toLocaleString('id-ID');
            const base = {
                animation: reducedMotion ? false : { duration: 600 },
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
            };

            const trendEl = document.getElementById('chart-trend');
            if (trendEl) {
                const ctx = trendEl.getContext('2d');
                const grad = ctx.createLinearGradient(0, 0, 0, 256);
                grad.addColorStop(0, primary + '55');
                grad.addColorStop(1, primary + '00');
                const labels = chartData.trend.labels;
                charts.push(new Chart(trendEl, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [{
                            data: chartData.trend.values,
                            borderColor: primary,
                            backgroundColor: grad,
                            fill: true,
                            tension: 0.35,
                            pointRadius: labels.length > 60 ? 0 : 2,
                            pointBackgroundColor: primary,
                        }],
                    },
                    options: { ...base,
                        scales: {
                            x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                            y: { grid: { color: grid }, ticks: { maxTicksLimit: 6, callback: (v) => 'Rp ' + Intl.NumberFormat('id-ID', { notation: 'compact' }).format(v) } },
                        },
                        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => rp(c.parsed.y) } } },
                    },
                }));
            }

            const agingEl = document.getElementById('chart-aging');
            if (agingEl) {
                charts.push(new Chart(agingEl, {
                    type: 'bar',
                    data: {
                        labels: chartData.aging.labels,
                        datasets: [{
                            data: chartData.aging.values,
                            backgroundColor: [danger, amber, slate],
                            borderRadius: 6,
                        }],
                    },
                    options: { ...base,
                        indexAxis: 'y',
                        scales: {
                            x: { grid: { color: grid }, ticks: { precision: 0 } },
                            y: { grid: { display: false } },
                        },
                        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => c.parsed.x + ' invoice' } } },
                    },
                }));
            }

            const kritikEl = document.getElementById('chart-kritik');
            if (kritikEl) {
                const items = chartData.kritik;
                charts.push(new Chart(kritikEl, {
                    type: 'bar',
                    data: {
                        labels: items.map((it) => it.kode),
                        datasets: [{
                            data: items.map((it) => it.stok),
                            backgroundColor: items.map((it) => it.stok <= 0 ? danger : amber),
                            borderRadius: 6,
                        }],
                    },
                    options: { ...base,
                        indexAxis: 'y',
                        scales: {
                            x: { grid: { color: grid }, ticks: { precision: 0 } },
                            y: { grid: { display: false } },
                        },
                        plugins: { legend: { display: false },
                            tooltip: { callbacks: {
                                title: (cs) => items[cs[0].dataIndex].kode,
                                label: (c) => items[c.dataIndex].nama + ': ' + items[c.dataIndex].stok + ' ' + items[c.dataIndex].satuan,
                            } } },
                    },
                }));
            }
        }

        initDashboardCharts();
        document.getElementById('theme-toggle')?.addEventListener('click', () => setTimeout(initDashboardCharts, 50));
    }

    // ============================
    // DARK MODE TOGGLE
    // ============================
    const themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        });
    }

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

    // ============================
    // TOAST AUTO-DISMISS (partials/flash [data-toast], 6 dtk + progress bar CSS)
    // ============================
    document.querySelectorAll('[data-toast]').forEach((el) => {
        setTimeout(() => {
            el.classList.add('toast-hide');
            setTimeout(() => el.remove(), 400);
        }, 6000);
    });

    // ============================
    // IMPORT ERRORS MODAL (partials/import-errors-modal)
    // Persisten: TANPA data-toast sehingga tidak ikut auto-dismiss.
    // Auto-terbuka dari server (session import_errors); tutup eksplisit
    // via tombol Tutup/Mengerti, klik backdrop, atau ESC.
    // ============================
    const ieBackdrop = document.getElementById('import-errors-backdrop');
    if (ieBackdrop) {
        const tutupModalError = () => ieBackdrop.remove();
        ieBackdrop.querySelectorAll('[data-modal-close]').forEach((b) =>
            b.addEventListener('click', tutupModalError));
        ieBackdrop.addEventListener('click', (e) => {
            if (e.target === ieBackdrop) tutupModalError();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.body.contains(ieBackdrop)) tutupModalError();
        });

        const ieSearch = document.getElementById('import-errors-search');
        const ieItems = Array.from(ieBackdrop.querySelectorAll('[data-import-error-item]'));
        const ieEmpty = document.getElementById('import-errors-empty');
        const ieCount = document.getElementById('import-errors-count');
        const perbaruiHitung = (n) => {
            if (ieCount) ieCount.textContent = 'Menampilkan ' + n + ' dari ' + ieItems.length + ' masalah';
        };
        ieSearch?.addEventListener('input', () => {
            const kunci = ieSearch.value.trim().toLowerCase();
            let n = 0;
            ieItems.forEach((li) => {
                const cocok = kunci === '' || li.textContent.toLowerCase().includes(kunci);
                li.classList.toggle('hidden', !cocok);
                if (cocok) n++;
            });
            ieEmpty?.classList.toggle('hidden', n > 0);
            perbaruiHitung(n);
        });
        perbaruiHitung(ieItems.length);

        ieBackdrop.querySelector('[data-copy-import-errors]')?.addEventListener('click', (e) => {
            const btn = e.currentTarget;
            const teks = ieItems
                .map((li, i) => (i + 1) + '. ' + li.textContent.replace(/\s+/g, ' ').trim())
                .join('\n');
            const umpanBalik = (ok) => {
                const asli = btn.innerHTML;
                btn.innerHTML = ok
                    ? '<span class="material-symbols-outlined text-[16px]" aria-hidden="true">check</span> Tersalin!'
                    : '<span class="material-symbols-outlined text-[16px]" aria-hidden="true">error</span> Gagal menyalin';
                setTimeout(() => { btn.innerHTML = asli; }, 1800);
            };
            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(teks).then(() => umpanBalik(true), () => umpanBalik(false));
            } else {
                // Fallback browser lama: textarea sementara + execCommand.
                const ta = document.createElement('textarea');
                ta.value = teks;
                document.body.appendChild(ta);
                ta.select();
                let ok = false;
                try { ok = document.execCommand('copy'); } catch { ok = false; }
                ta.remove();
                umpanBalik(ok);
            }
        });
    }

    // ============================
    // FILE PICKER (label-button + nama file; input file sr-only)
    // ============================
    document.addEventListener('change', (e) => {
        const input = e.target.closest?.('input[type="file"]');
        if (!input) return;
        const nameEl = input.closest('form')?.querySelector('[data-filename]');
        if (!nameEl) return;
        nameEl.textContent = input.files?.length
            ? Array.from(input.files).map((f) => f.name).join(', ')
            : 'Belum ada file dipilih';
    });

    // ============================
    // SUBMIT LOADING STATE (spinner + disabled, kecuali GET/AJAX-delete/opt-out)
    // ============================
    document.querySelectorAll('form').forEach((form) => {
        if (form.method.toUpperCase() === 'GET') return;
        if (form.hasAttribute('data-delete-ajax') || form.hasAttribute('data-no-loading')) return;
        form.addEventListener('submit', (e) => {
            const btn = (e.submitter && e.submitter.matches('button, input'))
                ? e.submitter
                : form.querySelector('button[type="submit"], input[type="submit"]');
            if (!btn || btn.disabled) return;
            btn.classList.add('is-loading');
            btn.disabled = true;
            // Pengaman: aktifkan lagi bila navigasi batal/validasi klien menahan submit.
            setTimeout(() => { btn.classList.remove('is-loading'); btn.disabled = false; }, 10000);
        });
    });
});