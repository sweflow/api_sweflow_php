<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace de Módulos — Sweflow</title>
    <link rel="stylesheet" href="/style.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        html, body { margin: 0; padding: 0; }
        html.will-dark, html.will-dark body, html.will-dark .dash-body { background: #0b0d18 !important; color: #f1f5f9 !important; }
    </style>
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        if (localStorage.getItem('dash-dark-mode') === '1') {
            document.documentElement.classList.add('will-dark', 'dash-no-transition');
        } else {
            document.documentElement.classList.add('dash-no-transition');
        }
        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('dash-dark-mode') === '1') document.body.classList.add('dark');
            document.documentElement.classList.remove('will-dark');
            requestAnimationFrame(function() { requestAnimationFrame(function() { document.documentElement.classList.remove('dash-no-transition'); }); });
        });
    </script><script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        if (localStorage.getItem('dash-dark-mode') === '1') {
            document.documentElement.classList.add('will-dark', 'dash-no-transition');
        } else {
            document.documentElement.classList.add('dash-no-transition');
        }
        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('dash-dark-mode') === '1') document.body.classList.add('dark');
            document.documentElement.classList.remove('will-dark');
            requestAnimationFrame(function() { requestAnimationFrame(function() { document.documentElement.classList.remove('dash-no-transition'); }); });
        });
    </script>
    <style>
        /* ── Marketplace — usa variáveis do tema (light/dark) ── */
        .mp-hero {
            background: linear-gradient(135deg, rgba(79,70,229,0.12) 0%, rgba(124,58,237,0.08) 100%);
            border: 1px solid rgba(79,70,229,0.2);
            border-radius: 16px;
            padding: 36px 32px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }
        .mp-hero-text h1 {
            color: var(--text-primary);
            font-size: 1.8rem;
            margin: 0 0 8px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .mp-hero-text h1 i { color: #818cf8; }
        .mp-hero-text p { color: var(--text-secondary); font-size: 1rem; margin: 0; }
        .mp-hero-badge {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 12px;
            padding: 14px 20px;
            text-align: center;
            min-width: 110px;
        }
        .mp-hero-badge .badge-num { font-size: 2rem; font-weight: 800; color: #818cf8; display: block; }
        .mp-hero-badge .badge-label { font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }

        .mp-search-wrap {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 14px;
            padding: 18px 22px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .mp-search-wrap label { font-weight: 700; font-size: 1rem; color: var(--text-secondary); white-space: nowrap; }
        .mp-search-input {
            flex: 1;
            min-width: 220px;
            padding: 13px 16px;
            font-size: 1rem;
            border: 1px solid var(--border-input);
            border-radius: 10px;
            background: var(--bg-input);
            color: var(--text-primary);
            transition: border-color 0.15s, box-shadow 0.15s;
            outline: none;
            font-family: inherit;
        }
        .mp-search-input::placeholder { color: var(--text-muted); }
        .mp-search-input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.18); background: rgba(79,70,229,0.04); }
        .mp-search-btn {
            padding: 13px 22px;
            font-size: 1rem;
            font-weight: 700;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            transition: opacity 0.15s, transform 0.1s;
            white-space: nowrap;
            font-family: inherit;
            box-shadow: 0 4px 14px rgba(79,70,229,0.3);
            touch-action: manipulation;
        }
        .mp-search-btn:hover { opacity: 0.9; transform: translateY(-1px); }

        .mp-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        .pkg-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
            overflow: hidden;
            position: relative;
        }
        .pkg-card:hover { border-color: rgba(79,70,229,0.4); box-shadow: 0 12px 36px rgba(0,0,0,0.15); transform: translateY(-3px); }
        .pkg-card.installed { border-color: rgba(34,197,94,0.25); }
        .pkg-card.installed:hover { border-color: rgba(34,197,94,0.45); box-shadow: 0 12px 36px rgba(34,197,94,0.1); }
        .pkg-card::before { content: ''; display: block; height: 4px; background: linear-gradient(90deg, #4f46e5, #818cf8); }
        .pkg-card.installed::before { background: linear-gradient(90deg, #22c55e, #4ade80); }
        .pkg-card-body { padding: 22px 22px 16px; flex: 1; display: flex; flex-direction: column; gap: 14px; }
        .pkg-header { display: flex; align-items: flex-start; gap: 14px; }
        .pkg-icon {
            width: 48px; height: 48px; border-radius: 12px;
            background: rgba(79,70,229,0.12); display: flex; align-items: center;
            justify-content: center; font-size: 1.4rem; color: #818cf8; flex-shrink: 0;
        }
        .pkg-card.installed .pkg-icon { background: rgba(34,197,94,0.1); color: #22c55e; }
        .pkg-title-wrap { flex: 1; min-width: 0; }
        .pkg-name { font-size: 1.05rem; font-weight: 800; color: var(--text-primary); margin: 0 0 4px; word-break: break-word; line-height: 1.3; }
        .pkg-vendor { font-size: 0.82rem; color: var(--text-muted); font-family: monospace; }
        .pkg-status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; white-space: nowrap; flex-shrink: 0;
        }
        .pkg-status-badge.installed { background: rgba(34,197,94,0.1); color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }
        .pkg-status-badge.available { background: rgba(79,70,229,0.1); color: #818cf8; border: 1px solid rgba(79,70,229,0.2); }
        .pkg-desc { font-size: 0.95rem; color: var(--text-secondary); line-height: 1.65; margin: 0; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .pkg-meta { display: flex; gap: 16px; flex-wrap: wrap; }
        .pkg-meta-item { display: flex; align-items: center; gap: 7px; font-size: 0.88rem; color: var(--text-secondary); font-weight: 500; }
        .pkg-meta-item i { color: var(--text-muted); font-size: 0.82rem; }
        .pkg-meta-item strong { color: var(--text-primary); }
        .pkg-card-footer { padding: 14px 22px 18px; border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; background: var(--bg-card); }
        .pkg-packagist-link { font-size: 0.88rem; color: #818cf8; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
        .pkg-packagist-link:hover { color: #a5b4fc; text-decoration: underline; }
        .pkg-btn {
            padding: 11px 20px; font-size: 0.97rem; font-weight: 700; border: none; border-radius: 10px;
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.15s; min-width: 120px; justify-content: center; font-family: inherit;
            touch-action: manipulation;
        }
        .pkg-btn.install { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff; box-shadow: 0 4px 14px rgba(79,70,229,0.3); }
        .pkg-btn.install:hover { opacity: 0.9; transform: translateY(-1px); }
        .pkg-btn.remove { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
        .pkg-btn.remove:hover { background: rgba(239,68,68,0.18); }
        .pkg-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        .mp-state { grid-column: 1/-1; background: var(--bg-card); border-radius: 16px; padding: 56px 32px; text-align: center; border: 1px dashed var(--border-card); }
        .mp-state i { font-size: 3rem; color: var(--text-muted); margin-bottom: 16px; display: block; }
        .mp-state p { font-size: 1rem; color: var(--text-secondary); margin: 0; }
        .mp-state strong { color: var(--text-primary); }
        @media (max-width: 900px) {
            .mp-hero { padding: 24px 18px; }
            .mp-hero-text h1 { font-size: 1.4rem; }
            .mp-grid { grid-template-columns: 1fr; }
            .mp-search-wrap { padding: 14px; }
        }
    </style>
</head>
<body class="dash-body">
    <!-- TOPBAR -->
    <header class="dash-topbar" id="dash-topbar">
        <div class="dash-topbar-left">
            <button class="dash-sidebar-toggle" id="sidebar-toggle" aria-label="Menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <a href="/" class="dash-brand">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="dash-brand-img" />
                <?php else: ?>
                    <img src="/favicon.ico" alt="Sweflow" class="dash-brand-img" />
                <?php endif; ?>
                <span class="dash-brand-name">Sweflow <span class="dash-brand-accent">API</span></span>
            </a>
        </div>

        <nav class="dash-topbar-nav" aria-label="Navegação principal">
            <div class="dash-dropdown">
                <button class="dash-dropdown-btn" data-dropdown="monitor">
                    <i class="fa-solid fa-chart-line"></i> Monitoramento
                    <i class="fa-solid fa-chevron-down dash-dd-arrow"></i>
                </button>
                <div class="dash-dropdown-menu" id="dd-monitor">
                    <a href="/dashboard#metrics"  class="dash-dd-item"><i class="fa-solid fa-gauge-high"></i> Métricas</a>
                    <a href="/dashboard#modules"  class="dash-dd-item"><i class="fa-solid fa-layer-group"></i> Módulos</a>
                    <a href="/dashboard#routes"   class="dash-dd-item"><i class="fa-solid fa-route"></i> Rotas</a>
                    <a href="/dashboard#features" class="dash-dd-item"><i class="fa-solid fa-toggle-on"></i> Funcionalidades</a>
                </div>
            </div>
            <div class="dash-dropdown">
                <button class="dash-dropdown-btn" data-dropdown="config">
                    <i class="fa-solid fa-gear"></i> Configuração
                    <i class="fa-solid fa-chevron-down dash-dd-arrow"></i>
                </button>
                <div class="dash-dropdown-menu" id="dd-config">
                    <a href="/dashboard#capabilities"  class="dash-dd-item"><i class="fa-solid fa-plug"></i> Capacidades</a>
                    <a href="/modules/marketplace"     class="dash-dd-item"><i class="fa-solid fa-store"></i> Marketplace</a>
                    <a href="/dashboard#email-actions" class="dash-dd-item"><i class="fa-solid fa-envelope"></i> E-mail</a>
                </div>
            </div>
            <div class="dash-dropdown">
                <button class="dash-dropdown-btn" data-dropdown="conta">
                    <i class="fa-solid fa-circle-user"></i> Conta
                    <i class="fa-solid fa-chevron-down dash-dd-arrow"></i>
                </button>
                <div class="dash-dropdown-menu" id="dd-conta">
                    <a href="/dashboard/usuarios" class="dash-dd-item"><i class="fa-solid fa-users"></i> Usuários</a>
                    <div class="dash-dd-divider"></div>
                    <button type="button" id="logout-btn" class="dash-dd-item dash-dd-danger"><i class="fa-solid fa-right-from-bracket"></i> Sair</button>
                </div>
            </div>
        </nav>

        <div class="dash-topbar-right">
            <button class="dash-theme-toggle" id="theme-toggle" aria-label="Alternar tema" title="Alternar dark/light mode">
                <div class="dash-theme-toggle-thumb" id="theme-thumb">
                    <i class="fa-solid fa-moon" id="theme-icon"></i>
                </div>
            </button>
            <div class="dash-avatar-wrap" id="topbar-avatar-wrap">
                <div class="dash-avatar" id="topbar-avatar" title="Meu perfil" role="button" tabindex="0" aria-label="Meu perfil">
                    <i class="fa-solid fa-circle-user"></i>
                </div>
                <span class="dash-avatar-status"></span>
            </div>
        </div>
    </header>

    <div class="dash-layout">
        <!-- SIDEBAR -->
        <aside class="dash-sidebar" id="dash-sidebar">
            <div class="dash-sidebar-inner">
                <nav class="dash-sidenav">
                    <div class="dash-sidenav-section">
                        <span class="dash-sidenav-label">Configuração</span>
                        <a href="/modules/marketplace"     class="dash-sidenav-link dash-sidenav-active"><i class="fa-solid fa-store"></i> Marketplace</a>
                    </div>
                    <div class="dash-sidenav-section">
                        <span class="dash-sidenav-label">Conta</span>
                        <a href="/dashboard/usuarios" class="dash-sidenav-link"><i class="fa-solid fa-users"></i> Usuários</a>
                    </div>
                    <div class="dash-sidenav-section">
                        <a href="/dashboard" class="dash-sidenav-link"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                        <a href="/" class="dash-sidenav-link"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a>
                        <button type="button" id="sb-logout" class="dash-sidenav-link dash-sidenav-danger"><i class="fa-solid fa-right-from-bracket"></i> Sair</button>
                    </div>
                </nav>
            </div>
        </aside>
        <div class="dash-sidebar-backdrop" id="sidebar-backdrop"></div>

        <main class="dash-main" id="main-content">

            <!-- Hero -->
            <div class="mp-hero" role="banner">
                <div class="mp-hero-text">
                    <h1><i class="fa-solid fa-store" aria-hidden="true"></i> Marketplace de Módulos</h1>
                    <p>Descubra, instale e gerencie módulos para estender a plataforma Sweflow.</p>
                </div>
                <div class="mp-hero-badge" aria-label="Total de módulos encontrados">
                    <span class="badge-num" id="total-count">—</span>
                    <span class="badge-label">módulos</span>
                </div>
            </div>

            <!-- Search -->
            <div class="mp-search-wrap" role="search">
                <label for="q"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar</label>
                <input
                    id="q"
                    type="search"
                    class="mp-search-input"
                    placeholder="Nome do módulo ou vendor (ex.: sweflow)"
                    aria-label="Pesquisar módulos no Packagist"
                    autocomplete="off"
                />
                <button class="mp-search-btn" id="search" aria-label="Executar busca">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar
                </button>
            </div>

            <!-- Grid -->
            <div class="mp-grid" id="pkg-grid" role="list" aria-live="polite" aria-label="Lista de módulos"></div>

        </main>
    </div><!-- /.dash-layout -->

    <!-- Modal: Confirmar remoção -->
    <div class="modal-overlay" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <div class="modal">
            <div class="modal-header">
                <h2 id="confirm-title"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;" aria-hidden="true"></i> Confirmar remoção</h2>
                <button class="modal-close" data-close="confirm-modal" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <p id="confirm-message" style="font-size:1.05rem;line-height:1.6;margin:8px 0 20px;"></p>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn ghost" data-close="confirm-modal" style="font-size:1rem;padding:12px 20px;">Cancelar</button>
                <button class="btn" id="confirm-btn" style="background:#dc2626;color:#fff;font-size:1rem;padding:12px 20px;border:none;">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i> Remover
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Sucesso -->
    <div class="modal-overlay" id="success-modal" role="dialog" aria-modal="true" aria-labelledby="success-title">
        <div class="modal">
            <div class="modal-header">
                <h2 id="success-title"><i class="fa-solid fa-circle-check" style="color:#10b981;" aria-hidden="true"></i> Operação concluída</h2>
                <button class="modal-close" data-close="success-modal" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <p id="success-message" style="font-size:1.05rem;line-height:1.6;margin:8px 0 20px;"></p>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn primary" data-close="success-modal" style="font-size:1rem;padding:12px 24px;">OK</button>
            </div>
        </div>
    </div>

    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
    function openModal(id) {
        const el = document.getElementById(id);
        el.classList.add('show');
        el.querySelector('button')?.focus();
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }
    document.addEventListener('click', (e) => {
        const target = e.target.closest('[data-close]');
        if (target) closeModal(target.dataset.close);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
        }
    });

    (function () {
        const grid        = document.getElementById('pkg-grid');
        const qInput      = document.getElementById('q');
        const searchBtn   = document.getElementById('search');
        const totalCount  = document.getElementById('total-count');
        let currentPkg    = null;
        let currentAction = null;
        let currentBtn    = null;

        // ── Helpers ──────────────────────────────────────────────────────
        function shortName(fullName) {
            return fullName.split('/').pop() ?? fullName;
        }

        function iconForPkg(name) {
            const n = name.toLowerCase();
            if (n.includes('email') || n.includes('mail'))   return 'fa-envelope';
            if (n.includes('auth') || n.includes('login'))   return 'fa-shield-halved';
            if (n.includes('payment') || n.includes('pay'))  return 'fa-credit-card';
            if (n.includes('storage') || n.includes('file')) return 'fa-hard-drive';
            if (n.includes('notification'))                   return 'fa-bell';
            if (n.includes('user') || n.includes('usuario')) return 'fa-users';
            if (n.includes('log') || n.includes('audit'))    return 'fa-clipboard-list';
            if (n.includes('cache'))                          return 'fa-bolt';
            if (n.includes('queue') || n.includes('job'))    return 'fa-layer-group';
            if (n.includes('api'))                            return 'fa-plug';
            return 'fa-puzzle-piece';
        }

        // ── Card template ─────────────────────────────────────────────────
        function card(pkg) {
            const name      = pkg.name      || '';
            const desc      = pkg.description || 'Sem descrição disponível.';
            const dls       = (pkg.downloads || 0).toLocaleString('pt-BR');
            const installed = pkg.installed  || false;
            const url       = pkg.url        || '';
            const repo      = pkg.repository || '';
            const icon      = iconForPkg(name);
            const vendor    = name.includes('/') ? name.split('/')[0] : '';
            const pkgShort  = shortName(name);

            const statusBadge = installed
                ? `<span class="pkg-status-badge installed" aria-label="Módulo instalado"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Instalado</span>`
                : `<span class="pkg-status-badge available" aria-label="Disponível para instalação"><i class="fa-solid fa-circle-dot" aria-hidden="true"></i> Disponível</span>`;

            const actionBtn = installed
                ? `<button class="pkg-btn remove" data-pkg="${name}" data-action="uninstall" aria-label="Remover módulo ${pkgShort}">
                       <i class="fa-solid fa-trash" aria-hidden="true"></i> Remover
                   </button>`
                : `<button class="pkg-btn install" data-pkg="${name}" data-action="install" aria-label="Instalar módulo ${pkgShort}">
                       <i class="fa-solid fa-download" aria-hidden="true"></i> Instalar
                   </button>`;

            const externalLinks = [];
            if (url)  externalLinks.push(`<a href="${url}"  target="_blank" rel="noopener noreferrer" class="pkg-packagist-link" aria-label="Ver ${pkgShort} no Packagist"><i class="fa-solid fa-box-open" aria-hidden="true"></i> Packagist</a>`);
            if (repo) externalLinks.push(`<a href="${repo}" target="_blank" rel="noopener noreferrer" class="pkg-packagist-link" aria-label="Ver repositório de ${pkgShort}"><i class="fa-brands fa-github" aria-hidden="true"></i> Repositório</a>`);

            return `
            <article class="pkg-card ${installed ? 'installed' : ''}" role="listitem" aria-label="Módulo ${pkgShort}">
                <div class="pkg-card-body">
                    <div class="pkg-header">
                        <div class="pkg-icon" aria-hidden="true">
                            <i class="fa-solid ${icon}"></i>
                        </div>
                        <div class="pkg-title-wrap">
                            <h2 class="pkg-name">${pkgShort}</h2>
                            ${vendor ? `<span class="pkg-vendor">${name}</span>` : ''}
                        </div>
                        ${statusBadge}
                    </div>

                    <p class="pkg-desc">${desc}</p>

                    <div class="pkg-meta">
                        <span class="pkg-meta-item">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                            <strong>${dls}</strong> downloads
                        </span>
                    </div>
                </div>

                <div class="pkg-card-footer">
                    <div style="display:flex;gap:14px;flex-wrap:wrap;">
                        ${externalLinks.join('')}
                    </div>
                    ${actionBtn}
                </div>
            </article>`;
        }

        // ── Render ────────────────────────────────────────────────────────
        function setLoading() {
            grid.innerHTML = `
                <div class="mp-state" role="status" aria-live="polite">
                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                    <p><strong>Buscando módulos no Packagist...</strong><br>Aguarde um momento.</p>
                </div>`;
            if (totalCount) totalCount.textContent = '—';
        }

        function renderPkgs(pkgs) {
            if (!grid) return;
            if (totalCount) totalCount.textContent = pkgs.length;

            if (!pkgs || pkgs.length === 0) {
                grid.innerHTML = `
                    <div class="mp-state" role="status">
                        <i class="fa-solid fa-box-open" aria-hidden="true"></i>
                        <p><strong>Nenhum módulo encontrado.</strong><br>Tente outro termo de busca.</p>
                    </div>`;
                return;
            }

            grid.innerHTML = pkgs.map(p => card(p)).join('');

            grid.querySelectorAll('button[data-pkg]').forEach(btn => {
                btn.addEventListener('click', () => {
                    currentPkg    = btn.getAttribute('data-pkg');
                    currentAction = btn.getAttribute('data-action');
                    currentBtn    = btn;

                    if (currentAction === 'uninstall') {
                        document.getElementById('confirm-message').textContent =
                            `Tem certeza que deseja remover o módulo "${shortName(currentPkg)}"? Esta ação não pode ser desfeita.`;
                        openModal('confirm-modal');
                    } else {
                        document.getElementById('confirm-btn').click();
                    }
                });
            });
        }

        // ── Fetch ─────────────────────────────────────────────────────────
        async function fetchPkgs(query) {
            try {
                const res  = await fetch('/api/system/marketplace?q=' + encodeURIComponent(query ?? ''));
                const data = await res.json();
                return data.results || [];
            } catch {
                return [];
            }
        }

        // ── Confirm action ────────────────────────────────────────────────
        document.getElementById('confirm-btn').addEventListener('click', async () => {
            closeModal('confirm-modal');
            if (!currentPkg || !currentAction) return;

            const btn = currentBtn;
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = currentAction === 'install'
                    ? '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Instalando...'
                    : '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Removendo...';
            }

            try {
                const endpoint = currentAction === 'install'
                    ? '/api/system/modules/install'
                    : '/api/system/modules/uninstall';

                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ package: currentPkg }),
                });
                const out = await res.json().catch(() => ({}));

                if (res.ok) {
                    document.getElementById('success-message').textContent =
                        out.message || 'Operação realizada com sucesso.';
                    openModal('success-modal');
                    setLoading();
                    const pkgs = await fetchPkgs(qInput.value.trim());
                    renderPkgs(pkgs);
                } else {
                    alert('Falha: ' + (out.message || res.status));
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = currentAction === 'install'
                            ? '<i class="fa-solid fa-download" aria-hidden="true"></i> Instalar'
                            : '<i class="fa-solid fa-trash" aria-hidden="true"></i> Remover';
                    }
                }
            } catch {
                alert('Erro de conexão. Tente novamente.');
                if (btn) btn.disabled = false;
            }
        });

        // ── Events ────────────────────────────────────────────────────────
        if (searchBtn) {
            searchBtn.addEventListener('click', async () => {
                setLoading();
                const pkgs = await fetchPkgs(qInput.value.trim());
                renderPkgs(pkgs);
            });
        }
        if (qInput) {
            qInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') searchBtn?.click(); });
        }

        // ── Init ──────────────────────────────────────────────────────────
        setLoading();
        fetchPkgs('').then(renderPkgs);
    })();
    </script>
    <script src="/assets/nav-init.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/nav-init.js') ?>"></script>
</body>
</html>
