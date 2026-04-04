'use strict';

// Seta flag antes de nav-init.js para evitar listener duplicado
window.__sidebarToggleInit = true;

document.addEventListener('DOMContentLoaded', function () {
    // Fecha modal de rota ao clicar no X ou no overlay
    const rdClose = document.getElementById('route-detail-close');
    if (rdClose) {
        rdClose.addEventListener('click', () => {
            const ov = document.getElementById('route-detail-modal');
            if (ov) { ov.classList.remove('show'); ov.setAttribute('aria-hidden', 'true'); }
        });
    }
    document.getElementById('route-detail-modal')?.addEventListener('click', function (e) {
        if (e.target === this) { this.classList.remove('show'); this.setAttribute('aria-hidden', 'true'); }
    });

    // Sidebar toggle
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar       = document.getElementById('dash-sidebar');
    const backdrop      = document.getElementById('sidebar-backdrop');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('open');
            backdrop?.classList.toggle('show');
        });
        backdrop?.addEventListener('click', () => {
            sidebar.classList.remove('open');
            backdrop.classList.remove('show');
        });
        document.querySelectorAll('.dash-sidenav-link[href^="#"]').forEach(a => {
            a.addEventListener('click', () => {
                if (window.innerWidth < 1024) {
                    sidebar.classList.remove('open');
                    backdrop?.classList.remove('show');
                }
            });
        });
    }

    // Smooth scroll
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        const href = a.getAttribute('href');
        if (!href || href === '#') return;
        a.addEventListener('click', e => {
            const t = document.querySelector(href);
            if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });

    // Botões extras de e-mail apontam para o mesmo modal
    ['open-email-modal-hero', 'open-email-modal2'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', () => document.getElementById('open-email-modal')?.click());
    });

    // Sidebar links de perfil/criar usuário/logout
    const sbPerfil = document.getElementById('sb-meu-perfil');
    const sbCriar  = document.getElementById('sb-criar-user');
    const sbLogout = document.getElementById('sb-logout');
    if (sbPerfil) sbPerfil.addEventListener('click', e => { e.preventDefault(); document.getElementById('open-meu-perfil')?.click(); });
    if (sbCriar)  sbCriar.addEventListener('click',  e => { e.preventDefault(); document.getElementById('open-criar-usuario')?.click(); });
    if (sbLogout) sbLogout.addEventListener('click',  e => { e.preventDefault(); document.getElementById('logout-btn')?.click(); });

    // Avatar topbar → perfil + carrega foto
    const avatar = document.getElementById('topbar-avatar');
    if (avatar) {
        avatar.addEventListener('click', () => document.getElementById('open-meu-perfil')?.click());
        fetch('/api/auth/me', { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                const user = data?.user ?? data;
                if (user?.avatar_url) {
                    avatar.innerHTML = `<img src="${user.avatar_url}" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />`;
                } else if (user?.nome || user?.username) {
                    const initials = ((user.nome || user.username || '?')[0]).toUpperCase();
                    avatar.innerHTML = `<span style="font-size:1.1rem;font-weight:800;">${initials}</span>`;
                }
            })
            .catch(() => {});
    }
});
