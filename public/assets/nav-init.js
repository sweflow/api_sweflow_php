/**
 * nav-init.js — inicialização compartilhada do navbar/sidebar
 * Usado em: dashboard (via inline), marketplace.php, usuarios.php
 */
(function () {
    document.addEventListener('DOMContentLoaded', function () {

        // ── Sidebar toggle ────────────────────────────────────────────
        const toggle   = document.getElementById('sidebar-toggle');
        const sidebar  = document.getElementById('dash-sidebar');
        const backdrop = document.getElementById('sidebar-backdrop');
        if (toggle && sidebar) {
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
                backdrop?.classList.toggle('show');
            });
            backdrop?.addEventListener('click', () => {
                sidebar.classList.remove('open');
                backdrop.classList.remove('show');
            });
        }

        // ── Dropdowns ─────────────────────────────────────────────────
        document.querySelectorAll('.dash-dropdown-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const menu = document.getElementById('dd-' + this.dataset.dropdown);
                if (!menu) return;
                const open = menu.classList.toggle('open');
                this.classList.toggle('active', open);
            });
        });
        document.addEventListener('click', () => {
            document.querySelectorAll('.dash-dropdown-menu.open').forEach(m => m.classList.remove('open'));
            document.querySelectorAll('.dash-dropdown-btn.active').forEach(b => b.classList.remove('active'));
        });

        // ── Tema toggle ───────────────────────────────────────────────
        const themeBtn  = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('theme-icon');
        const DARK_KEY  = 'dash-dark-mode';
        function applyTheme(dark) {
            document.body.classList.toggle('dark', dark);
            if (themeIcon) themeIcon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
            if (themeBtn) themeBtn.setAttribute('aria-label', dark ? 'Ativar modo claro' : 'Ativar modo escuro');
        }
        if (!document.body.classList.contains('dark')) {
            applyTheme(localStorage.getItem(DARK_KEY) === '1');
        } else {
            if (themeIcon) themeIcon.className = 'fa-solid fa-sun';
        }
        if (themeBtn) {
            themeBtn.addEventListener('click', () => {
                const isDark = document.body.classList.toggle('dark');
                localStorage.setItem(DARK_KEY, isDark ? '1' : '0');
                applyTheme(isDark);
            });
        }

        // ── Avatar ────────────────────────────────────────────────────
        const avatar = document.getElementById('topbar-avatar');
        if (avatar) {
            // Aplica imediatamente do cache
            try {
                const saved = localStorage.getItem('dash-avatar-url');
                if (saved) {
                    const img = document.createElement('img');
                    img.src = saved;
                    img.alt = 'Avatar';
                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
                    img.onerror = () => { avatar.innerHTML = '<i class="fa-solid fa-circle-user"></i>'; };
                    avatar.innerHTML = '';
                    avatar.appendChild(img);
                }
            } catch (_) {}

            // Confirma com a API
            fetch('/api/perfil', { credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : null)
                .then(d => {
                    const url = d?.usuario?.url_avatar;
                    if (!url) return;
                    try { localStorage.setItem('dash-avatar-url', url); } catch (_) {}
                    const current = avatar.querySelector('img');
                    if (current && current.src === url) return;
                    const img = document.createElement('img');
                    img.src = url;
                    img.alt = 'Avatar';
                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
                    img.onerror = () => { avatar.innerHTML = '<i class="fa-solid fa-circle-user"></i>'; };
                    avatar.innerHTML = '';
                    avatar.appendChild(img);
                }).catch(() => {});
        }

        // ── Logout ────────────────────────────────────────────────────
        async function doLogout(e) {
            e.preventDefault();
            await fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin' }).catch(() => {});
            localStorage.removeItem('hasAuthSession');
            window.location.href = '/';
        }
        document.getElementById('logout-btn')?.addEventListener('click', doLogout);
        document.getElementById('sb-logout')?.addEventListener('click', doLogout);

        // ── Scroll shadow ─────────────────────────────────────────────
        const topbar = document.getElementById('dash-topbar');
        if (topbar) {
            window.addEventListener('scroll', () => {
                topbar.classList.toggle('scrolled', window.scrollY > 8);
            }, { passive: true });
        }
    });
})();
