<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace de Módulos — Sweflow</title>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── Marketplace layout ── */
        .mp-hero {
            background: linear-gradient(135deg, #1a1f3b 0%, #2c3166 60%, #1a1f3b 100%);
            color: #fff;
            border-radius: 16px;
            padding: 40px 36px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }
        .mp-hero-text h1 {
            color: #fff;
            font-size: 2rem;
            margin: 0 0 8px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .mp-hero-text h1 i { color: #a5b4fc; }
        .mp-hero-text p {
            color: rgba(255,255,255,0.72);
            font-size: 1.1rem;
            margin: 0;
        }
        .mp-hero-badge {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 12px;
            padding: 14px 20px;
            text-align: center;
            min-width: 120px;
        }
        .mp-hero-badge .badge-num {
            font-size: 2rem;
            font-weight: 800;
            color: #a5b4fc;
            display: block;
        }
        .mp-hero-badge .badge-label {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.6);
            margin-top: 2px;
        }

        /* ── Search bar ── */
        .mp-search-wrap {
            background: #fff;
            border-radius: 14px;
            padding: 20px 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            margin-bottom: 28px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .mp-search-wrap label {
            font-weight: 700;
            font-size: 1rem;
            color: #1e2235;
            white-space: nowrap;
        }
        .mp-search-input {
            flex: 1;
            min-width: 220px;
            padding: 14px 18px;
            font-size: 1.05rem;
            border: 2px solid #e0e4f0;
            border-radius: 10px;
            background: #f8f9ff;
            color: #1e2235;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .mp-search-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99,102,241,0.15);
            background: #fff;
        }
        .mp-search-btn {
            padding: 14px 24px;
            font-size: 1.05rem;
            font-weight: 700;
            background: linear-gradient(135deg, #2c2f55, #4f52a0);
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: opacity 0.15s, transform 0.1s;
            white-space: nowrap;
        }
        .mp-search-btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .mp-search-btn:active { transform: translateY(0); }

        /* ── Grid ── */
        .mp-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 22px;
        }

        /* ── Package card ── */
        .pkg-card {
            background: #fff;
            border: 2px solid #eef0f8;
            border-radius: 16px;
            padding: 0;
            display: flex;
            flex-direction: column;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
            overflow: hidden;
            position: relative;
        }
        .pkg-card:hover {
            border-color: #c7d0ff;
            box-shadow: 0 12px 36px rgba(99,102,241,0.12);
            transform: translateY(-3px);
        }
        .pkg-card.installed {
            border-color: #bbf7d0;
        }
        .pkg-card.installed:hover {
            border-color: #6ee7b7;
            box-shadow: 0 12px 36px rgba(16,185,129,0.12);
        }

        /* accent bar */
        .pkg-card::before {
            content: '';
            display: block;
            height: 5px;
            background: linear-gradient(90deg, #6366f1, #a5b4fc);
        }
        .pkg-card.installed::before {
            background: linear-gradient(90deg, #10b981, #6ee7b7);
        }

        .pkg-card-body {
            padding: 22px 24px 18px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .pkg-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .pkg-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: linear-gradient(135deg, #eef0ff, #dde1ff);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #6366f1;
            flex-shrink: 0;
        }
        .pkg-card.installed .pkg-icon {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            color: #10b981;
        }
        .pkg-title-wrap { flex: 1; min-width: 0; }
        .pkg-name {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1e2235;
            margin: 0 0 4px;
            word-break: break-word;
            line-height: 1.3;
        }
        .pkg-vendor {
            font-size: 0.85rem;
            color: #6b7280;
            font-family: monospace;
        }

        .pkg-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .pkg-status-badge.installed {
            background: #d1fae5;
            color: #065f46;
        }
        .pkg-status-badge.available {
            background: #ede9fe;
            color: #4c1d95;
        }

        .pkg-desc {
            font-size: 1rem;
            color: #4b5563;
            line-height: 1.65;
            margin: 0;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .pkg-meta {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }
        .pkg-meta-item {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.92rem;
            color: #6b7280;
            font-weight: 500;
        }
        .pkg-meta-item i { color: #9ca3af; font-size: 0.85rem; }
        .pkg-meta-item strong { color: #374151; }

        .pkg-card-footer {
            padding: 16px 24px 20px;
            border-top: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .pkg-packagist-link {
            font-size: 0.9rem;
            color: #6366f1;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }
        .pkg-packagist-link:hover { text-decoration: underline; }

        .pkg-btn {
            padding: 12px 22px;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            transition: all 0.15s;
            min-width: 130px;
            justify-content: center;
        }
        .pkg-btn:focus-visible {
            outline: 3px solid #6366f1;
            outline-offset: 3px;
        }
        .pkg-btn.install {
            background: linear-gradient(135deg, #2c2f55, #4f52a0);
            color: #fff;
            box-shadow: 0 4px 14px rgba(99,102,241,0.3);
        }
        .pkg-btn.install:hover { opacity: 0.9; transform: translateY(-1px); }
        .pkg-btn.remove {
            background: #fef2f2;
            color: #b91c1c;
            border: 2px solid #fecaca;
        }
        .pkg-btn.remove:hover { background: #fee2e2; }
        .pkg-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ── Empty / loading states ── */
        .mp-state {
            grid-column: 1 / -1;
            background: #fff;
            border-radius: 16px;
            padding: 56px 32px;
            text-align: center;
            border: 2px dashed #e5e7eb;
        }
        .mp-state i { font-size: 3rem; color: #c7d2fe; margin-bottom: 16px; display: block; }
        .mp-state p { font-size: 1.1rem; color: #6b7280; margin: 0; }
        .mp-state strong { color: #374151; }

        /* ── Modals ── */
        .mp-modal-icon { font-size: 2.5rem; display: block; text-align: center; margin-bottom: 12px; }

        @media (max-width: 900px) {
            .mp-hero { padding: 28px 20px; }
            .mp-hero-text h1 { font-size: 1.5rem; }
            .mp-grid { grid-template-columns: 1fr; }
            .mp-search-wrap { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" style="width:32px;height:32px;border-radius:6px;object-fit:contain;vertical-align:middle;margin-right:8px;" />
                <?php else: ?>
                    <i class="fa-solid fa-cubes"></i>
                <?php endif; ?>
                Dashboard
            </div>
            <nav>
                <ul>
                    <li class="nav-section-label">Navegação</li>
                    <li><a href="/dashboard"><i class="fa-solid fa-arrow-left"></i> Voltar ao Dashboard</a></li>
                    <li><a href="/"><i class="fa-solid fa-house"></i> Início</a></li>
                    <li class="nav-divider"></li>
                    <li class="nav-section-label">Marketplace</li>
                    <li><a href="/modules/marketplace" aria-current="page"><i class="fa-solid fa-store"></i> Módulos</a></li>
                </ul>
            </nav>
        </aside>

        <main class="content" id="main-content">

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
    </div>

    <!-- Modal: Confirmar remoção -->
    <div class="modal-overlay" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <div class="modal">
            <div class="modal-header">
                <h2 id="confirm-title"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;" aria-hidden="true"></i> Confirmar remoção</h2>
                <button class="modal-close" onclick="closeModal('confirm-modal')" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <p id="confirm-message" style="font-size:1.05rem;line-height:1.6;margin:8px 0 20px;"></p>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn ghost" onclick="closeModal('confirm-modal')" style="font-size:1rem;padding:12px 20px;">Cancelar</button>
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
                <button class="modal-close" onclick="closeModal('success-modal')" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <p id="success-message" style="font-size:1.05rem;line-height:1.6;margin:8px 0 20px;"></p>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn primary" onclick="closeModal('success-modal')" style="font-size:1rem;padding:12px 24px;">OK</button>
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
</body>
</html>
