// Aplikasi GenTOt — sidebar mobile (drawer) & desktop (minimize), plus perilaku global.

document.addEventListener('DOMContentLoaded', () => {
    const btnToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('app-sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const mqDesktop = window.matchMedia('(min-width: 768px)');

    // Restore state minimize desktop dari localStorage
    if (localStorage.getItem('sidebar-minimized') === '1') {
        document.body.classList.add('sidebar-minimized');
    }

    if (btnToggle && sidebar) {
        btnToggle.addEventListener('click', () => {
            if (mqDesktop.matches) {
                // Desktop: minimize/expand sidebar (rail ikon 64px)
                const minimized = document.body.classList.toggle('sidebar-minimized');
                localStorage.setItem('sidebar-minimized', minimized ? '1' : '0');
            } else {
                // Mobile: buka/tutup drawer + overlay
                sidebar.classList.toggle('-translate-x-full');
                if (overlay) {
                    overlay.classList.toggle('hidden');
                }
            }
        });
    }
    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('hidden');
        });
    }

    // Konfirmasi hapus umum
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
});