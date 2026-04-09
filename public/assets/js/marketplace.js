'use strict';

(function () {
    function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('show');
        el.querySelector('button')?.focus();
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('show');
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

    const grid        = document.getElementById('pkg-grid');
    const qInput      = document.getElementById('q');
    const searchBtn   = document.getElementById('search');
    const totalCount  = document.getElementById('total-count');
    let currentPkg    = null;
    let currentAction = null;
    let currentBtn    = null;

    // ── Helpers ──────────────────────────────────────────────────────────
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

    // ── Card template ─────────────────────────────────────────────────────
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
        if (url)  externalLinks.push(`<a href="${url}"  target="_blank" rel="noopener noreferrer" class="pkg-packagist-link"><i class="fa-solid fa-box-open" aria-hidden="true"></i> Packagist</a>`);
        if (repo) externalLinks.push(`<a href="${repo}" target="_blank" rel="noopener noreferrer" class="pkg-packagist-link"><i class="fa-brands fa-github" aria-hidden="true"></i> Repositório</a>`);

        return `
        <article class="pkg-card ${installed ? 'installed' : ''}" role="listitem" aria-label="Módulo ${pkgShort}" data-pkg-name="${name}" style="cursor:pointer;">
            <div class="pkg-card-body">
                <div class="pkg-header">
                    <div class="pkg-icon" aria-hidden="true"><i class="fa-solid ${icon}"></i></div>
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
                <div style="display:flex;gap:14px;flex-wrap:wrap;">${externalLinks.join('')}</div>
                ${actionBtn}
            </div>
        </article>`;
    }

    // ── Modal de detalhes ─────────────────────────────────────────────────
    function openDetailModal(pkg) {
        const name      = pkg.name      || '';
        const desc      = pkg.description || 'Sem descrição disponível.';
        const dls       = (pkg.downloads || 0).toLocaleString('pt-BR');
        const installed = pkg.installed  || false;
        const url       = pkg.url        || '';
        const repo      = pkg.repository || '';
        const vendor    = name.includes('/') ? name.split('/')[0] : '';
        const pkgShort  = shortName(name);

        const body = document.getElementById('detail-modal-body');
        if (!body) return;
        body.textContent = '';

        // Hero
        const hero = document.createElement('div');
        hero.className = 'pkg-detail-hero';

        const iconEl = document.createElement('div');
        iconEl.className = 'pkg-detail-icon' + (installed ? ' installed' : '');
        const iconI = document.createElement('i');
        iconI.className = 'fa-solid ' + iconForPkg(name);
        iconEl.appendChild(iconI);

        const titleWrap = document.createElement('div');
        titleWrap.style.flex = '1';
        const titleEl = document.createElement('div');
        titleEl.className = 'pkg-detail-title';
        titleEl.textContent = pkgShort;
        const vendorEl = document.createElement('div');
        vendorEl.className = 'pkg-detail-vendor';
        vendorEl.textContent = name;
        const statusEl = document.createElement('div');
        statusEl.style.marginTop = '6px';
        statusEl.innerHTML = installed
            ? `<span class="pkg-status-badge installed"><i class="fa-solid fa-circle-check"></i> Instalado</span>`
            : `<span class="pkg-status-badge available"><i class="fa-solid fa-circle-dot"></i> Disponível</span>`;
        titleWrap.appendChild(titleEl);
        if (vendor) titleWrap.appendChild(vendorEl);
        titleWrap.appendChild(statusEl);
        hero.appendChild(iconEl);
        hero.appendChild(titleWrap);
        body.appendChild(hero);

        // Descrição
        const descEl = document.createElement('p');
        descEl.className = 'pkg-detail-desc';
        descEl.textContent = desc;
        body.appendChild(descEl);

        // Grid de metadados
        const gridEl = document.createElement('div');
        gridEl.className = 'pkg-detail-grid';
        [
            ['fa-box',         'Pacote',     name],
            ['fa-download',    'Downloads',  dls],
            ['fa-code-branch', 'Vendor',     vendor || '—'],
            ['fa-circle-info', 'Status',     installed ? 'Instalado' : 'Disponível'],
        ].forEach(([ic, label, val]) => {
            const f = document.createElement('div');
            f.className = 'pkg-detail-field';
            f.innerHTML = `<label><i class="fa-solid ${ic}" style="margin-right:5px;color:#818cf8;"></i>${label}</label>`;
            const span = document.createElement('span');
            span.textContent = val;
            f.appendChild(span);
            gridEl.appendChild(f);
        });
        body.appendChild(gridEl);

        // Links externos
        if (url || repo) {
            const links = document.createElement('div');
            links.className = 'pkg-detail-links';
            if (url) {
                const a = document.createElement('a');
                a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
                a.className = 'pkg-detail-link';
                a.innerHTML = '<i class="fa-solid fa-box-open"></i> Ver no Packagist';
                links.appendChild(a);
            }
            if (repo) {
                const a = document.createElement('a');
                a.href = repo; a.target = '_blank'; a.rel = 'noopener noreferrer';
                a.className = 'pkg-detail-link';
                a.innerHTML = '<i class="fa-brands fa-github"></i> Repositório';
                links.appendChild(a);
            }
            body.appendChild(links);
        }

        // Botões de ação
        const actions = document.createElement('div');
        actions.className = 'pkg-detail-actions';

        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn ghost';
        cancelBtn.style.cssText = 'font-size:1rem;padding:12px 20px;';
        cancelBtn.textContent = 'Fechar';
        cancelBtn.addEventListener('click', () => closeModal('detail-modal'));
        actions.appendChild(cancelBtn);

        if (!installed) {
            const installBtn = document.createElement('button');
            installBtn.className = 'btn';
            installBtn.style.cssText = 'background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;font-size:1rem;padding:12px 22px;border:none;';
            installBtn.innerHTML = '<i class="fa-solid fa-download"></i> Instalar';
            installBtn.addEventListener('click', () => { closeModal('detail-modal'); triggerInstall(name, null); });
            actions.appendChild(installBtn);
        } else {
            const removeBtn = document.createElement('button');
            removeBtn.className = 'btn';
            removeBtn.style.cssText = 'background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.2);font-size:1rem;padding:12px 22px;';
            removeBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Remover';
            removeBtn.addEventListener('click', () => { closeModal('detail-modal'); triggerUninstall(name, null); });
            actions.appendChild(removeBtn);
        }
        body.appendChild(actions);
        openModal('detail-modal');
    }

    // ── Ações ─────────────────────────────────────────────────────────────
    function triggerInstall(pkgName, btn) {
        currentPkg    = pkgName;
        currentAction = 'install';
        currentBtn    = btn;
        document.getElementById('install-message').textContent =
            `Deseja instalar o módulo "${shortName(pkgName)}"?`;
        openModal('install-modal');
    }

    function triggerUninstall(pkgName, btn) {
        currentPkg    = pkgName;
        currentAction = 'uninstall';
        currentBtn    = btn;
        document.getElementById('confirm-message').textContent =
            `Tem certeza que deseja remover o módulo "${shortName(pkgName)}"? Esta ação não pode ser desfeita.`;
        openModal('confirm-modal');
    }

    // ── Render ────────────────────────────────────────────────────────────
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

        grid.querySelectorAll('.pkg-card').forEach(cardEl => {
            const pkgName = cardEl.getAttribute('data-pkg-name');
            const pkg = pkgs.find(p => p.name === pkgName);
            cardEl.addEventListener('click', (e) => {
                if (e.target.closest('button') || e.target.closest('a')) return;
                if (pkg) openDetailModal(pkg);
            });
        });

        grid.querySelectorAll('button[data-pkg]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const pkgName = btn.getAttribute('data-pkg');
                const action  = btn.getAttribute('data-action');
                if (action === 'uninstall') triggerUninstall(pkgName, btn);
                else triggerInstall(pkgName, btn);
            });
        });
    }

    // ── Fetch ─────────────────────────────────────────────────────────────
    async function fetchPkgs(query) {
        try {
            const res  = await fetch('/api/system/marketplace?q=' + encodeURIComponent(query ?? ''), {
                credentials: 'same-origin',
            });
            const data = await res.json();
            return data.results || [];
        } catch {
            return [];
        }
    }

    // ── Executar ação ─────────────────────────────────────────────────────
    async function executeAction() {
        closeModal('confirm-modal');
        closeModal('install-modal');
        if (!currentPkg || !currentAction) return;

        const btn = currentBtn;
        if (btn) {
            btn.disabled = true;
            const label = currentAction === 'install' ? 'Instalando...' : 'Removendo...';
            btn.textContent = '';
            const ic = document.createElement('i');
            ic.className = 'fa-solid fa-spinner fa-spin';
            ic.setAttribute('aria-hidden', 'true');
            btn.appendChild(ic);
            btn.appendChild(document.createTextNode(' ' + label));
        }

        try {
            const endpoint = currentAction === 'install'
                ? '/api/system/modules/install'
                : '/api/system/modules/uninstall';

            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
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
                document.getElementById('error-message').textContent =
                    out.message || 'Ocorreu um erro inesperado. Tente novamente.';
                openModal('error-modal');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '';
                    const ic2 = document.createElement('i');
                    ic2.className = currentAction === 'install' ? 'fa-solid fa-download' : 'fa-solid fa-trash';
                    ic2.setAttribute('aria-hidden', 'true');
                    btn.appendChild(ic2);
                    btn.appendChild(document.createTextNode(currentAction === 'install' ? ' Instalar' : ' Remover'));
                }
            }
        } catch {
            document.getElementById('error-message').textContent = 'Erro de conexão. Verifique sua internet e tente novamente.';
            openModal('error-modal');
            if (btn) btn.disabled = false;
        }
    }

    document.getElementById('confirm-btn').addEventListener('click', executeAction);
    document.getElementById('install-confirm-btn').addEventListener('click', executeAction);

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

    setLoading();
    fetchPkgs('').then(renderPkgs);
})();
