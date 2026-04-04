import { isLogado, getUsuario, getToken, getPerfil, logout } from './api.js';

// Guard — redireciona se não estiver logado
if (!isLogado()) window.location.href = 'index.html';

// ── Helpers ───────────────────────────────────────────────────────────────

const nivelLabel = {
    usuario:      'Usuário',
    moderador:    'Moderador',
    admin:        'Admin',
    admin_system: 'Admin System',
};

function inicialDo(nome) {
    return (nome || '?').trim().charAt(0).toUpperCase();
}

function formatarData(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('pt-BR', { day:'2-digit', month:'long', year:'numeric' });
}

// ── Sidebar ───────────────────────────────────────────────────────────────

function renderSidebar(u) {
    const avatarEl = document.getElementById('sidebar-avatar');
    if (u.url_avatar) {
        avatarEl.innerHTML = `<img src="${u.url_avatar}" alt="Avatar" onerror="this.parentElement.innerHTML='<i class=\\'fa-solid fa-user\\'></i>'">`;
    } else {
        avatarEl.textContent = inicialDo(u.nome_completo);
    }
    document.getElementById('sidebar-name').textContent = u.nome_completo || u.username || '—';
    const levelEl = document.getElementById('sidebar-level');
    levelEl.textContent = nivelLabel[u.nivel_acesso] || u.nivel_acesso;
    levelEl.className = `sidebar-user-level level-${u.nivel_acesso}`;
}

// ── Sections ──────────────────────────────────────────────────────────────

function renderPerfil(u) {
    const clone = document.getElementById('tpl-perfil').content.cloneNode(true);

    // Avatar
    const avatarEl = clone.querySelector('.profile-avatar-lg');
    if (u.url_avatar) {
        const img = document.createElement('img');
        img.src = u.url_avatar;
        img.alt = 'Avatar';
        img.onerror = () => { img.replaceWith(document.createTextNode(inicialDo(u.nome_completo))); };
        avatarEl.appendChild(img);
    } else {
        avatarEl.textContent = inicialDo(u.nome_completo);
    }

    // Capa
    const capaEl = clone.querySelector('.perfil-capa');
    if (u.url_capa) {
        capaEl.src = u.url_capa;
        capaEl.hidden = false;
    }

    // Cabeçalho
    clone.querySelector('.profile-name').textContent = u.nome_completo || '—';
    clone.querySelector('.profile-username').textContent = `@${u.username || '—'}`;
    const levelEl = clone.querySelector('.sidebar-user-level');
    levelEl.textContent = nivelLabel[u.nivel_acesso] || u.nivel_acesso;
    levelEl.classList.add(`level-${u.nivel_acesso}`);

    // Campos
    clone.querySelector('.pf-email').textContent = u.email || '—';

    const statusEl = clone.querySelector('.pf-status');
    statusEl.textContent = u.ativo ? '✔ Ativo' : '✖ Inativo';
    statusEl.style.color = u.ativo ? 'var(--success)' : 'var(--danger)';

    const verificadoEl = clone.querySelector('.pf-verificado');
    verificadoEl.textContent = u.verificado_email ? '✔ Verificado' : '⚠ Não verificado';
    verificadoEl.style.color = u.verificado_email ? 'var(--success)' : '#fbbf24';

    clone.querySelector('.pf-criado').textContent = formatarData(u.criado_em);

    if (u.biografia) {
        clone.querySelector('.pf-bio').textContent = u.biografia;
        clone.querySelector('.pf-bio-wrap').hidden = false;
    }

    return clone;
}

function renderToken() {
    const token = getToken() || '';
    const clone = document.getElementById('tpl-token').content.cloneNode(true);

    clone.querySelector('.token-value').textContent = token;

    try {
        const partes = token.split('.');
        const payload = JSON.parse(atob(partes[1].replace(/-/g,'+').replace(/_/g,'/')));
        clone.querySelector('.tk-nivel').textContent = payload.nivel_acesso || '—';
        clone.querySelector('.tk-iat').textContent = payload.iat ? new Date(payload.iat * 1000).toLocaleString('pt-BR') : '—';
        clone.querySelector('.tk-exp').textContent = payload.exp ? new Date(payload.exp * 1000).toLocaleString('pt-BR') : '—';
        clone.querySelector('.token-info').hidden = false;
    } catch {}

    return clone;
}

// ── Navigation ────────────────────────────────────────────────────────────

const sections = {
    perfil: { title: 'Meu Perfil', sub: 'Dados da sua conta' },
    token:  { title: 'Token de Acesso', sub: 'JWT atual da sessão' },
};

let perfilData = null;

async function showSection(name) {
    document.querySelectorAll('.nav-item').forEach(el => el.classList.toggle('active', el.dataset.section === name));
    document.getElementById('topbar-title').textContent = sections[name].title;
    document.getElementById('topbar-sub').textContent   = sections[name].sub;

    const content = document.getElementById('content');

    if (name === 'perfil') {
        if (!perfilData) {
            content.innerHTML = '<div class="loading-overlay"><span class="spinner" style="border-color:rgba(79,70,229,0.3);border-top-color:var(--brand);"></span> Carregando...</div>';
            try {
                const res = await getPerfil();
                perfilData = res.usuario || res;
                renderSidebar(perfilData);
            } catch (e) {
                content.innerHTML = `<div class="loading-overlay" style="color:var(--danger)"><i class="fa-solid fa-circle-exclamation"></i> ${e.message}</div>`;
                return;
            }
        }
        content.innerHTML = '';
        content.appendChild(renderPerfil(perfilData));
    }

    if (name === 'token') {
        content.innerHTML = '';
        content.appendChild(renderToken());
        document.getElementById('copy-btn')?.addEventListener('click', () => {
            navigator.clipboard.writeText(getToken() || '');
            const btn = document.getElementById('copy-btn');
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copiado!';
            setTimeout(() => btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copiar', 2000);
        });
    }
}

// ── Init ──────────────────────────────────────────────────────────────────

// Preenche sidebar com dados do localStorage enquanto carrega da API
const cached = getUsuario();
if (cached) renderSidebar(cached);

// Nav clicks
document.querySelectorAll('.nav-item[data-section]').forEach(btn => {
    btn.addEventListener('click', () => showSection(btn.dataset.section));
});

// Refresh
document.getElementById('refresh-btn').addEventListener('click', () => {
    perfilData = null;
    const active = document.querySelector('.nav-item.active')?.dataset.section || 'perfil';
    showSection(active);
});

// Logout
document.getElementById('logout-btn').addEventListener('click', async () => {
    const btn = document.getElementById('logout-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saindo...';
    await logout();
    window.location.href = 'index.html';
});

// Carrega seção inicial
showSection('perfil');