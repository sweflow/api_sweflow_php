// ── Trusted Types — política registrada em trusted-types-policy.js ──────────
// Carregado antes deste script via <script nonce="..."> nas views PHP.
// Permite innerHTML com HTML sanitizado via esc() e bloqueia XSS externo.

// ── XSS protection helper ─────────────────────────────────────────────────
// Use esc() em TODOS os dados vindos da API antes de inserir em innerHTML.
function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#x27;')
        .replace(/\//g, '&#x2F;')
        // Line/paragraph separators — podem quebrar strings JS em contextos inline
        .replace(/\u2028/g, '&#x2028;')
        .replace(/\u2029/g, '&#x2029;');
}

// ── DOM helpers — sem innerHTML para conteúdo simples ────────────────────
/**
 * Define o conteúdo de um botão com ícone Font Awesome + texto.
 * Usa createElement em vez de innerHTML — compatível com Trusted Types sem política.
 * @param {HTMLElement} el  - o botão
 * @param {string} icon     - classe FA, ex: 'fa-solid fa-check'
 * @param {string} text     - texto visível
 */
function setBtn(el, icon, text) {
    if (!el) return;
    el.textContent = '';
    const i = document.createElement('i');
    i.className = icon;
    el.appendChild(i);
    el.appendChild(document.createTextNode(' ' + text));
}

/**
 * Cria um elemento <p> de estado (loading/erro) sem innerHTML.
 */
function makeStatusP(text, color) {
    const p = document.createElement('p');
    p.style.cssText = `color:${color};text-align:center;padding:32px;`;
    p.textContent = text;
    return p;
}

window.onload = function () {
    const dbConnection = document.getElementById('db-connection');
    const dbMeta = document.getElementById('db-meta');
    const serverStatus = document.getElementById('server-status');
    const serverMeta = document.getElementById('server-meta');
    const usersTotal = document.getElementById('users-total');
    const modulesList = document.getElementById('modules-list');
    const routesList = document.getElementById('routes-list');
    const modulesToggleList = document.getElementById('modules-toggle-list');
    const protectedModules = ['Auth', 'Usuario'];
    let moduleState = {};
    let emailModuleEnabled = false;
    let authRequireEmailVerification = false;
    const disableModal = document.getElementById('disable-modal');
    const disableModalName = document.getElementById('disable-modal-name');
    const disableModalText = document.getElementById('disable-modal-text');
    const disableConfirm = document.getElementById('disable-confirm');
    const disableCancel = document.getElementById('disable-cancel');
    const disableClose = document.getElementById('disable-close');
    const protectedModal = document.getElementById('protected-modal');
    const protectedModalName = document.getElementById('protected-modal-name');
    const protectedModalClose = document.getElementById('protected-modal-close');
    const protectedModalOk = document.getElementById('protected-modal-ok');
    const emailDisabledModal = document.getElementById('email-disabled-modal');
    const emailDisabledClose = document.getElementById('email-disabled-close');
    const emailDisabledOk = document.getElementById('email-disabled-ok');
    const logoutBtn = document.getElementById('logout-btn');
    const emailModal = document.getElementById('email-modal');
    const openEmailModalBtn = document.getElementById('open-email-modal');
    const emailClose = document.getElementById('email-close');
    const emailCancel = document.getElementById('email-cancel');
    const emailForm = document.getElementById('email-form');
    const emailTo = document.getElementById('email-to');
    const emailSubject = document.getElementById('email-subject');
    const emailLogo = document.getElementById('email-logo');
    const emailEditor = document.getElementById('email-editor');
    const emailPreview = document.getElementById('email-preview');

    /** Insere HTML no editor/preview sanitizando via DOMPurify. */
    function setEditorHtml(el, html) {
        if (!el) return;
        if (!html) { el.innerHTML = ''; return; }
        el.innerHTML = window.DOMPurify
            ? DOMPurify.sanitize(html, {
                ALLOWED_TAGS: ['div','span','p','br','b','i','u','strong','em','h1','h2','h3','h4','h5','h6',
                               'ul','ol','li','table','thead','tbody','tr','th','td','img','a','hr','blockquote'],
                ALLOWED_ATTR: ['class','style','href','src','alt','width','height','target','rel'],
                FORBID_ATTR:  ['onerror','onload','onclick','onmouseover','onfocus','onblur'],
                ALLOW_DATA_ATTR: false,
              })
            : '';
    }
    const emailPreviewBtn = document.getElementById('email-preview-btn');
    const emailFullscreenBtn = document.getElementById('email-fullscreen-btn');
    const emailToolbar = document.getElementById('email-toolbar');
    const emailFontSize = document.getElementById('email-font-size');
    const emailFontColor = document.getElementById('email-font-color');
    const emailBgColor = document.getElementById('email-bg-color');
    const emailFeedback = document.getElementById('email-feedback');
    const emailSend = document.getElementById('email-send');
    const authVerifyToggle = document.getElementById('require-email-verification');
    const authVerifyTag = document.getElementById('auth-verify-tag');
    const linkModal = document.getElementById('link-modal');
    const linkUrl = document.getElementById('link-url');
    const linkConfirm = document.getElementById('link-confirm');
    const linkCancel = document.getElementById('link-cancel');
    const linkClose = document.getElementById('link-close');
    const imageModal = document.getElementById('image-modal');
    const imageUrl = document.getElementById('image-url');
    const imageConfirm = document.getElementById('image-confirm');
    const imageCancel = document.getElementById('image-cancel');
    const imageClose = document.getElementById('image-close');
    let imagePopover = null;
    let imagePopoverTarget = null;
    let savedSelection = null;

    function askDisable(moduleName) {
        return new Promise((resolve) => {
            if (!disableModal) {
                // fallback seguro sem confirm() nativo
                resolve(true);
                return;
            }

            disableModalName.textContent = moduleName;
            disableModalText.innerHTML = `Tem certeza que deseja desabilitar o módulo <strong>${esc(moduleName)}</strong>?\nTodas as rotas e serviços desse módulo ficarão indisponíveis.`;

            const cleanup = () => {
                disableModal.classList.remove('show');
                disableConfirm.onclick = null;
                disableCancel.onclick = null;
                disableClose.onclick = null;
            };

            const confirmHandler = () => { cleanup(); resolve(true); };
            const cancelHandler = () => { cleanup(); resolve(false); };

            disableConfirm.onclick = confirmHandler;
            disableCancel.onclick = cancelHandler;
            disableClose.onclick = cancelHandler;

            // No overlay click-to-close — only buttons close this modal

            requestAnimationFrame(() => disableModal.classList.add('show'));
        });
    }

    const enableModal     = document.getElementById('enable-modal');
    const enableModalName = document.getElementById('enable-modal-name');
    const enableModalText = document.getElementById('enable-modal-text');
    const enableConfirm   = document.getElementById('enable-confirm');
    const enableCancel    = document.getElementById('enable-cancel');
    const enableClose     = document.getElementById('enable-close');

    function askEnable(moduleName) {
        return new Promise((resolve) => {
            if (!enableModal) {
                // fallback seguro sem confirm() nativo
                resolve(true);
                return;
            }

            enableModalName.textContent = moduleName;
            enableModalText.textContent = `Tem certeza que deseja ativar o módulo "${moduleName}"? As rotas e serviços desse módulo ficarão disponíveis imediatamente.`;

            const cleanup = () => {
                enableModal.classList.remove('show');
                enableConfirm.onclick = null;
                enableCancel.onclick  = null;
                enableClose.onclick   = null;
            };

            const confirmHandler = () => { cleanup(); resolve(true); };
            const cancelHandler  = () => { cleanup(); resolve(false); };

            enableConfirm.onclick = confirmHandler;
            enableCancel.onclick  = cancelHandler;
            enableClose.onclick   = cancelHandler;

            requestAnimationFrame(() => enableModal.classList.add('show'));
        });
    }

    function askAuthVerifyEnable() {
        return new Promise((resolve) => {
            const modal   = document.getElementById('auth-verify-enable-modal');
            const confirm = document.getElementById('auth-verify-enable-confirm');
            const cancel  = document.getElementById('auth-verify-enable-cancel');
            const close   = document.getElementById('auth-verify-enable-close');
            if (!modal) { resolve(false); return; }

            const cleanup = () => {
                modal.classList.remove('show');
                confirm.onclick = null;
                cancel.onclick  = null;
                close.onclick   = null;
            };
            confirm.onclick = () => { cleanup(); resolve(true); };
            cancel.onclick  = () => { cleanup(); resolve(false); };
            close.onclick   = () => { cleanup(); resolve(false); };
            requestAnimationFrame(() => modal.classList.add('show'));
        });
    }

    function askAuthVerifyDisable() {
        return new Promise((resolve) => {
            const modal   = document.getElementById('auth-verify-disable-modal');
            const confirm = document.getElementById('auth-verify-disable-confirm');
            const cancel  = document.getElementById('auth-verify-disable-cancel');
            const close   = document.getElementById('auth-verify-disable-close');
            if (!modal) { resolve(false); return; }

            const cleanup = () => {
                modal.classList.remove('show');
                confirm.onclick = null;
                cancel.onclick  = null;
                close.onclick   = null;
            };
            confirm.onclick = () => { cleanup(); resolve(true); };
            cancel.onclick  = () => { cleanup(); resolve(false); };
            close.onclick   = () => { cleanup(); resolve(false); };
            requestAnimationFrame(() => modal.classList.add('show'));
        });
    }

    function showProtectedModal(moduleName) {
        if (!protectedModal) {
            showErrorModal(`O módulo "${moduleName}" é essencial e não pode ser desabilitado.`, 'Módulo essencial');
            return;
        }
        protectedModalName.textContent = moduleName;

        const cleanup = () => {
            protectedModal.classList.remove('show');
            protectedModalOk.onclick = null;
            protectedModalClose.onclick = null;
        };

        const closeHandler = () => cleanup();

        protectedModalOk.onclick = closeHandler;
        protectedModalClose.onclick = closeHandler;
        // No overlay click-to-close

        requestAnimationFrame(() => protectedModal.classList.add('show'));
    }

    // ── Reusable error modal (replaces all alert() calls) ────────────────
    function showErrorModal(message, title = 'Erro', action = null) {
        const modal   = document.getElementById('error-modal');
        const titleEl = document.getElementById('error-modal-title');
        const msgEl   = document.getElementById('error-modal-message');
        const okBtn   = document.getElementById('error-modal-ok');
        const closeBtn = document.getElementById('error-modal-close');

        if (!modal) { console.error(message); return; }

        if (titleEl) titleEl.textContent = title;
        if (msgEl)   msgEl.textContent   = message;

        // Botão de ação extra (ex: "Ir para Marketplace")
        let actionBtn = modal.querySelector('.error-modal-action');
        if (action && action.label && action.href) {
            if (!actionBtn) {
                actionBtn = document.createElement('a');
                actionBtn.className = 'btn primary error-modal-action';
                actionBtn.style.marginRight = '8px';
                if (okBtn && okBtn.parentNode) okBtn.parentNode.insertBefore(actionBtn, okBtn);
            }
            actionBtn.textContent = action.label;
            actionBtn.href = action.href;
            actionBtn.style.display = 'inline-flex';
        } else if (actionBtn) {
            actionBtn.style.display = 'none';
        }

        const close = () => { modal.classList.remove('show'); modal.style.zIndex = ''; };
        if (okBtn)    okBtn.onclick    = close;
        if (closeBtn) closeBtn.onclick = close;
        modal.style.zIndex = '3000';
        requestAnimationFrame(() => modal.classList.add('show'));
    }

    function showEmailDisabledModal() {
        const modal    = document.getElementById('email-disabled-modal');
        const okBtn    = document.getElementById('email-disabled-ok');
        const closeBtn = document.getElementById('email-disabled-close');

        if (!modal) {
            showErrorModal('O módulo de E-mail está desabilitado. Habilite em "Funcionalidades" para usar os envios.', 'Módulo desabilitado');
            return;
        }

        const close = () => {
            modal.classList.remove('show');
            modal.style.zIndex = '';
        };

        // Re-wire every time to avoid stale handlers
        if (okBtn)    okBtn.onclick    = close;
        if (closeBtn) closeBtn.onclick = close;

        // Overlay click
        // No overlay click-to-close — only X button closes this modal

        // Elevate above any open modal (z-index 2000 → 3000)
        modal.style.zIndex = '3000';
        requestAnimationFrame(() => modal.classList.add('show'));
    }

    function renderModules(modules) {
        if (!modulesList) return;
        
        if (!modules || modules.length === 0) {
            modulesList.innerHTML = '<div class="muted" style="grid-column: 1/-1; text-align: center; padding: 40px; color: #95a5a6;">Nenhum módulo encontrado.</div>';
            return;
        }

        modulesList.innerHTML = modules.map(mod => {
            const isEnabled = mod.enabled !== false;
            const isProtected = mod.protected || ['Auth', 'Usuario'].includes(mod.name);
            const statusClass = isEnabled ? 'active' : 'inactive';
            const statusText = isEnabled ? 'Ativo' : 'Inativo';
            let cardStatusClass = isProtected ? 'status-system' : (isEnabled ? 'status-active' : 'status-inactive');

            let iconClass = 'fa-cube';
            const nameLower = (mod.name || mod.nome || '').toLowerCase();
            if (nameLower.includes('auth')) iconClass = 'fa-shield-halved';
            else if (nameLower.includes('user') || nameLower.includes('usuario')) iconClass = 'fa-users';
            else if (nameLower.includes('email')) iconClass = 'fa-envelope';
            else if (nameLower.includes('payment') || nameLower.includes('pagamento')) iconClass = 'fa-credit-card';
            else if (nameLower.includes('report') || nameLower.includes('relatorio')) iconClass = 'fa-chart-pie';
            else if (nameLower.includes('plugin')) iconClass = 'fa-plug';

            const modName = esc(mod.name ?? mod.nome ?? '');
            const modNameRaw = mod.name ?? mod.nome ?? '';
            let actionElement = '';
            if (!isProtected) {
                const btnClass = isEnabled ? 'toggle-on' : 'toggle-off';
                const btnIcon  = isEnabled ? 'fa-power-off' : 'fa-play';
                const btnText  = isEnabled ? 'Desativar' : 'Ativar';
                // data-module-name armazena o nome original sem escape — lido pelo event listener
                actionElement = `<button class="module-btn ${btnClass}" data-toggle-module data-module-name="${modName}"><i class="fa-solid ${btnIcon}"></i> ${btnText}</button>`;
            } else {
                actionElement = `<span style="font-size:0.85rem;color:#95a5a6;font-style:italic;"><i class="fa-solid fa-lock"></i> Protegido</span>`;
            }

            const routeCount = (mod.routes || []).length;
            const routeText  = routeCount === 1 ? 'rota' : 'rotas';
            const modDesc    = esc(mod.description || 'Sem descrição disponível para este módulo.');
            const modVersion = esc(mod.version || '1.0.0');

            // Badge/select de conexão de banco — clicável para alterar
            const conn = mod.connection || 'auto';
            const connColor = conn === 'modules' ? '#818cf8' : conn === 'core' ? '#4ade80' : '#94a3b8';
            const connBadge = `<select class="module-conn-select" data-module-conn="${esc(modNameRaw)}"
                style="font-size:0.78rem;font-weight:700;color:${connColor};background:transparent;border:1px solid ${connColor}33;border-radius:6px;padding:2px 6px;cursor:pointer;outline:none;font-family:inherit;">
                <option value="core"    ${conn === 'core'    ? 'selected' : ''}>DB (core)</option>
                <option value="modules" ${conn === 'modules' ? 'selected' : ''}>DB2 (modules)</option>
                <option value="auto"    ${conn === 'auto'    ? 'selected' : ''}>auto</option>
            </select>`;

            return `
            <div class="module-card ${cardStatusClass}">
                <div class="module-header">
                    <div class="module-info">
                        <div class="module-icon"><i class="fa-solid ${iconClass}"></i></div>
                        <div class="module-meta">
                            <h3 class="module-title">${modName}</h3>
                            <span class="module-version">v${modVersion}</span>
                        </div>
                    </div>
                    <span class="module-badge ${isProtected ? 'system' : statusClass}">${statusText}</span>
                </div>
                <div class="module-description" title="${modDesc}">${modDesc}</div>
                <div class="module-stats">
                    <div class="stat-item"><i class="fa-solid fa-route"></i> ${routeCount} ${routeText}</div>
                    <div class="stat-item">${isProtected ? '<i class="fa-solid fa-lock"></i> Core' : '<i class="fa-solid fa-puzzle-piece"></i> Extensão'}</div>
                    <div class="stat-item">${connBadge}</div>
                </div>
                <div class="module-footer">${actionElement}</div>
            </div>`;
        }).join('');

        // Event listeners nos botões de toggle — usa dataset para evitar HTML entity corruption
        modulesList.querySelectorAll('[data-toggle-module]').forEach(btn => {
            btn.addEventListener('click', () => {
                window.toggleModule(btn.dataset.moduleName);
            });
        });

        // Event listeners nos selects de conexão de banco
        modulesList.querySelectorAll('.module-conn-select').forEach(sel => {
            sel.addEventListener('change', async (e) => {
                e.stopPropagation();
                const name = sel.dataset.moduleConn;
                const conn = sel.value;
                const prev = sel.dataset.prev || sel.querySelector('[selected]')?.value || 'core';
                sel.dataset.prev = conn;

                try {
                    const res  = await fetch('/api/modules/connection', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ name, connection: conn }),
                    });
                    const data = await res.json();
                    if (!res.ok) {
                        showErrorModal(data.error || 'Erro ao alterar conexão.', 'Erro');
                        sel.value = prev; // reverte
                        sel.dataset.prev = prev;
                        return;
                    }
                    // Confirma que o backend retornou o valor correto
                    if (data.connection && data.connection !== conn) {
                        sel.value = data.connection;
                        sel.dataset.prev = data.connection;
                    }
                    // Atualiza cor do select
                    const color = conn === 'modules' ? '#818cf8' : conn === 'core' ? '#4ade80' : '#94a3b8';
                    sel.style.color       = color;
                    sel.style.borderColor = color + '33';
                } catch {
                    showErrorModal('Erro de conexão.', 'Erro');
                    sel.value = prev;
                    sel.dataset.prev = prev;
                }
            });
            // Guarda valor inicial para rollback
            sel.dataset.prev = sel.value;
        });
    }

    // Expose toggleModule globally
    window.toggleModule = async function(name) {
        if (protectedModules.includes(name)) {
            showProtectedModal(name);
            return;
        }

        const currentlyEnabled = moduleState[name] ?? true;

        // Pede confirmação via modal — desativar ou ativar
        if (currentlyEnabled) {
            const confirmed = await askDisable(name);
            if (!confirmed) return;
        } else {
            const confirmed = await askEnable(name);
            if (!confirmed) return;
        }

        try {
            const res = await fetch('/api/modules/toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ name, enabled: !currentlyEnabled })
            });
            const data = await res.json();
            if (data.enabled !== undefined) {
                await fetchMetricsFull();
                await fetchModulesState();
                await loadCapabilities();
            } else {
                showErrorModal(data.error || 'Erro desconhecido ao alterar status.', 'Erro ao alterar módulo');
            }
        } catch (e) {
            showErrorModal('Erro de conexão ao tentar alterar o módulo.', 'Erro de conexão');
        }
    };

    function renderRoutes(modules) {
        if (!routesList) return;
        const withRoutes = modules.filter(m => Array.isArray(m.routes) && m.routes.length > 0);

        // Preserva quais módulos estão expandidos antes de re-renderizar
        const expandedMods = new Set();
        routesList.querySelectorAll('.rt-module-toggle[aria-expanded="true"]').forEach(btn => {
            expandedMods.add(btn.dataset.mod);
        });

        if (!withRoutes.length) {
            routesList.innerHTML = '<p style="color:#475569;text-align:center;padding:24px;">Nenhuma rota disponível.</p>';
            return;
        }

        const methodMeta = {
            GET:    { color: '#22c55e', bg: 'rgba(34,197,94,0.12)',    label: 'GET'    },
            POST:   { color: '#60a5fa', bg: 'rgba(96,165,250,0.12)',   label: 'POST'   },
            PUT:    { color: '#f59e0b', bg: 'rgba(245,158,11,0.12)',   label: 'PUT'    },
            PATCH:  { color: '#a78bfa', bg: 'rgba(167,139,250,0.12)', label: 'PATCH'  },
            DELETE: { color: '#f87171', bg: 'rgba(248,113,113,0.12)', label: 'DELETE' },
        };

        // Armazena dados para o modal
        window._routeData = {};

        let html = '';
        withRoutes.forEach(mod => {
            const modKey = (mod.name ?? mod.nome).replace(/\W/g, '');
            const isDisabled = mod.enabled === false;
            const disabledBadge = isDisabled
                ? '<span class="rt-badge rt-badge-private" style="margin-left:8px;"><i class="fa-solid fa-power-off"></i> Desativado</span>'
                : '';

            let routesHtml = '';
            mod.routes.forEach((route, idx) => {
                const m = methodMeta[route.method] || { color: '#94a3b8', bg: 'rgba(148,163,184,0.1)', label: route.method };
                const priv = route.tipo === 'privada' || route.protected;
                const hasFields = Array.isArray(route.fields) && route.fields.length > 0;
                const routeId = `route-${modKey}-${idx}`;
                window._routeData[routeId] = { ...route, moduleName: mod.name ?? mod.nome };

                routesHtml += `<div class="rt-row" data-route-id="${routeId}" role="button" tabindex="0"
                                    aria-label="Ver detalhes de ${route.method} ${route.uri}">
                    <span class="rt-method" style="color:${m.color};background:${m.bg}">${m.label}</span>
                    <span class="rt-uri">${route.uri}</span>
                    <div class="rt-badges">
                        ${priv
                            ? '<span class="rt-badge rt-badge-private"><i class="fa-solid fa-lock"></i> Privada</span>'
                            : '<span class="rt-badge rt-badge-public"><i class="fa-solid fa-unlock"></i> Pública</span>'}
                        ${hasFields ? '<span class="rt-badge rt-badge-fields"><i class="fa-solid fa-list"></i> Campos</span>' : ''}
                    </div>
                    <i class="fa-solid fa-chevron-right rt-row-arrow"></i>
                </div>`;
            });

            html += `
            <div class="rt-module">
                <button type="button" class="rt-module-header rt-module-toggle" data-mod="${modKey}"
                        aria-expanded="false" aria-controls="rt-body-${modKey}">
                    <span class="rt-module-name">
                        <i class="fa-solid fa-layer-group"></i> ${mod.name ?? mod.nome}
                        ${disabledBadge}
                    </span>
                    <span style="display:flex;align-items:center;gap:10px;">
                        <span class="rt-module-count">${mod.routes.length} rota${mod.routes.length !== 1 ? 's' : ''}</span>
                        <i class="fa-solid fa-chevron-down rt-toggle-icon" style="transition:transform .2s;"></i>
                    </span>
                </button>
                <div class="rt-list" id="rt-body-${modKey}" style="display:none;">
                    ${routesHtml}
                </div>
            </div>`;
        });

        routesList.innerHTML = html;

        // Restaura o estado de expansão que existia antes do re-render
        withRoutes.forEach(mod => {
            const modKey = (mod.name ?? mod.nome).replace(/\W/g, '');
            const btn  = routesList.querySelector(`.rt-module-toggle[data-mod="${modKey}"]`);
            const body = document.getElementById(`rt-body-${modKey}`);
            const icon = btn?.querySelector('.rt-toggle-icon');
            if (btn && body && icon && expandedMods.has(modKey)) {
                btn.setAttribute('aria-expanded', 'true');
                body.style.display = '';
                icon.style.transform = 'rotate(180deg)';
            }
        });

        // Toggle expand/collapse por módulo
        routesList.querySelectorAll('.rt-module-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const body = document.getElementById(`rt-body-${btn.dataset.mod}`);
                const icon = btn.querySelector('.rt-toggle-icon');
                const expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', String(!expanded));
                body.style.display = expanded ? 'none' : '';
                icon.style.transform = expanded ? '' : 'rotate(180deg)';
            });
        });

        // Eventos de clique nas rotas
        routesList.querySelectorAll('.rt-row').forEach(row => {
            const open = () => openRouteModal(window._routeData[row.dataset.routeId]);
            row.addEventListener('click', open);
            row.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
        });
    }

    // ── Modal de detalhes da rota ────────────────────────────────────
    function openRouteModal(route) {
        if (!route) return;

        const methodMeta = {
            GET:    { color: '#22c55e', bg: 'rgba(34,197,94,0.12)'    },
            POST:   { color: '#60a5fa', bg: 'rgba(96,165,250,0.12)'   },
            PUT:    { color: '#f59e0b', bg: 'rgba(245,158,11,0.12)'   },
            PATCH:  { color: '#a78bfa', bg: 'rgba(167,139,250,0.12)' },
            DELETE: { color: '#f87171', bg: 'rgba(248,113,113,0.12)' },
        };
        const m    = methodMeta[route.method] || { color: '#94a3b8', bg: 'rgba(148,163,184,0.1)' };
        const priv = route.tipo === 'privada' || route.protected;

        const authLabels = {
            admin:     { icon: 'fa-user-shield', label: 'Admin obrigatório', color: '#f87171' },
            jwt:       { icon: 'fa-key',         label: 'JWT obrigatório',   color: '#f59e0b' },
            cookie:    { icon: 'fa-cookie',      label: 'Cookie de sessão',  color: '#a78bfa' },
            api_token: { icon: 'fa-plug',        label: 'API Token',         color: '#60a5fa' },
            none:      { icon: 'fa-unlock',      label: 'Pública',           color: '#22c55e' },
        };
        const auth = authLabels[route.auth] || authLabels.none;

        const typeIcons = {
            email: 'fa-envelope', password: 'fa-lock', uuid: 'fa-fingerprint',
            phone: 'fa-phone', url: 'fa-link', date: 'fa-calendar',
            boolean: 'fa-toggle-on', enum: 'fa-list-check', integer: 'fa-hashtag', string: 'fa-font',
        };
        const inLabels = { path: 'Path', query: 'Query', body: 'Body' };
        const inColors = {
            path:  { color: '#f59e0b', bg: 'rgba(245,158,11,0.12)'  },
            query: { color: '#a78bfa', bg: 'rgba(167,139,250,0.12)' },
            body:  { color: '#60a5fa', bg: 'rgba(96,165,250,0.12)'  },
        };

        const fields = Array.isArray(route.fields) ? route.fields : [];
        const bodyFields  = fields.filter(f => f.in === 'body');
        const pathFields  = fields.filter(f => f.in === 'path');
        const queryFields = fields.filter(f => f.in === 'query');

        const renderFieldRow = (f) => {
            const ic  = inColors[f.in] || inColors.body;
            const ico = typeIcons[f.type] || 'fa-font';
            return `<div class="rd-field-row">
                <span class="rd-field-in" style="color:${ic.color};background:${ic.bg}">${inLabels[f.in] || f.in}</span>
                <span class="rd-field-name"><i class="fa-solid ${ico}"></i> ${f.name}</span>
                <span class="rd-field-type">${f.type}</span>
                ${f.required ? '<span class="rd-field-req">obrigatório</span>' : '<span class="rd-field-opt">opcional</span>'}
            </div>`;
        };

        // Gera exemplo JSON para o body (estilo Postman/Thunder Client)
        const buildBodyJson = (bFields) => {
            const obj = {};
            bFields.forEach(f => {
                const examples = {
                    string: `"valor"`, integer: 0, boolean: false,
                    email: `"[email]"`, password: `"[senha]"`, uuid: `"[uuid]"`,
                    phone: `"[telefone]"`, url: `"https://exemplo.com"`,
                    date: `"2024-01-01"`, enum: `"opcao"`,
                };
                obj[f.name] = examples[f.type] !== undefined ? examples[f.type] : `"valor"`;
            });
            // Formata como JSON com syntax highlight
            const lines = ['{'];
            const entries = Object.entries(obj);
            entries.forEach(([k, v], i) => {
                const comma = i < entries.length - 1 ? ',' : '';
                const valStr = typeof v === 'string' && !v.startsWith('"') ? JSON.stringify(v) : String(v);
                const isStr = String(v).startsWith('"');
                const keyHtml = `<span class="json-key">"${k}"</span>`;
                const valHtml = isStr
                    ? `<span class="json-str">${valStr}</span>`
                    : `<span class="json-val">${valStr}</span>`;
                lines.push(`  ${keyHtml}: ${valHtml}${comma}`);
            });
            lines.push('}');
            return lines.join('\n');
        };

        const renderBodySection = (bFields) => {
            if (!bFields.length) return '';
            const jsonHtml = buildBodyJson(bFields);
            const jsonRaw = JSON.stringify(
                Object.fromEntries(bFields.map(f => {
                    const ex = { string:'valor', integer:0, boolean:false, email:'[email]', password:'[senha]', uuid:'[uuid]', phone:'[telefone]', url:'https://exemplo.com', date:'2024-01-01', enum:'opcao' };
                    return [f.name, ex[f.type] !== undefined ? ex[f.type] : 'valor'];
                })),
                null, 2
            );
            return `<div class="rd-section">
                <div class="rd-section-title">
                    <i class="fa-solid fa-code"></i> Body (${route.method})
                    <span class="rd-json-badge">JSON</span>
                    <button class="rd-copy-json-btn" data-json='${jsonRaw.replace(/'/g,"&#39;")}' title="Copiar JSON">
                        <i class="fa-solid fa-copy"></i> Copiar
                    </button>
                </div>
                <div class="rd-json-block"><pre class="rd-json-pre">${jsonHtml}</pre></div>
            </div>`;
        };

        const fieldsHtml = fields.length
            ? `<div class="rd-section">
                <div class="rd-section-title"><i class="fa-solid fa-list-check"></i> Campos detectados</div>
                <div class="rd-fields">${fields.map(renderFieldRow).join('')}</div>
               </div>`
            : ((['POST','PUT','PATCH','DELETE'].includes(route.method))
                ? `<div class="rd-section"><p class="rd-empty"><i class="fa-solid fa-circle-info"></i> Nenhum campo detectado automaticamente para esta rota.</p></div>`
                : '');

        const descHtml = route.description
            ? `<p class="rd-desc">${esc(route.description)}</p>` : '';

        const overlay = document.getElementById('route-detail-modal');
        const body    = document.getElementById('route-detail-body');
        if (!overlay || !body) return;

        body.innerHTML = `
            <div class="rd-header">
                <span class="rd-method" style="color:${m.color};background:${m.bg}">${esc(route.method)}</span>
                <code class="rd-uri">${esc(route.uri)}</code>
                <button class="rd-copy-btn" id="rd-copy" title="Copiar caminho">
                    <i class="fa-solid fa-copy"></i> Copiar
                </button>
            </div>
            ${descHtml}
            <div class="rd-meta-row">
                <span class="rd-meta-item" style="color:${auth.color}">
                    <i class="fa-solid ${auth.icon}"></i> ${auth.label}
                </span>
                <span class="rd-meta-item" style="color:${priv ? '#f87171' : '#22c55e'}">
                    <i class="fa-solid ${priv ? 'fa-lock' : 'fa-unlock'}"></i> ${priv ? 'Privada' : 'Pública'}
                </span>
                <span class="rd-meta-item" style="color:#94a3b8">
                    <i class="fa-solid fa-layer-group"></i> ${esc(route.moduleName)}
                </span>
            </div>
            ${pathFields.length  ? `<div class="rd-section"><div class="rd-section-title"><i class="fa-solid fa-route"></i> Parâmetros de rota</div><div class="rd-fields">${pathFields.map(renderFieldRow).join('')}</div></div>` : ''}
            ${queryFields.length ? `<div class="rd-section"><div class="rd-section-title"><i class="fa-solid fa-magnifying-glass"></i> Query params</div><div class="rd-fields">${queryFields.map(renderFieldRow).join('')}</div></div>` : ''}
            ${bodyFields.length  ? renderBodySection(bodyFields) : ''}
            ${!pathFields.length && !queryFields.length && !bodyFields.length ? fieldsHtml : ''}
        `;

        // Botão copiar URI
        document.getElementById('rd-copy')?.addEventListener('click', () => {
            const btn = document.getElementById('rd-copy');
            const copyDone = () => {
                if (btn) { setBtn(btn, 'fa-solid fa-check', 'Copiado!'); setTimeout(() => setBtn(btn, 'fa-solid fa-copy', 'Copiar'), 1800); }
            };
            navigator.clipboard.writeText(route.uri).then(copyDone).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = route.uri; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                copyDone();
            });
        });

        // Botão copiar JSON do body
        body.querySelectorAll('.rd-copy-json-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const json = btn.getAttribute('data-json');
                const copyDone = () => {
                    setBtn(btn, 'fa-solid fa-check', 'Copiado!');
                    setTimeout(() => setBtn(btn, 'fa-solid fa-copy', 'Copiar'), 1800);
                };
                navigator.clipboard.writeText(json).then(copyDone).catch(() => {
                    const ta = document.createElement('textarea');
                    ta.value = json; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                    copyDone();
                });
            });
        });

        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');
    }

    async function loadCapabilities() {
        const el = document.getElementById('capabilities-list');
        if (!el) return;
        el.textContent = 'Carregando...';
        try {
            const res = await fetch('/api/capabilities', { credentials: 'same-origin' });
            const data = await res.json();
            const items = data.items || [];
            if (items.length === 0) {
                el.innerHTML = '<div class="muted">Nenhuma capacidade detectada.</div>';
                return;
            }
            const rows = items.map(it => {
                // Inclui o provider ativo nas opções mesmo que não venha via plugin.json
                const allProviders = [...new Set([...(it.providers || []), ...(it.active ? [it.active] : [])])];
                const options = allProviders.map(p => `<option value="${p}" ${it.active === p ? 'selected' : ''}>${p}</option>`).join('');
                const noneOption = `<option value="" ${!it.active ? 'selected' : ''}>-- Selecione --</option>`;

                // Verifica se o provedor ativo pertence a um módulo desativado
                const activeProvider = it.active || '';
                // Tenta mapear o provider para um nome de módulo (ex: "Email" de "vupi.us/email")
                const providerModuleKey = Object.keys(moduleState).find(k =>
                    activeProvider.toLowerCase().includes(k.toLowerCase())
                );
                const providerDisabled = providerModuleKey !== undefined && moduleState[providerModuleKey] === false;

                const disabledBadge = providerDisabled
                    ? `<span class="toggle-tag" style="background:#fdeaea;color:#b3261e;margin-left:6px;">
                           <i class="fa-solid fa-power-off"></i> Módulo desativado
                       </span>`
                    : '';

                return `
                <div class="toggle-card ${providerDisabled ? 'cap-module-disabled' : ''}">
                    <div class="toggle-info">
                        <span class="toggle-name">${it.capability}</span>
                        <span class="toggle-tag">Ativo: ${it.active || 'nenhum'}</span>
                        ${disabledBadge}
                    </div>
                    <div class="cap-select-group">
                        <label class="cap-select-label"><i class="fa-solid fa-plug"></i> Provedor</label>
                        <div class="cap-select-wrap">
                            <select data-capability="${it.capability}" class="capability-select"
                                    ${providerDisabled ? 'disabled title="Habilite o módulo para alterar o provedor"' : ''}>
                                ${noneOption}
                                ${options}
                            </select>
                        </div>
                    </div>
                </div>`;

            }).join('');
            el.innerHTML = rows;
            el.querySelectorAll('select.capability-select:not([disabled])').forEach(sel => {
                sel.addEventListener('change', async () => {
                    const cap = sel.getAttribute('data-capability');
                    const plugin = sel.value;
                    sel.disabled = true;
                    try {
                        const res = await fetch('/api/capabilities/provider', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'same-origin',
                            body: JSON.stringify({ capability: cap, plugin })
                        });
                        const body = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            showErrorModal(body.error || body.message || 'Falha ao definir provedor.', 'Erro');
                        } else {
                            await loadCapabilities();
                        }
                    } catch (e) {
                        showErrorModal('Erro ao definir provedor de capacidade.', 'Erro');
                    } finally {
                        sel.disabled = false;
                    }
                });
            });
        } catch (e) {
            el.innerHTML = '<div class="muted">Erro ao carregar capacidades.</div>';
        }
    }

    async function loadMigrations() {
        const el = document.getElementById('migrations-list');
        if (!el) return;
        el.innerHTML = '<span class="dash-loading">Carregando...</span>';
        try {
            const res  = await fetch('/api/system/migrations/status', { credentials: 'same-origin' });
            const data = await res.json();
            if (!res.ok) { el.innerHTML = '<div class="muted">Erro ao carregar migrations.</div>'; return; }

            const migs = data.migrations || { core: [], modules: [] };
            const conns = ['core', 'modules'];
            let html = '';

            for (const conn of conns) {
                const items = migs[conn] || [];
                if (!items.length) continue;

                const connColor = conn === 'core' ? '#4ade80' : '#818cf8';
                const connLabel = conn === 'core' ? 'DB (core)' : 'DB2 (modules)';
                html += `<div class="mig-group">
                    <div class="mig-group-header">
                        <i class="fa-solid fa-database" style="color:${connColor}"></i>
                        <span style="color:${connColor};font-weight:700;">${connLabel}</span>
                        <span class="mig-count">${items.length} migration${items.length !== 1 ? 's' : ''}</span>
                    </div>
                    <div class="mig-rows">`;

                for (const m of items) {
                    const isDone    = m.status === 'done';
                    const isChanged = m.changed;
                    const icon  = isChanged ? 'fa-triangle-exclamation' : (isDone ? 'fa-circle-check' : 'fa-circle');
                    const color = isChanged ? '#f59e0b' : (isDone ? '#4ade80' : '#94a3b8');
                    const label = isChanged ? 'alterada' : (isDone ? 'executada' : 'pendente');
                    const warn  = isChanged
                        ? `<span class="mig-warn">⚠ Crie uma nova migration para alterar o schema</span>`
                        : '';
                    html += `<div class="mig-row ${isChanged ? 'mig-changed' : (isDone ? 'mig-done' : 'mig-pending')}">
                        <i class="fa-solid ${icon}" style="color:${color};flex-shrink:0;"></i>
                        <span class="mig-name">${esc(m.module)}/<strong>${esc(m.name)}</strong></span>
                        <span class="mig-status" style="color:${color}">${label}</span>
                        ${warn}
                    </div>`;
                }
                html += `</div></div>`;
            }

            if (!html) {
                html = '<div class="muted" style="padding:24px;text-align:center;">Nenhuma migration encontrada.</div>';
            }
            el.innerHTML = html;
        } catch (e) {
            el.innerHTML = '<div class="muted">Erro ao carregar migrations.</div>';
        }
    }

    // Botão de refresh
    document.getElementById('migrations-refresh-btn')?.addEventListener('click', loadMigrations);

    function openEmailModal() {
        if (!emailModal) return;
        emailModal.classList.add('show');
        if (emailEditor) emailEditor.focus();
    }

    function closeEmailModal() {
        if (!emailModal) return;
        emailModal.classList.remove('show');
        if (emailFeedback) {
            emailFeedback.textContent = '';
            emailFeedback.className = 'email-feedback';
        }
        if (emailForm) emailForm.reset();
        // Restore color inputs to valid defaults (form.reset sets them to "" which is invalid)
        if (emailFontColor) emailFontColor.value = '#000000';
        if (emailBgColor)   emailBgColor.value   = '#ffffff';
        // contenteditable não é limpo pelo form.reset()
        if (emailEditor) emailEditor.innerHTML = '';
        if (emailPreview) {
            emailPreview.innerHTML = '';
            emailPreview.setAttribute('hidden', 'hidden');
            emailEditor?.removeAttribute('hidden');
            if (emailPreviewBtn) emailPreviewBtn.classList.remove('active');
        }
        if (emailSend) {
            emailSend.disabled = false;
            setBtn(emailSend, 'fa-solid fa-paper-plane', 'Enviar');
        }
    }

    function togglePreview() {
        if (!emailPreview || !emailEditor) return;
        const isHidden = emailPreview.hasAttribute('hidden');
        if (isHidden) {
            emailPreview.innerHTML = emailEditor.innerHTML;
            emailPreview.removeAttribute('hidden');
            emailEditor.setAttribute('hidden', 'hidden');
            emailPreviewBtn.classList.add('active');
        } else {
            emailPreview.setAttribute('hidden', 'hidden');
            emailEditor.removeAttribute('hidden');
            emailPreviewBtn.classList.remove('active');
        }
    }

    function toggleFullscreen() {
        if (!emailModal) return;
        emailModal.querySelector('.email-modal')?.classList.toggle('fullscreen');
    }

    function storeSelection() {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (emailEditor && emailEditor.contains(range.commonAncestorContainer)) {
            savedSelection = range.cloneRange();
        }
    }

    function restoreSelection() {
        if (!savedSelection) return;
        const sel = window.getSelection();
        if (!sel) return;
        sel.removeAllRanges();
        sel.addRange(savedSelection);
    }

    function openLinkModal() {
        if (!linkModal) return;
        if (linkUrl) linkUrl.value = '';
        linkModal.classList.add('show');
        requestAnimationFrame(() => linkUrl?.focus());
    }

    function closeLinkModal() {
        if (!linkModal) return;
        linkModal.classList.remove('show');
    }

    function openImageModal() {
        if (!imageModal) return;
        if (imageUrl) imageUrl.value = '';
        imageModal.classList.add('show');
        requestAnimationFrame(() => imageUrl?.focus());
    }

    function closeImageModal() {
        if (!imageModal) return;
        imageModal.classList.remove('show');
    }

    function handleToolbarClick(e) {
        const btn = e.target.closest('button');
        if (!btn || !emailEditor) return;
        e.preventDefault();
        const cmd = btn.getAttribute('data-cmd');
        const value = btn.getAttribute('data-value') || null;
        if (!cmd) return;
        if (cmd.startsWith('align-')) {
            const map = {
                'align-left': 'justifyLeft',
                'align-center': 'justifyCenter',
                'align-right': 'justifyRight',
                'align-justify': 'justifyFull',
            };
            const exec = map[cmd];
            if (exec) document.execCommand(exec, false, null);
            return;
        }
        if (cmd === 'createLink') {
            storeSelection();
            openLinkModal();
            return;
        }
        if (cmd === 'insertImage') {
            storeSelection();
            openImageModal();
            return;
        }
        if (cmd === 'formatBlock' && value) {
            document.execCommand('formatBlock', false, value);
            return;
        }
        document.execCommand(cmd, false, value);
    }

    function insertImageWithDefaults(url) {
        if (!emailEditor) return;
        restoreSelection();
        const range = savedSelection ? savedSelection.cloneRange() : null;
        if (!range) {
            document.execCommand('insertImage', false, url);
            return;
        }

        const img = document.createElement('img');
        // Valida URL antes de inserir imagem no editor
        const safeImgUrl = sanitizeAvatarUrl(url);
        if (!safeImgUrl) return;
        img.src = safeImgUrl;
        img.alt = '';
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
        img.style.display = 'inline-block';
        img.style.margin = '6px 8px';

        range.deleteContents();
        range.insertNode(img);

        const sel = window.getSelection();
        if (sel) {
            sel.removeAllRanges();
            const afterRange = document.createRange();
            afterRange.setStartAfter(img);
            afterRange.setEndAfter(img);
            sel.addRange(afterRange);
            savedSelection = afterRange.cloneRange();
        }
    }

    function ensureImagePopover() {
        if (imagePopover) return imagePopover;
        const pop = document.createElement('div');
        pop.className = 'image-popover';
        pop.innerHTML = `
            <div class="image-popover-row">
                <label>Tamanho</label>
                <input type="range" min="40" max="1200" step="10" class="image-size-range" />
                <input type="number" min="40" max="1200" step="10" class="image-size-number" aria-label="Largura em pixels" />
                <button type="button" class="btn ghost image-reset">Reset</button>
            </div>
            <div class="image-popover-hint">Use os botões de alinhamento existentes para centralizar ou alinhar.</div>
        `;
        document.body.appendChild(pop);
        imagePopover = pop;
        return pop;
    }

    function hideImagePopover() {
        if (imagePopover) {
            imagePopover.classList.remove('show');
            imagePopoverTarget = null;
        }
    }

    function positionImagePopover(img) {
        const pop = ensureImagePopover();
        // Garante que o popover está visível antes de medir offsetHeight
        pop.style.visibility = 'hidden';
        pop.style.opacity = '0';
        pop.classList.add('show');

        const rect = img.getBoundingClientRect();
        const popH = pop.offsetHeight || 80;
        const popW = pop.offsetWidth  || 260;

        let top  = window.scrollY + rect.top - popH - 8;
        let left = window.scrollX + rect.left;

        // Evita sair pela esquerda/direita
        const maxLeft = window.scrollX + window.innerWidth - popW - 8;
        left = Math.max(8, Math.min(left, maxLeft));

        // Se não cabe acima, posiciona abaixo da imagem
        if (top < window.scrollY + 8) {
            top = window.scrollY + rect.bottom + 8;
        }

        pop.style.top  = `${top}px`;
        pop.style.left = `${left}px`;
        pop.style.visibility = '';
        pop.style.opacity = '';
    }

    function showImagePopover(img) {
        const pop = ensureImagePopover();
        const rangeInput = pop.querySelector('.image-size-range');
        const numberInput = pop.querySelector('.image-size-number');
        const resetBtn = pop.querySelector('.image-reset');
        const currentWidth = parseInt(img.style.width || '', 10) || img.getBoundingClientRect().width;

        imagePopoverTarget = img;
        positionImagePopover(img);

        const applyWidth = (val) => {
            const clamped = Math.max(40, Math.min(1200, Number(val) || currentWidth));
            img.style.width = `${clamped}px`;
            img.style.height = 'auto';
            if (rangeInput) rangeInput.value = String(clamped);
            if (numberInput) numberInput.value = String(clamped);
        };

        if (rangeInput) {
            rangeInput.value = String(currentWidth);
            rangeInput.oninput = (ev) => applyWidth(ev.target.value);
        }
        if (numberInput) {
            numberInput.value = String(currentWidth);
            numberInput.oninput = (ev) => applyWidth(ev.target.value);
        }
        if (resetBtn) {
            resetBtn.onclick = () => {
                img.style.width = '';
                img.style.height = 'auto';
                if (rangeInput) rangeInput.value = '';
                if (numberInput) numberInput.value = '';
            };
        }
    }

    function applyFontSizePx(px) {
        if (!emailEditor || !px) return;
        document.execCommand('fontSize', false, '7');
        emailEditor.querySelectorAll('font[size="7"]').forEach(node => {
            node.removeAttribute('size');
            node.style.fontSize = `${px}px`;
            node.style.lineHeight = '1.4';
        });
    }

    function handleFontSizeChange() {
        if (!emailFontSize) return;
        const v = Number(emailFontSize.value);
        if (!v) return;
        const sizeMap = {
            12: 13, // 12x requested, slightly larger for readability
            14: 17,
            18: 20,
            22: 24,
            28: 32,
            36: 40
        };
        const targetPx = sizeMap[v] ?? v;
        applyFontSizePx(targetPx);
    }

    function handleFontColorChange() {
        const v = emailFontColor?.value;
        if (v) document.execCommand('foreColor', false, v);
    }

    function handleBgColorChange() {
        const v = emailBgColor?.value;
        if (v) document.execCommand('hiliteColor', false, v);
    }

    function extractEmailsFromText(text) {
        const parts = (text || '').split(/[,;\n\s]+/);
        // Regex sem backtracking: classes de caracteres específicas evitam ReDoS
        const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return parts
            .map(p => p.trim())
            .filter(p => p !== '' && emailRe.test(p));
    }

    async function submitEmail(e) {
        e.preventDefault();
        if (!emailForm || !emailEditor || !emailSubject) return;

        const recipients = extractEmailsFromText(emailTo?.value || '').map(e => ({ email: e, name: e }));
        const rawHtml = emailEditor.innerHTML.trim();
        const payload = {
            recipients,
            subject: emailSubject.value.trim(),
            logo_url: emailLogo?.value.trim() || '',
            // Sanitiza o HTML do editor antes de enviar — previne Stored XSS
            html: window.DOMPurify ? window.DOMPurify.sanitize(rawHtml, { USE_PROFILES: { html: true } }) : rawHtml,
        };

        if (!payload.recipients.length || !payload.subject || !payload.html) {
            if (emailFeedback) {
                emailFeedback.textContent = 'Informe destinatários, assunto e conteúdo.';
                emailFeedback.className = 'email-feedback error';
            }
            return;
        }

        if (emailSend) {
            emailSend.disabled = true;
            emailSend.textContent = 'Enviando...';
        }

        try {
            const res = await fetch('/api/email/custom', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });
            const body = await res.json();
            if (body.module_disabled) {
                closeEmailModal();
                showEmailDisabledModal();
                return;
            }
            if (!res.ok) {
                throw new Error(body.error || body.message || 'Falha ao enviar e-mail');
            }
            if (emailFeedback) {
                emailFeedback.textContent = 'E-mail enviado com sucesso.';
                emailFeedback.className = 'email-feedback success';
            }
            setTimeout(closeEmailModal, 800);
        } catch (err) {
            if (emailFeedback) {
                emailFeedback.textContent = err.message;
                emailFeedback.className = 'email-feedback error';
            }
        } finally {
            if (emailSend) {
                emailSend.disabled = false;
                setBtn(emailSend, 'fa-solid fa-paper-plane', 'Enviar');
            }
        }
    }

    async function handleLogout(e) {
        if (e) e.preventDefault();
        try {
            const res = await fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin' });
            // ignore body; best-effort clear cookie server-side
        } catch (err) {
            // ignore
        }
        // Clear client-side tokens if stored
        try {
            localStorage.removeItem('access_token');
            localStorage.removeItem('refresh_token');
            localStorage.removeItem('hasAuthSession');
        } catch (_) {}
        window.location.href = '/';
    }

    function renderFeatureToggles(modules) {
        if (!modulesToggleList) return;

        // Ordena alfabeticamente para garantir ordem estável
        const sorted = [...modules].sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));

        // Atualiza moduleState
        sorted.forEach(mod => { moduleState[mod.name] = mod.enabled !== false; });

        // Se já existe o mesmo conjunto de módulos renderizados, só atualiza estado (sem recriar DOM)
        const existing = modulesToggleList.querySelectorAll('[data-module]');
        const existingNames = [...existing].map(el => el.getAttribute('data-module'));
        const newNames = sorted.map(m => m.name);
        const sameSet = existingNames.length === newNames.length && newNames.every((n, i) => n === existingNames[i]);

        if (sameSet) {
            existing.forEach(input => {
                const name = input.getAttribute('data-module');
                const enabled = moduleState[name] ?? true;
                input.checked = enabled;
                const tag = input.closest('.toggle-card')?.querySelector('.toggle-tag');
                if (tag) tag.textContent = enabled ? 'Ativo' : 'Inativo';
            });
            updateEmailCardState();
            fetchAuthPolicy();
            return;
        }

        // Primeira renderização ou conjunto de módulos mudou
        modulesToggleList.innerHTML = sorted.map(mod => {
            const enabled = mod.enabled !== false;
            const modName = esc(mod.name);
            return `
                <div class="toggle-card">
                    <div class="toggle-info">
                        <span class="toggle-name">${modName}</span>
                        <span class="toggle-tag">${enabled ? 'Ativo' : 'Inativo'}</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" data-module="${modName}" ${enabled ? 'checked' : ''} />
                        <span class="slider"></span>
                    </label>
                </div>`;
        }).join('');

        modulesToggleList.querySelectorAll('input[type="checkbox"]').forEach(input => {
            input.addEventListener('change', async (e) => {
                const name = e.target.getAttribute('data-module');
                const enabled = e.target.checked;
                if (!enabled && protectedModules.includes(name)) {
                    showProtectedModal(name);
                    e.target.checked = true;
                    return;
                }
                if (!enabled) {
                    const confirmDisable = await askDisable(name);
                    if (!confirmDisable) {
                        e.target.checked = true;
                        return;
                    }
                }
                try {
                    const res = await fetch('/api/modules/toggle', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ name, enabled })
                    });
                    const body = await res.json();
                    if (!res.ok) {
                        throw new Error(body.error || body.message || 'Erro ao atualizar módulo');
                    }
                    await fetchMetricsFull();
                    await fetchModulesState();
                    await loadCapabilities();
                } catch (err) {
                    e.target.checked = !enabled; // rollback
                    showErrorModal(err.message || 'Erro ao atualizar módulo.', 'Erro');
                }
            });
        });

        updateEmailCardState();
        fetchAuthPolicy();
    }

    async function fetchModulesState() {
        try {
            const res = await fetch('/api/modules/state', { credentials: 'same-origin' });
            if (res.status === 401 || res.status === 403) {
                redirectToLogin();
                return;
            }
            const data = await res.json();
            const modules = data.modules || [];
            renderFeatureToggles(modules);
        } catch (err) {
            // silencioso
        }
    }

    function renderMetrics(data, { fullRender = true } = {}) {
        const status = data.status || {};
        const db = data.database || {};
        const usuarios = data.usuarios || {};
        const modules = data.modules || [];

        if (dbConnection) {
            const conectado = db.conectado === true;
            dbConnection.textContent = conectado ? 'Conectado' : 'Desconectado';
            dbConnection.className = conectado ? 'metric-value success' : 'metric-value danger';
        }
        if (dbMeta) {
            const driver = db.database?.driver ?? null;
            dbMeta.textContent = db.conectado
                ? (driver ? `Driver: ${driver}` : 'Conexão ativa')
                : 'Verifique se o banco está rodando';
        }

        if (serverStatus) {
            serverStatus.textContent = 'On-line';
            serverStatus.className = 'metric-value success';
        }
        if (serverMeta) {
            const meta = `env=${status.env ?? '-'} • debug=${status.debug ?? '-'}`;
            serverMeta.textContent = meta;
        }

        if (usersTotal) {
            usersTotal.textContent = usuarios.total ?? '--';
        }

        // fullRender=false no polling — evita recriar DOM de módulos e rotas
        // desnecessariamente, preservando estado da UI (cards expandidos, scroll, foco)
        if (fullRender) {
            renderModules(modules);
            renderRoutes(modules);
        }
        renderFeatureToggles(modules);
    }

    function updateEmailCardState() {
        const installed = 'Email' in moduleState;
        emailModuleEnabled = installed && Boolean(moduleState['Email']);
        const statePill        = document.getElementById('email-module-state');
        const openEmailModalBtn = document.getElementById('open-email-modal');
        const openHistoryBtn   = document.getElementById('open-email-history');
        // Cobre todos os botões de e-mail na página
        const allEmailBtns = document.querySelectorAll(
            '#open-email-modal, #open-email-modal2, #open-email-history, #open-email-modal-hero'
        );
        if (!statePill || !openEmailModalBtn) return;

        if (emailModuleEnabled) {
            statePill.textContent = 'Habilitado';
            statePill.style.backgroundColor = '';
            statePill.style.color = '';
            statePill.className = 'dash-badge';
            allEmailBtns.forEach(btn => {
                btn.classList.remove('disabled');
                btn.disabled = false;
                btn.removeAttribute('title');
                btn.style.pointerEvents = '';
            });
        } else {
            const label = installed ? 'Desabilitado' : 'Não instalado';
            const tip   = installed
                ? 'Módulo de E-mail desabilitado. Habilite em "Funcionalidades" para usar.'
                : 'Módulo de E-mail não instalado. Instale pelo Marketplace.';
            statePill.textContent = label;
            statePill.style.backgroundColor = 'rgba(248,113,113,0.12)';
            statePill.style.color = '#f87171';
            statePill.style.borderColor = 'rgba(248,113,113,0.25)';
            allEmailBtns.forEach(btn => {
                btn.classList.add('disabled');
                btn.disabled = true;
                btn.title = tip;
                btn.style.pointerEvents = 'none';
            });
        }

        updateAuthVerifyUI(authRequireEmailVerification, false);
    }

    function handleUnauthorized(status) {
        if (status === 401 || status === 403) {
            redirectToLogin();
            return true;
        }
        return false;
    }

    // Redireciona para login de forma segura — evita loops e múltiplos redirects
    let _redirecting = false;
    function redirectToLogin() {
        if (_redirecting) return;
        _redirecting = true;
        window.location.replace('/');
    }

    function updateAuthVerifyUI(state, loading = false) {
        if (!authVerifyToggle || !authVerifyTag) return;
        const disabledByModule = !emailModuleEnabled;
        const emailNotInstalled = !('Email' in moduleState);
        const effectiveState = disabledByModule ? false : state;
        const marketplaceLink = document.getElementById('auth-verify-marketplace-link');
        const switchLabel = authVerifyToggle.closest('label.switch');

        authVerifyToggle.checked = effectiveState;
        authVerifyToggle.disabled = loading || disabledByModule;

        // Overlay no switch para capturar cliques quando desabilitado
        if (switchLabel) {
            let overlay = switchLabel.querySelector('.switch-disabled-overlay');
            if (disabledByModule && !loading) {
                if (!overlay) {
                    overlay = document.createElement('span');
                    overlay.className = 'switch-disabled-overlay';
                    overlay.style.cssText = 'position:absolute;inset:0;cursor:not-allowed;z-index:1;';
                    switchLabel.style.position = 'relative';
                    switchLabel.appendChild(overlay);
                }
                overlay.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (emailNotInstalled) {
                        showErrorModal(
                            'O módulo de E-mail não está instalado. Instale-o pelo Marketplace para usar esta funcionalidade.',
                            'Módulo não instalado',
                            { label: 'Ir para Marketplace', href: '/modules/marketplace' }
                        );
                    } else {
                        showErrorModal(
                            'O módulo de E-mail está desabilitado. Habilite-o em "Funcionalidades" para usar esta opção.',
                            'Módulo desabilitado'
                        );
                    }
                };
            } else if (overlay) {
                overlay.remove();
            }
        }

        // Mostra/esconde link do marketplace
        if (marketplaceLink) {
            marketplaceLink.style.display = emailNotInstalled ? 'inline-flex' : 'none';
        }

        if (loading) {
            authVerifyTag.textContent = 'Sincronizando...';
            authVerifyTag.style.backgroundColor = '#fff3cd';
            authVerifyTag.style.color = '#8a6d3b';
            return;
        }
        if (emailNotInstalled) {
            authVerifyTag.textContent = 'Módulo E-mail não instalado';
            authVerifyTag.style.backgroundColor = '#fdeaea';
            authVerifyTag.style.color = '#b3261e';
            return;
        }
        if (disabledByModule) {
            authVerifyTag.textContent = 'Módulo E-mail desabilitado';
            authVerifyTag.style.backgroundColor = '#fff3cd';
            authVerifyTag.style.color = '#8a6d3b';
            return;
        }
        if (effectiveState) {
            authVerifyTag.textContent = 'Verificação exigida';
            authVerifyTag.style.backgroundColor = '#e5f7ee';
            authVerifyTag.style.color = '#0f7b3b';
        } else {
            authVerifyTag.textContent = 'Opcional';
            authVerifyTag.style.backgroundColor = '#f5f6fb';
            authVerifyTag.style.color = '#6c6c6c';
        }
    }

    async function fetchAuthPolicy() {
        if (!authVerifyToggle) return;
        if (!emailModuleEnabled) {
            updateAuthVerifyUI(false, false);
            return;
        }
        updateAuthVerifyUI(authRequireEmailVerification, true);
        try {
            const res = await fetch('/api/auth/email-verification', { credentials: 'same-origin' });
            if (handleUnauthorized(res.status)) return;
            const body = await res.json();
            if (!res.ok) throw new Error(body.error || body.message || 'Não foi possível obter a política.');
            authRequireEmailVerification = Boolean(body.require_verification ?? body.requireVerification ?? body.enabled);
            updateAuthVerifyUI(authRequireEmailVerification, false);
        } catch (err) {
            authRequireEmailVerification = false;
            updateAuthVerifyUI(authRequireEmailVerification, false);
        }
    }

    async function persistAuthPolicy(enabled) {
        if (!authVerifyToggle) return;
        const emailNotInstalled = !('Email' in moduleState);
        if (emailNotInstalled && enabled) {
            showErrorModal(
                'O módulo de E-mail não está instalado. Instale-o pelo Marketplace para usar esta funcionalidade.',
                'Módulo não instalado',
                { label: 'Ir para Marketplace', href: '/modules/marketplace' }
            );
            updateAuthVerifyUI(authRequireEmailVerification, false);
            authVerifyToggle.checked = false;
            return;
        }
        if (!emailModuleEnabled && enabled) {
            showErrorModal('Ative o módulo de E-mail para exigir verificação por e-mail.', 'Módulo desabilitado');
            updateAuthVerifyUI(authRequireEmailVerification, false);
            authVerifyToggle.checked = false;
            return;
        }
        updateAuthVerifyUI(enabled, true);
        try {
            const res = await fetch('/api/auth/email-verification', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ require_verification: enabled })
            });
            const body = await res.json();
            if (!res.ok) throw new Error(body.error || body.message || 'Erro ao salvar política.');
            authRequireEmailVerification = enabled;
            updateAuthVerifyUI(authRequireEmailVerification, false);
        } catch (err) {
            updateAuthVerifyUI(authRequireEmailVerification, false);
            if (authVerifyToggle) authVerifyToggle.checked = authRequireEmailVerification;
            showErrorModal(err.message || 'Erro ao salvar política de verificação.', 'Erro');
        }
    }

    function fetchMetrics() {
        return fetch('/api/dashboard/metrics', { credentials: 'same-origin' })
            .then(async (res) => {
                if (handleUnauthorized(res.status)) return null;
                const body = await res.json();
                if (!res.ok) {
                    throw new Error(body.message || body.error || body.erro || 'Falha ao obter métricas.');
                }
                return body;
            })
            .then(data => {
                if (data) {
                    // Polling leve: só atualiza valores numéricos, não recria DOM de módulos/rotas
                    renderMetrics(data, { fullRender: false });
                }
            })
            .catch(() => {});
    }

    // Usado após ações do usuário (toggle, install) — faz re-render completo de módulos e rotas
    function fetchMetricsFull() {
        return fetch('/api/dashboard/metrics', { credentials: 'same-origin' })
            .then(async (res) => {
                if (handleUnauthorized(res.status)) return null;
                const body = await res.json();
                if (!res.ok) throw new Error(body.message || body.error || 'Falha ao obter métricas.');
                return body;
            })
            .then(data => { if (data) renderMetrics(data, { fullRender: true }); })
            .catch(() => {});
    }


    // Inicialização: verifica sessão via API antes de carregar o resto
    (async function init() {
        // Função de fetch autenticado — usa cookie (dashboard nativo) ou Bearer token (localStorage)
        // NÃO sobrescreve window.fetch globalmente para evitar vazamento de token
        // Intercepta 401 automaticamente — qualquer resposta 401 redireciona para login
        function apiFetch(url, opts = {}) {
            const token = localStorage.getItem('dash_token') || localStorage.getItem('access_token');
            const headers = Object.assign(
                { Accept: 'application/json' },
                token ? { 'Authorization': 'Bearer ' + token } : {},
                opts.headers || {}
            );
            return fetch(url, Object.assign({}, opts, {
                headers,
                credentials: opts.credentials ?? 'same-origin',
            })).then(res => {
                if (res.status === 401) { redirectToLogin(); }
                return res;
            });
        }

        // Expõe para uso nos outros métodos do dashboard
        window._apiFetch = apiFetch;

        // Verificação periódica de sessão — detecta token expirado ou revogado
        // mesmo quando o usuário está inativo (sem fazer requests)
        setInterval(function () {
            fetch('/api/auth/me', { credentials: 'same-origin' })
                .then(function (res) {
                    if (res.status === 401) redirectToLogin();
                })
                .catch(function () {});
        }, 60000); // verifica a cada 60s

        let data = null;
        try {
            const res = await apiFetch('/api/dashboard/metrics');
            if (handleUnauthorized(res.status)) return;
            const body = await res.json();
            if (!res.ok) {
                console.error('[dashboard] /api/dashboard/metrics falhou:', res.status, body);
                return;
            }
            data = body;
        } catch (err) {
            console.error('[dashboard] Erro ao carregar métricas:', err);
            return;
        }

        if (!data) return;
        renderMetrics(data);

        apiFetch('/api/perfil')
            .then(r => r.ok ? r.json() : null)
            .then(d => {
                if (d?.usuario?.url_avatar) updateTopbarAvatar(d.usuario.url_avatar);
                const heroName = document.getElementById('hero-username');
                if (heroName && d?.usuario) {
                    const name = d.usuario.nome_completo?.split(' ')[0] || d.usuario.username || 'usuário';
                    heroName.textContent = name;
                }
            })
            .catch(() => {});

        await fetchModulesState();
        await loadCapabilities();
        await loadMigrations();

        setInterval(async () => {
            // Polling leve: atualiza apenas métricas numéricas (DB, server, usuários).
            // renderModules e renderRoutes NÃO são chamados aqui — eles só re-renderizam
            // quando o usuário executa uma ação (toggle, install, etc.), evitando
            // destruir o estado da UI (cards expandidos, scroll, foco) a cada ciclo.
            await fetchMetrics();
            await fetchModulesState();
        }, 10000);
    })();

    // ── Nav scroll shadow ─────────────────────────────────────────────
    const topbar = document.getElementById('dash-topbar');
    if (topbar) {
        window.addEventListener('scroll', () => {
            topbar.classList.toggle('scrolled', window.scrollY > 8);
        }, { passive: true });
    }

    // ── Avatar clique → abre perfil ───────────────────────────────────
    const avatarEl = document.getElementById('topbar-avatar');
    if (avatarEl) {
        avatarEl.addEventListener('click', () => {
            openModal('meu-perfil-modal');
            carregarMeuPerfil();
        });
        avatarEl.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); avatarEl.click(); }
        });
    }

    // ── Histórico de e-mails ──────────────────────────────────────────────
    const historyModal      = document.getElementById('email-history-modal');
    const historyClose      = document.getElementById('email-history-close');
    const historyList       = document.getElementById('email-history-list');
    const detailModal       = document.getElementById('email-detail-modal');
    const detailClose       = document.getElementById('email-detail-close');
    const detailBody        = document.getElementById('email-detail-body');
    const detailEdit        = document.getElementById('email-detail-edit');
    const detailResend      = document.getElementById('email-detail-resend');
    const detailDiscard     = document.getElementById('email-detail-discard');
    const detailDraftEdit   = document.getElementById('email-detail-draft-edit');
    const detailDelete      = document.getElementById('email-detail-delete');
    const deleteModal       = document.getElementById('email-delete-modal');
    const deleteClose       = document.getElementById('email-delete-close');
    const deleteCancel      = document.getElementById('email-delete-cancel');
    const deleteConfirm     = document.getElementById('email-delete-confirm');
    const openHistoryBtn    = document.getElementById('open-email-history');
    let currentHistoryId    = null;

    function fmtDate(iso) {
        if (!iso) return '--';
        const d = new Date(iso);
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    function recipientEmails(recipients) {
        if (!Array.isArray(recipients)) return String(recipients || '');
        return recipients.map(r => (typeof r === 'string' ? r : r.email || '')).filter(Boolean).join(', ');
    }

    async function loadEmailHistory(q = '') {
        if (!historyList) return;
        historyList.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:center;gap:12px;padding:40px;color:var(--text-muted,#64748b);">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:1.2rem;color:#818cf8;"></i>
                <span>Carregando histórico...</span>
            </div>`;
        try {
            const url = '/api/email/history' + (q ? '?q=' + encodeURIComponent(q) : '');
            const res = await fetch(url, { credentials: 'same-origin' });
            const data = await res.json();
            const remoteItems = data.items || [];

            // Injeta rascunhos locais no topo, filtrando pela busca se houver
            const allDrafts = getDrafts();
            const drafts = q
                ? allDrafts.filter(d =>
                    (d.subject || '').toLowerCase().includes(q.toLowerCase()) ||
                    (d.to || '').toLowerCase().includes(q.toLowerCase()) ||
                    'rascunho'.includes(q.toLowerCase()))
                : allDrafts;

            const items = [...drafts, ...remoteItems];

            if (!items.length) {
                historyList.innerHTML = `
                    <div style="text-align:center;padding:48px 24px;color:var(--text-muted,#64748b);">
                        <i class="fa-solid fa-inbox" style="font-size:2.5rem;color:#334155;margin-bottom:14px;display:block;"></i>
                        <p style="font-size:1rem;font-weight:600;margin:0 0 6px;">${q ? 'Nenhum resultado encontrado' : 'Nenhum e-mail enviado ainda'}</p>
                        <p style="font-size:0.88rem;margin:0;">${q ? `Tente outro termo de busca.` : 'Os disparos realizados aparecerão aqui.'}</p>
                    </div>`;
                return;
            }
            historyList.innerHTML = items.map(item => {
                const isDraft = item.status === 'rascunho';
                const ok      = item.status === 'enviado';
                const color   = isDraft ? '#f59e0b' : (ok ? '#4ade80' : '#f87171');
                const bg      = isDraft ? 'rgba(245,158,11,0.1)' : (ok ? 'rgba(74,222,128,0.1)' : 'rgba(248,113,113,0.1)');
                const border  = isDraft ? 'rgba(245,158,11,0.2)' : (ok ? 'rgba(74,222,128,0.2)' : 'rgba(248,113,113,0.2)');
                const icon    = isDraft ? 'fa-file-pen' : (ok ? 'fa-circle-check' : 'fa-circle-xmark');
                const label   = isDraft ? 'Rascunho' : (ok ? 'Enviado' : esc(item.status));
                const errorHint = item.error
                    ? `<div style="margin-top:6px;display:flex;align-items:center;gap:6px;font-size:0.8rem;color:#f87171;">
                           <i class="fa-solid fa-triangle-exclamation"></i>
                           <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:340px;">${esc(item.error)}</span>
                       </div>` : '';
                return `
                <div class="email-hist-card" data-id="${esc(String(item.id))}" data-draft="${isDraft ? '1' : '0'}" role="button" tabindex="0"
                     style="display:flex;align-items:center;gap:16px;padding:16px 18px;border-radius:14px;
                            border:1px solid var(--border-card,rgba(255,255,255,0.07));
                            background:var(--bg-card,rgba(255,255,255,0.03));
                            cursor:pointer;margin-bottom:10px;transition:background .15s,border-color .15s;">
                    <div style="width:42px;height:42px;border-radius:11px;background:${bg};border:1px solid ${border};
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fa-solid ${icon}" style="color:${color};font-size:1.15rem;"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:1rem;font-weight:700;color:var(--text-primary,#f1f5f9);
                                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px;">
                            ${esc(item.subject) || '<em style="opacity:.6">Sem assunto</em>'}
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="font-size:0.82rem;font-weight:700;color:${color};
                                         background:${bg};border:1px solid ${border};
                                         padding:2px 10px;border-radius:999px;">
                                <i class="fa-solid ${icon}" style="font-size:0.75em;margin-right:3px;"></i>${label}
                            </span>
                            <span style="font-size:0.82rem;color:var(--text-muted,#64748b);">
                                <i class="fa-regular fa-clock" style="margin-right:4px;"></i>${fmtDate(item.created_at)}
                            </span>
                        </div>
                        ${errorHint}
                    </div>
                    <i class="fa-solid fa-chevron-right" style="color:#475569;font-size:0.85rem;flex-shrink:0;"></i>
                </div>`;
            }).join('');

            historyList.querySelectorAll('.email-hist-card').forEach(el => {
                const open = () => openEmailDetail(el.dataset.id, el.dataset.draft === '1');
                el.addEventListener('click', open);
                el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') open(); });
                el.addEventListener('mouseenter', () => {
                    el.style.background = 'var(--bg-hover,rgba(255,255,255,0.06))';
                    el.style.borderColor = 'rgba(99,102,241,0.3)';
                });
                el.addEventListener('mouseleave', () => {
                    el.style.background = 'var(--bg-card,rgba(255,255,255,0.03))';
                    el.style.borderColor = 'var(--border-card,rgba(255,255,255,0.07))';
                });
            });
        } catch {
            historyList.innerHTML = `
                <div style="text-align:center;padding:40px;color:#f87171;">
                    <i class="fa-solid fa-circle-exclamation" style="font-size:2rem;margin-bottom:12px;display:block;"></i>
                    <p style="margin:0;font-size:0.95rem;">Erro ao carregar histórico. Tente novamente.</p>
                </div>`;
        }
    }

    async function openEmailDetail(id, isDraft = false) {
        currentHistoryId = id;
        if (detailBody) { detailBody.textContent = ''; detailBody.appendChild(makeStatusP('Carregando...', 'var(--text-muted,#64748b)')); }

        // Feedback movido para o footer — limpa ao abrir
        const detailFb = document.getElementById('detail-resend-feedback');
        if (detailFb) { detailFb.textContent = ''; detailFb.className = 'email-feedback'; }

        if (detailModal) detailModal.classList.add('show');

        // Subtítulo
        const subtitle = document.getElementById('email-detail-subtitle');
        if (subtitle) subtitle.textContent = isDraft ? 'Rascunho local' : 'Visualizando registro';

        // Ajusta botões conforme tipo
        if (detailResend)    detailResend.style.display    = isDraft ? 'none' : '';
        if (detailEdit)      detailEdit.style.display      = isDraft ? 'none' : '';
        if (detailDraftEdit) detailDraftEdit.style.display = isDraft ? '' : 'none';
        if (detailEdit)      setBtn(detailEdit, 'fa-solid fa-pen', 'Editar e reenviar');
        if (detailDelete)    detailDelete.style.display    = '';
        if (detailDiscard)   detailDiscard.style.display   = isDraft ? '' : 'none';

        if (isDraft) {
            const draft = getDrafts().find(d => d.id === id);
            if (!draft) {
                if (detailBody) { detailBody.textContent = ''; detailBody.appendChild(makeStatusP('Rascunho não encontrado.', '#f87171')); }
                return;
            }
            if (detailBody) detailBody.innerHTML = buildDetailFields({
                para:    draft.to || '',
                assunto: draft.subject || '',
                logo:    draft.logo_url || '',
                status:  '<span style="color:#f59e0b;font-weight:700;"><i class="fa-solid fa-file-pen"></i> Rascunho</span>',
                data:    fmtDate(draft.created_at),
                html:    draft.html || '',
                error:   null,
            });
            setEmailPreviewHtml(detailBody, draft.html || '');
            return;
        }

        try {
            const res  = await fetch(`/api/email/history/${id}`, { credentials: 'same-origin' });
            const item = await res.json();
            if (!res.ok) throw new Error(item.error || 'Erro ao carregar.');

            const statusOk    = item.status === 'enviado';
            const statusColor = statusOk ? '#4ade80' : '#f87171';
            const statusIcon  = statusOk ? 'fa-circle-check' : 'fa-circle-xmark';
            const statusLabel = esc(item.status);

            if (detailBody) detailBody.innerHTML = buildDetailFields({
                para:    recipientEmails(item.recipients),
                assunto: item.subject || '',
                logo:    item.logo_url || '',
                status:  `<span style="color:${statusColor};font-weight:700;"><i class="fa-solid ${statusIcon}"></i> ${statusLabel}</span>`,
                data:    fmtDate(item.created_at),
                html:    item.html || '',
                error:   item.error || null,
            });
            setEmailPreviewHtml(detailBody, item.html || '');
        } catch (err) {
            if (detailBody) { detailBody.textContent = ''; detailBody.appendChild(makeStatusP(esc(err.message), '#f87171')); }
        }
    }

    /** Popula o preview de e-mail com HTML sanitizado via DOMPurify. */
    function setEmailPreviewHtml(container, html) {
        const frame = container ? container.querySelector('.email-preview-frame') : null;
        if (!frame) return;
        if (!html) {
            const em = document.createElement('em');
            em.style.color = 'var(--text-muted,#64748b)';
            em.textContent = 'Sem conteúdo';
            frame.replaceChildren(em);
            return;
        }
        if (window.DOMPurify) {
            frame.innerHTML = DOMPurify.sanitize(html, {
                ALLOWED_TAGS: ['div','span','p','br','b','i','u','strong','em','h1','h2','h3','h4','h5','h6',
                               'ul','ol','li','table','thead','tbody','tr','th','td','img','a','hr','blockquote'],
                ALLOWED_ATTR: ['class','style','href','src','alt','width','height','target','rel'],
                FORBID_ATTR:  ['onerror','onload','onclick','onmouseover','onfocus','onblur'],
                ALLOW_DATA_ATTR: false,
            });
        } else {
            frame.textContent = html; // fallback seguro: mostra como texto
        }
    }

    function buildDetailFields({ para, assunto, logo, status, data, html, error }) {
        const fieldRow = (icon, label, content) => `
            <div class="email-field-row" style="align-items:flex-start;min-height:50px;">
                <div class="email-field-label" style="padding-top:14px;">
                    <i class="fa-solid ${icon}"></i>
                    <span>${label}</span>
                </div>
                <div style="flex:1;padding:12px 0;color:var(--text-primary,#f1f5f9);font-size:0.97rem;word-break:break-word;">${content}</div>
            </div>`;

        const errorRow = error ? `
            <div class="email-field-divider"></div>
            ${fieldRow('fa-triangle-exclamation', 'Erro', `<span style="color:#f87171;">${esc(error)}</span>`)}` : '';

        const logoRow = logo ? `
            <div class="email-field-divider"></div>
            ${fieldRow('fa-image', 'Logo', `<span style="color:var(--text-muted,#64748b);font-size:0.9rem;">${esc(logo)}</span>`)}` : '';

        return `
        <div class="email-fields" style="margin-top:16px;">
            ${fieldRow('fa-at', 'Para', para ? esc(para) : '<em style="color:var(--text-muted,#64748b);">Nenhum destinatário</em>')}
            <div class="email-field-divider"></div>
            ${fieldRow('fa-heading', 'Assunto', assunto ? esc(assunto) : '<em style="color:var(--text-muted,#64748b);">Sem assunto</em>')}
            ${logoRow}
            <div class="email-field-divider"></div>
            <div class="email-field-row">
                <div class="email-field-label"><i class="fa-solid fa-circle-info"></i><span>Status</span></div>
                <div style="flex:1;padding:12px 0;">${status}</div>
                <div style="padding:12px 0 12px 24px;font-size:0.88rem;color:var(--text-muted,#64748b);">
                    <i class="fa-regular fa-clock" style="margin-right:4px;"></i>${data}
                </div>
            </div>
            ${errorRow}
        </div>
        <div style="padding:16px 0 8px;">
            <div style="font-size:0.82rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                <i class="fa-solid fa-envelope" style="color:#818cf8;margin-right:6px;"></i>Conteúdo
            </div>
            <div class="email-preview-frame" style="max-height:280px;overflow-y:auto;pointer-events:none;user-select:text;border:1px solid var(--border-card,rgba(255,255,255,0.07));border-radius:8px;padding:12px;background:var(--bg-input,#fff);"></div>
        </div>`;
    }

    function closeEmailDetail() {
        if (detailModal) detailModal.classList.remove('show');
        currentHistoryId = null;
    }

    function openDeleteConfirm() {
        if (deleteModal) deleteModal.classList.add('show');
    }

    function closeDeleteConfirm() {
        if (deleteModal) deleteModal.classList.remove('show');
    }

    if (openHistoryBtn) {
        openHistoryBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (!emailModuleEnabled) {
                showEmailDisabledModal();
                return;
            }
            const searchEl = document.getElementById('email-history-search');
            if (searchEl) searchEl.value = '';
            loadEmailHistory();
            if (historyModal) historyModal.classList.add('show');
        });
    }
    // Fallback: event delegation in case button was added after initial parse
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('#open-email-history');
        if (!btn) return;
        e.preventDefault();
        if (!emailModuleEnabled) {
            showEmailDisabledModal();
            return;
        }
        const searchEl = document.getElementById('email-history-search');
        if (searchEl) searchEl.value = '';
        loadEmailHistory();
        const modal = document.getElementById('email-history-modal');
        if (modal) modal.classList.add('show');
    });

    // Search with debounce
    let historySearchTimer = null;
    document.getElementById('email-history-search')?.addEventListener('input', (e) => {
        clearTimeout(historySearchTimer);
        historySearchTimer = setTimeout(() => loadEmailHistory(e.target.value.trim()), 350);
    });

    if (historyClose) historyClose.addEventListener('click', () => historyModal?.classList.remove('show'));
    // Overlay clicks do NOT close modals — only X button or Cancel closes them
    if (detailClose) detailClose.addEventListener('click', closeEmailDetail);

    if (detailResend) {
        detailResend.addEventListener('click', async () => {
            if (!currentHistoryId) return;
            detailResend.disabled = true;
            setBtn(detailResend, 'fa-solid fa-spinner fa-spin', 'Reenviando...');

            const fb = document.getElementById('detail-resend-feedback');
            if (fb) { fb.textContent = ''; fb.className = 'email-feedback'; }

            try {
                const res = await fetch(`/api/email/history/${currentHistoryId}/resend`, {
                    method: 'POST', credentials: 'same-origin'
                });
                const data = await res.json();

                if (data.module_disabled) {
                    closeEmailDetail();
                    historyModal?.classList.remove('show');
                    showEmailDisabledModal();
                    loadEmailHistory();
                    return;
                }

                if (!res.ok) {
                    if (fb) { fb.textContent = data.error || 'Falha ao reenviar.'; fb.className = 'email-feedback error'; }
                    loadEmailHistory();
                    return;
                }

                if (fb) { fb.textContent = 'E-mail reenviado com sucesso.'; fb.className = 'email-feedback success'; }
                setTimeout(() => { closeEmailDetail(); loadEmailHistory(); }, 800);
            } catch (err) {
                if (fb) { fb.textContent = err.message || 'Erro de conexão.'; fb.className = 'email-feedback error'; }
            } finally {
                detailResend.disabled = false;
                setBtn(detailResend, 'fa-solid fa-rotate-right', 'Reenviar');
            }
        });
    }

    if (detailDraftEdit) {
        detailDraftEdit.addEventListener('click', () => {
            if (!currentHistoryId) return;
            const draft = getDrafts().find(d => d.id === currentHistoryId);
            if (!draft) return;
            if (emailTo)      emailTo.value         = draft.to       || '';
            if (emailSubject) emailSubject.value    = draft.subject  || '';
            if (emailLogo)    emailLogo.value       = draft.logo_url || '';
            if (emailEditor)  setEditorHtml(emailEditor, draft.html || '');
            closeEmailDetail();
            historyModal?.classList.remove('show');
            if (!emailModuleEnabled) { showEmailDisabledModal(); return; }
            openEmailModal();
        });
    }

    if (detailEdit) {
        detailEdit.addEventListener('click', async () => {
            if (!currentHistoryId) return;

            // Rascunho local: abre modal de e-mail para editar/enviar
            if (currentHistoryId.startsWith('draft_')) {
                const draft = getDrafts().find(d => d.id === currentHistoryId);
                if (!draft) return;

                if (!draft.to || !draft.to.trim()) {
                    // Sem destinatário: abre modal para editar, com aviso
                    if (emailTo) emailTo.value = '';
                    if (emailSubject) emailSubject.value = draft.subject || '';
                    if (emailLogo) emailLogo.value = draft.logo_url || '';
                    if (emailEditor) setEditorHtml(emailEditor, draft.html || '');
                    closeEmailDetail();
                    historyModal?.classList.remove('show');
                    if (!emailModuleEnabled) { showEmailDisabledModal(); return; }
                    openEmailModal();
                    // Avisa após abrir
                    setTimeout(() => {
                        if (emailFeedback) {
                            emailFeedback.textContent = 'Informe o destinatário antes de enviar.';
                            emailFeedback.className = 'email-feedback error';
                        }
                        emailTo?.focus();
                    }, 100);
                    return;
                }

                // Tem destinatário: envia direto
                detailEdit.disabled = true;
                setBtn(detailEdit, 'fa-solid fa-spinner fa-spin', 'Enviando...');
                let fb = document.getElementById('detail-resend-feedback');
                if (fb) { fb.textContent = ''; fb.className = 'email-feedback'; }
                try {
                    const payload = {
                        to:       draft.to,
                        subject:  draft.subject || '',
                        html:     draft.html || '',
                        logo_url: draft.logo_url || '',
                    };
                    const res = await fetch('/api/email/send', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload),
                        credentials: 'same-origin',
                    });
                    const data = await res.json();
                    if (data.module_disabled) {
                        closeEmailDetail();
                        historyModal?.classList.remove('show');
                        showEmailDisabledModal();
                        return;
                    }
                    if (!res.ok) throw new Error(data.error || data.message || 'Falha ao enviar.');
                    // Remove rascunho após envio
                    saveDrafts(getDrafts().filter(d => d.id !== currentHistoryId));
                    if (fb) { fb.textContent = 'E-mail enviado com sucesso.'; fb.className = 'email-feedback success'; }
                    setTimeout(() => { closeEmailDetail(); loadEmailHistory(); }, 800);
                } catch (err) {
                    if (fb) { fb.textContent = err.message || 'Erro ao enviar.'; fb.className = 'email-feedback error'; }
                } finally {
                    detailEdit.disabled = false;
                    setBtn(detailEdit, 'fa-solid fa-paper-plane', 'Enviar e-mail');
                }
                return;
            }

            // Item do histórico remoto: comportamento original (editar e reenviar)
            try {
                const res = await fetch(`/api/email/history/${currentHistoryId}`, { credentials: 'same-origin' });
                const item = await res.json();
                if (!res.ok) throw new Error(item.error || 'Erro.');

                if (emailTo) emailTo.value = recipientEmails(item.recipients);
                if (emailSubject) emailSubject.value = item.subject || '';
                if (emailLogo) emailLogo.value = item.logo_url || '';
                if (emailEditor) setEditorHtml(emailEditor, item.html || '');

                closeEmailDetail();
                historyModal?.classList.remove('show');
                if (!emailModuleEnabled) { showEmailDisabledModal(); return; }
                openEmailModal();
            } catch (err) {
                showErrorModal(err.message || 'Erro ao carregar dados do e-mail.', 'Erro');
            }
        });
    }

    if (detailDelete) detailDelete.addEventListener('click', openDeleteConfirm);
    if (detailDiscard) {
        detailDiscard.addEventListener('click', () => {
            if (!currentHistoryId || !currentHistoryId.startsWith('draft_')) return;
            saveDrafts(getDrafts().filter(d => d.id !== currentHistoryId));
            closeEmailDetail();
            loadEmailHistory();
        });
    }
    if (deleteClose) deleteClose.addEventListener('click', closeDeleteConfirm);
    if (deleteCancel) deleteCancel.addEventListener('click', closeDeleteConfirm);
    // No overlay click-to-close for delete confirm

    if (deleteConfirm) {
        deleteConfirm.addEventListener('click', async () => {
            if (!currentHistoryId) return;
            deleteConfirm.disabled = true;
            try {
                // Rascunho local: remove do localStorage
                if (currentHistoryId.startsWith('draft_')) {
                    saveDrafts(getDrafts().filter(d => d.id !== currentHistoryId));
                    closeDeleteConfirm();
                    closeEmailDetail();
                    loadEmailHistory();
                    return;
                }
                const res = await fetch(`/api/email/history/${currentHistoryId}`, {
                    method: 'DELETE', credentials: 'same-origin'
                });
                if (!res.ok) { const d = await res.json(); throw new Error(d.error || 'Erro.'); }
                closeDeleteConfirm();
                closeEmailDetail();
                loadEmailHistory();
            } catch (err) {
                showErrorModal(err.message || 'Erro ao excluir registro.', 'Erro');
            } finally {
                deleteConfirm.disabled = false;
            }
        });
    }
    // ── fim histórico ─────────────────────────────────────────────────────

    // ── Seleção múltipla no histórico ─────────────────────────────────────
    {
        let selectMode = false;

        const bulkBar        = document.getElementById('email-bulk-bar');
        const selectAllChk   = document.getElementById('email-select-all');
        const selectAllLabel = document.getElementById('email-select-all-label');
        const selectedCount  = document.getElementById('email-selected-count');
        const bulkDeleteBtn  = document.getElementById('email-bulk-delete');
        const bulkCancelBtn  = document.getElementById('email-bulk-cancel');
        const enterSelectBtn = document.getElementById('email-enter-select-mode');
        const selectModeWrap = document.getElementById('email-select-mode-btn-wrap');
        const bulkModal      = document.getElementById('email-bulk-delete-modal');
        const bulkModalText  = document.getElementById('email-bulk-delete-text');
        const bulkModalClose = document.getElementById('email-bulk-delete-close');
        const bulkModalCancel= document.getElementById('email-bulk-delete-cancel');
        const bulkModalConfirm = document.getElementById('email-bulk-delete-confirm');

        function getChecked() {
            return [...(historyList?.querySelectorAll('.email-hist-checkbox:checked') || [])];
        }

        function updateBulkBar() {
            const checked = getChecked();
            const total   = historyList?.querySelectorAll('.email-hist-checkbox').length || 0;
            const n = checked.length;
            if (selectedCount) selectedCount.textContent = n > 0 ? `${n} selecionado${n !== 1 ? 's' : ''}` : '';
            if (bulkDeleteBtn) bulkDeleteBtn.disabled = n === 0;
            if (selectAllChk) {
                selectAllChk.checked       = n > 0 && n === total;
                selectAllChk.indeterminate = n > 0 && n < total;
            }
            if (selectAllLabel) selectAllLabel.textContent = n === total && total > 0 ? 'Desmarcar todos' : 'Marcar todos';
        }

        function enterSelectMode() {
            selectMode = true;
            if (bulkBar)        bulkBar.style.display        = 'flex';
            if (selectModeWrap) selectModeWrap.style.display = 'none';
            // Adiciona checkbox em cada card
            historyList?.querySelectorAll('.email-hist-card').forEach(card => {
                if (card.querySelector('.email-hist-checkbox')) return;
                const chk = document.createElement('input');
                chk.type      = 'checkbox';
                chk.className = 'email-hist-checkbox';
                chk.style.cssText = 'width:17px;height:17px;flex-shrink:0;cursor:pointer;accent-color:#4f46e5;margin-right:4px;';
                chk.addEventListener('change', updateBulkBar);
                // Clique no card não abre detalhe no modo seleção
                card.addEventListener('click', (e) => {
                    if (selectMode && !e.target.closest('.email-hist-checkbox')) {
                        chk.checked = !chk.checked;
                        updateBulkBar();
                    }
                }, true);
                card.insertBefore(chk, card.firstChild);
            });
            updateBulkBar();
        }

        function exitSelectMode() {
            selectMode = false;
            if (bulkBar)        bulkBar.style.display        = 'none';
            if (selectModeWrap) selectModeWrap.style.display = 'flex';
            if (selectAllChk)   selectAllChk.checked         = false;
            historyList?.querySelectorAll('.email-hist-checkbox').forEach(c => c.remove());
        }

        if (enterSelectBtn) enterSelectBtn.addEventListener('click', enterSelectMode);
        if (bulkCancelBtn)  bulkCancelBtn.addEventListener('click', exitSelectMode);

        if (selectAllChk) {
            selectAllChk.addEventListener('change', () => {
                const all = historyList?.querySelectorAll('.email-hist-checkbox') || [];
                all.forEach(c => { c.checked = selectAllChk.checked; });
                updateBulkBar();
            });
        }

        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', () => {
                const n = getChecked().length;
                if (n === 0) return;
                if (bulkModalText) bulkModalText.textContent =
                    `Tem certeza que deseja excluir ${n} registro${n !== 1 ? 's' : ''}? Esta ação não pode ser desfeita.`;
                if (bulkModal) bulkModal.classList.add('show');
            });
        }

        const closeBulkModal = () => bulkModal?.classList.remove('show');
        if (bulkModalClose)  bulkModalClose.addEventListener('click', closeBulkModal);
        if (bulkModalCancel) bulkModalCancel.addEventListener('click', closeBulkModal);

        if (bulkModalConfirm) {
            bulkModalConfirm.addEventListener('click', async () => {
                const checked = getChecked();
                if (!checked.length) return;
                bulkModalConfirm.disabled = true;
                bulkModalConfirm.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Excluindo...';

                const ids = checked.map(c => c.closest('.email-hist-card')?.dataset.id).filter(Boolean);
                const isDraft = id => id?.startsWith('draft_');

                // Remove rascunhos locais
                const draftIds = ids.filter(isDraft);
                if (draftIds.length) {
                    saveDrafts(getDrafts().filter(d => !draftIds.includes(d.id)));
                }

                // Remove registros remotos em paralelo
                const remoteIds = ids.filter(id => !isDraft(id));
                await Promise.allSettled(remoteIds.map(id =>
                    fetch(`/api/email/history/${id}`, { method: 'DELETE', credentials: 'same-origin' })
                ));

                closeBulkModal();
                exitSelectMode();
                loadEmailHistory(document.getElementById('email-history-search')?.value || '');

                bulkModalConfirm.disabled = false;
                bulkModalConfirm.innerHTML = '<i class="fa-solid fa-trash"></i> Excluir';
            });
        }

        // Ao recarregar o histórico, sai do modo seleção
        const origLoad = loadEmailHistory;
        // Garante que ao recarregar a lista, os checkboxes sejam re-adicionados se em modo seleção
        const historyObserver = new MutationObserver(() => {
            if (selectMode) {
                historyList?.querySelectorAll('.email-hist-card').forEach(card => {
                    if (card.querySelector('.email-hist-checkbox')) return;
                    const chk = document.createElement('input');
                    chk.type      = 'checkbox';
                    chk.className = 'email-hist-checkbox';
                    chk.style.cssText = 'width:17px;height:17px;flex-shrink:0;cursor:pointer;accent-color:#4f46e5;margin-right:4px;';
                    chk.addEventListener('change', updateBulkBar);
                    card.addEventListener('click', (e) => {
                        if (selectMode && !e.target.closest('.email-hist-checkbox')) {
                            chk.checked = !chk.checked;
                            updateBulkBar();
                        }
                    }, true);
                    card.insertBefore(chk, card.firstChild);
                });
                updateBulkBar();
            }
        });
        if (historyList) historyObserver.observe(historyList, { childList: true });

        // Ao fechar o modal do histórico, sai do modo seleção
        document.getElementById('email-history-close')?.addEventListener('click', exitSelectMode);
    }
    // ── fim seleção múltipla ──────────────────────────────────────────────

    if (openEmailModalBtn) {
        openEmailModalBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (!emailModuleEnabled) {
                showEmailDisabledModal();
                return;
            }
            openEmailModal();
        });
    }
    if (emailClose) emailClose.addEventListener('click', closeEmailModal);
    if (emailCancel) emailCancel.addEventListener('click', closeEmailModal);

    // ── Rascunho (salvo no histórico local) ───────────────────────────────────
    const DRAFT_KEY = 'vupi.us-email-drafts';
    const emailDraftBtn = document.getElementById('email-draft-btn');

    function getDrafts() {
        try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || '[]'); } catch (_) { return []; }
    }
    function saveDrafts(drafts) {
        try { localStorage.setItem(DRAFT_KEY, JSON.stringify(drafts)); } catch (_) {}
    }

    function saveDraft() {
        const to      = emailTo?.value.trim()      || '';
        const subject = emailSubject?.value.trim() || '';
        const rawBody = emailEditor?.innerHTML      || '';
        const body    = window.DOMPurify ? window.DOMPurify.sanitize(rawBody, { USE_PROFILES: { html: true } }) : rawBody;
        if (!to && !subject && !body) return;

        const drafts = getDrafts();
        drafts.unshift({
            id:         'draft_' + Date.now(),
            status:     'rascunho',
            to,
            subject,
            html:       body,
            logo_url:   emailLogo?.value || '',
            created_at: new Date().toISOString(),
        });
        saveDrafts(drafts);

        if (emailFeedback) {
            emailFeedback.textContent = 'Rascunho salvo no histórico.';
            emailFeedback.className = 'email-feedback success';
            setTimeout(() => { if (emailFeedback) { emailFeedback.textContent = ''; emailFeedback.className = 'email-feedback'; } }, 2500);
        }
    }

    if (emailDraftBtn) emailDraftBtn.addEventListener('click', saveDraft);
    // ── fim rascunho ──────────────────────────────────────────────────────────
    if (emailToolbar) emailToolbar.addEventListener('click', handleToolbarClick);
    if (emailEditor) {
        emailEditor.addEventListener('keyup', storeSelection);
        emailEditor.addEventListener('mouseup', storeSelection);
        emailEditor.addEventListener('mouseleave', storeSelection);
        emailEditor.addEventListener('input', storeSelection);
    }
    if (emailFontSize) emailFontSize.addEventListener('change', handleFontSizeChange);
    if (emailFontColor) emailFontColor.addEventListener('change', handleFontColorChange);
    if (emailBgColor) emailBgColor.addEventListener('change', handleBgColorChange);
    if (emailPreviewBtn) emailPreviewBtn.addEventListener('click', (e) => { e.preventDefault(); togglePreview(); });
    if (emailFullscreenBtn) emailFullscreenBtn.addEventListener('click', (e) => { e.preventDefault(); toggleFullscreen(); });
    if (emailForm) emailForm.addEventListener('submit', submitEmail);
    if (authVerifyToggle) authVerifyToggle.addEventListener('change', async (e) => {
        const enabling = e.target.checked;

        // Reverte imediatamente — só aplica após confirmação
        e.target.checked = !enabling;

        const confirmed = enabling
            ? await askAuthVerifyEnable()
            : await askAuthVerifyDisable();

        if (!confirmed) return;

        // Restaura o valor e executa
        e.target.checked = enabling;
        persistAuthPolicy(enabling);
    });

    if (linkConfirm) {
        linkConfirm.addEventListener('click', (e) => {
            e.preventDefault();
            const url = linkUrl?.value.trim();
            if (!url) return;
            restoreSelection();
            document.execCommand('createLink', false, url);
            closeLinkModal();
            emailEditor?.focus();
        });
    }
    if (linkCancel) linkCancel.addEventListener('click', (e) => { e.preventDefault(); closeLinkModal(); emailEditor?.focus(); });
    if (linkClose) linkClose.addEventListener('click', (e) => { e.preventDefault(); closeLinkModal(); emailEditor?.focus(); });
    if (linkModal) {
        linkModal.addEventListener('click', (ev) => {
            if (ev.target === linkModal) {
                closeLinkModal();
                emailEditor?.focus();
            }
        });
    }

    if (imageConfirm) {
        imageConfirm.addEventListener('click', (e) => {
            e.preventDefault();
            const url = imageUrl?.value.trim();
            if (!url) return;
            insertImageWithDefaults(url);
            closeImageModal();
            emailEditor?.focus();
        });
    }
    if (imageCancel) imageCancel.addEventListener('click', (e) => { e.preventDefault(); closeImageModal(); emailEditor?.focus(); });
    if (imageClose) imageClose.addEventListener('click', (e) => { e.preventDefault(); closeImageModal(); emailEditor?.focus(); });
    if (imageModal) {
        imageModal.addEventListener('click', (ev) => {
            if (ev.target === imageModal) {
                closeImageModal();
                emailEditor?.focus();
            }
        });
    }

    if (emailEditor) {
        emailEditor.addEventListener('click', (ev) => {
            const img = ev.target.closest('img');
            if (!img) {
                hideImagePopover();
                return;
            }
            showImagePopover(img);
        });

        document.addEventListener('click', (ev) => {
            if (!imagePopover) return;
            if (imagePopover.contains(ev.target) || ev.target.closest('img')) return;
            hideImagePopover();
        });

        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') hideImagePopover();
        });
    }

    // ── Meu Perfil / Editar / Alterar Senha / Criar Usuário ──────────────

    let perfilAtual = null; // cache do usuário logado

    function openModal(id) {
        const m = document.getElementById(id);
        if (m) { m.style.zIndex = '3000'; requestAnimationFrame(() => m.classList.add('show')); }
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) { m.classList.remove('show'); m.style.zIndex = ''; }
    }

    // ── Helpers de validação ─────────────────────────────────────────────

    function validarSenhaRegras(senha, confirmar, prefix) {
        const rules = {
            len:     senha.length >= 8,
            upper:   /[A-Z]/.test(senha),
            lower:   /[a-z]/.test(senha),
            num:     /[0-9]/.test(senha),
            special: /[^a-zA-Z0-9]/.test(senha),
            match:   senha !== '' && senha === confirmar,
        };
        Object.entries(rules).forEach(([k, ok]) => {
            const el = document.getElementById(`${prefix}-r-${k}`);
            if (!el) return;
            el.classList.toggle('ok', ok);
            const icon = el.querySelector('i');
            if (icon) {
                icon.className = ok ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
            }
        });
        return Object.values(rules).every(Boolean);
    }

    function validarUsername(val) {
        // Força lowercase
        const v = val.toLowerCase();
        if (v.length < 3) return { ok: false, msg: 'Mínimo 3 caracteres.' };
        if (/^[._]/.test(v)) return { ok: false, msg: 'Não pode iniciar com caractere especial.' };
        if (!/^[a-z0-9._]+$/.test(v)) return { ok: false, msg: 'Apenas letras minúsculas, números, ponto ou underline.' };
        const specials = (v.match(/[._]/g) || []).length;
        if (specials > 1) return { ok: false, msg: 'Apenas 1 caractere especial (. ou _) permitido.' };
        return { ok: true, msg: '' };
    }

    function forcarUsernameInput(input) {
        input.addEventListener('input', () => {
            const pos = input.selectionStart;
            // Remove maiúsculas e caracteres inválidos
            const clean = input.value.toLowerCase().replace(/[^a-z0-9._]/g, '');
            if (clean !== input.value) {
                input.value = clean;
                try { input.setSelectionRange(pos, pos); } catch (_) {}
            }
        });
    }

    let usernameCheckTimer = null;
    let emailCheckTimer = null;

    function setupUsernameCheck(inputId, feedbackId, excludeUuid = '') {
        const input = document.getElementById(inputId);
        const fb    = document.getElementById(feedbackId);
        if (!input || !fb) return;
        forcarUsernameInput(input);
        input.addEventListener('input', () => {
            clearTimeout(usernameCheckTimer);
            const val = input.value.trim();
            const v = validarUsername(val);
            if (!v.ok) {
                fb.textContent = val === '' ? '' : v.msg;
                fb.className = val === '' ? 'hint' : 'hint error';
                input.classList.remove('input-ok', 'input-error');
                return;
            }
            fb.textContent = 'Verificando...';
            fb.className = 'hint warn';
            usernameCheckTimer = setTimeout(async () => {
                try {
                    const url = `/api/usuarios/check-username?username=${encodeURIComponent(val)}${excludeUuid ? '&exclude=' + excludeUuid : ''}`;
                    const res = await fetch(url, { credentials: 'same-origin' });
                    const data = await res.json();
                    if (data.available) {
                        fb.textContent = '✔ Disponível';
                        fb.className = 'hint ok';
                        input.classList.add('input-ok');
                        input.classList.remove('input-error');
                    } else {
                        fb.textContent = '✖ Username já em uso.';
                        fb.className = 'hint error';
                        input.classList.add('input-error');
                        input.classList.remove('input-ok');
                    }
                } catch { fb.textContent = ''; fb.className = 'hint'; }
            }, 500);
        });
    }

    function setupEmailCheck(inputId, feedbackId, excludeUuid = '') {
        const input = document.getElementById(inputId);
        const fb    = document.getElementById(feedbackId);
        if (!input || !fb) return;
        input.addEventListener('input', () => {
            clearTimeout(emailCheckTimer);
            const val = input.value.trim();
            if (!val || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                fb.textContent = val ? 'E-mail inválido.' : '';
                fb.className = val ? 'hint error' : 'hint';
                input.classList.remove('input-ok', 'input-error');
                return;
            }
            fb.textContent = 'Verificando...';
            fb.className = 'hint warn';
            emailCheckTimer = setTimeout(async () => {
                try {
                    const url = `/api/usuarios/check-email?email=${encodeURIComponent(val)}${excludeUuid ? '&exclude=' + excludeUuid : ''}`;
                    const res = await fetch(url, { credentials: 'same-origin' });
                    const data = await res.json();
                    if (data.available) {
                        fb.textContent = '✔ Disponível';
                        fb.className = 'hint ok';
                        input.classList.add('input-ok');
                        input.classList.remove('input-error');
                    } else {
                        fb.textContent = '✖ E-mail já cadastrado.';
                        fb.className = 'hint error';
                        input.classList.add('input-error');
                        input.classList.remove('input-ok');
                    }
                } catch { fb.textContent = ''; fb.className = 'hint'; }
            }, 500);
        });
    }

    // ── Meu Perfil ───────────────────────────────────────────────────────

    async function carregarMeuPerfil() {
        const body = document.getElementById('meu-perfil-body');
        if (!body) return;
        body.textContent = '';
        body.appendChild(makeStatusP('Carregando...', '#888'));
        try {
            const res = await fetch('/api/perfil', { credentials: 'same-origin' });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Erro ao carregar perfil.');
            perfilAtual = data.usuario;
            renderMeuPerfil(perfilAtual);
        } catch (e) {
            body.textContent = '';
            body.appendChild(makeStatusP(e.message, '#e74c3c'));
        }
    }

    // Sanitiza URL de avatar — aceita apenas http/https, reconhecido pelo CodeQL como sanitizador
    function sanitizeAvatarUrl(url) {
        if (!url || typeof url !== 'string') return '';
        try {
            var p = new URL(url, window.location.href);
            if (p.protocol !== 'https:' && p.protocol !== 'http:') return '';
            return encodeURI(decodeURI(url));
        } catch { return ''; }
    }

    function updateHeroName(nomeCompleto, username) {
        const el = document.getElementById('hero-username');
        if (!el) return;
        el.textContent = nomeCompleto?.split(' ')[0] || username || 'usuário';
    }

    function updateTopbarAvatar(url) {
        const el = document.getElementById('topbar-avatar');
        if (!el) return;
        const safeUrl = sanitizeAvatarUrl(url);
        if (safeUrl) {
            try { localStorage.setItem('dash-avatar-url', safeUrl); } catch(_) {}
        } else {
            try { localStorage.removeItem('dash-avatar-url'); } catch(_) {}
        }
        const current = el.querySelector('img');
        if (safeUrl) {
            if (current && current.src === safeUrl) return;
            if (!current) {
                el.textContent = '';
                const img = document.createElement('img');
                img.src = safeUrl;
                img.alt = 'Avatar';
                img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
                img.onerror = () => {
                    el.textContent = '';
                    const ic = document.createElement('i');
                    ic.className = 'fa-solid fa-circle-user';
                    el.appendChild(ic);
                };
                el.appendChild(img);
            } else {
                current.src = safeUrl;
            }
        } else {
            if (!current) return;
            el.textContent = '';
            const ic = document.createElement('i');
            ic.className = 'fa-solid fa-circle-user';
            el.appendChild(ic);
        }
    }

    // Aplica avatar salvo imediatamente — sem esperar fetch
    (function () {
        try {
            const saved = localStorage.getItem('dash-avatar-url');
            if (saved) updateTopbarAvatar(saved);
        } catch(_) {}
    })();

    function renderMeuPerfil(u) {
        const body = document.getElementById('meu-perfil-body');
        if (!body || !u) return;

        // Atualiza avatar do topbar em tempo real
        updateTopbarAvatar(u.url_avatar);

        const avatarHtml = u.url_avatar
            ? `<img class="perfil-avatar" src="${esc(u.url_avatar)}" alt="Avatar" onerror="this.style.display='none'" />`
            : `<div class="perfil-avatar-placeholder"><i class="fa-solid fa-user"></i></div>`;
        const capaHtml = u.url_capa
            ? `<img class="perfil-capa" src="${esc(u.url_capa)}" alt="Capa" onerror="this.style.display='none'" />`
            : '';
        const nivelClass = esc(u.nivel_acesso || 'usuario');
        const nivelLabel = { usuario: 'Usuário', moderador: 'Moderador', admin: 'Admin', admin_system: 'Admin System' }[u.nivel_acesso] || esc(u.nivel_acesso);

        body.innerHTML = `
            ${capaHtml}
            <div class="perfil-header">
                ${avatarHtml}
                <div>
                    <div class="perfil-nome">${esc(u.nome_completo) || '--'}</div>
                    <div class="perfil-username">@${esc(u.username) || '--'}</div>
                    <span class="nivel-badge ${nivelClass}">${nivelLabel}</span>
                </div>
            </div>
            <div class="perfil-grid">
                <div class="perfil-field">
                    <label><i class="fa-solid fa-envelope"></i> E-mail</label>
                    <span>${esc(u.email) || '--'}</span>
                </div>
                <div class="perfil-field">
                    <label><i class="fa-solid fa-circle-check"></i> Status</label>
                    <span>${u.ativo ? '✔ Ativo' : '✖ Inativo'}</span>
                </div>
                <div class="perfil-field">
                    <label><i class="fa-solid fa-shield-check"></i> E-mail verificado</label>
                    <span>${u.verificado_email ? '✔ Sim' : '✖ Não'}</span>
                </div>
                <div class="perfil-field">
                    <label><i class="fa-solid fa-calendar"></i> Membro desde</label>
                    <span>${u.criado_em ? new Date(u.criado_em).toLocaleDateString('pt-BR') : '--'}</span>
                </div>
            </div>
            ${u.biografia ? `<div class="perfil-field" style="grid-column:1/-1"><label><i class="fa-solid fa-quote-left"></i> Biografia</label><span>${esc(u.biografia)}</span></div>` : ''}
        `;
    }

    // ── Editar Perfil ────────────────────────────────────────────────────

    function abrirEditarPerfil() {
        if (!perfilAtual) return;
        const u = perfilAtual;
        const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
        setVal('ep-nome', u.nome_completo);
        setVal('ep-username', u.username);
        setVal('ep-email', u.email);
        setVal('ep-avatar', u.url_avatar || '');
        setVal('ep-capa', u.url_capa || '');
        setVal('ep-bio', u.biografia || '');

        // Preview avatar/capa
        atualizarPreview('ep-avatar', 'ep-avatar-preview', 'ep-avatar-img');
        atualizarPreview('ep-capa', 'ep-capa-preview', 'ep-capa-img');

        // Feedback limpo
        ['ep-username-feedback','ep-email-feedback','ep-feedback'].forEach(id => {
            const el = document.getElementById(id);
            if (el) { el.textContent = ''; el.className = id === 'ep-feedback' ? 'login-feedback' : 'hint'; }
        });
        ['ep-username','ep-email'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.remove('input-ok','input-error');
        });

        document.getElementById('ep-senha-email-group').style.display = 'none';
        document.getElementById('ep-senha-email').value = '';

        setupUsernameCheck('ep-username', 'ep-username-feedback', u.uuid);
        setupEmailCheck('ep-email', 'ep-email-feedback', u.uuid);

        // Mostrar campo de senha quando email muda
        const emailInput = document.getElementById('ep-email');
        const senhaGroup = document.getElementById('ep-senha-email-group');
        emailInput.oninput = () => {
            const changed = emailInput.value.trim() !== u.email;
            senhaGroup.style.display = changed ? '' : 'none';
        };

        closeModal('meu-perfil-modal');
        openModal('editar-perfil-modal');
    }

    function atualizarPreview(inputId, previewId, imgId) {
        const input   = document.getElementById(inputId);
        const preview = document.getElementById(previewId);
        const img     = document.getElementById(imgId);
        if (!input || !preview || !img) return;
        const update = () => {
            const url = input.value.trim();
            if (url) {
                const safeUrl = sanitizeAvatarUrl(url);
                if (safeUrl) { img.src = safeUrl; preview.style.display = ''; }
                else { preview.style.display = 'none'; }
            } else {
                preview.style.display = 'none';
            }
        };
        update();
        input.addEventListener('input', update);
    }

    const editarPerfilForm = document.getElementById('editar-perfil-form');
    if (editarPerfilForm) {
        editarPerfilForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fb = document.getElementById('ep-feedback');
            const btn = document.getElementById('editar-perfil-save');
            if (!perfilAtual) return;

            const nome     = document.getElementById('ep-nome').value.trim();
            const username = document.getElementById('ep-username').value.trim();
            const email    = document.getElementById('ep-email').value.trim();
            const avatar   = document.getElementById('ep-avatar').value.trim();
            const capa     = document.getElementById('ep-capa').value.trim();
            const bio      = document.getElementById('ep-bio').value.trim();
            const senhaEmail = document.getElementById('ep-senha-email').value;

            // Validação username
            const uv = validarUsername(username);
            if (!uv.ok) {
                fb.textContent = uv.msg; fb.className = 'login-feedback error'; return;
            }

            btn.disabled = true;
            setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Salvando...');
            fb.textContent = ''; fb.className = 'login-feedback';

            try {
                // Atualiza perfil (sem email)
                const resP = await fetch('/api/perfil', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ nome_completo: nome, username, url_avatar: avatar, url_capa: capa, biografia: bio }),
                });
                const dataP = await resP.json();
                if (!resP.ok) throw new Error(dataP.message || 'Erro ao salvar perfil.');

                // Atualiza email se mudou
                if (email !== perfilAtual.email) {
                    if (!senhaEmail) throw new Error('Informe a senha atual para alterar o e-mail.');
                    const resE = await fetch('/api/perfil/email', {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ email, senha: senhaEmail }),
                    });
                    const dataE = await resE.json();
                    if (!resE.ok) throw new Error(dataE.message || 'Erro ao alterar e-mail.');
                }

                fb.textContent = 'Dados salvos com sucesso.';
                fb.className = 'login-feedback success';
                // Atualiza avatar e nome do hero imediatamente
                updateTopbarAvatar(avatar);
                updateHeroName(nome, username);
                setTimeout(async () => {
                    closeModal('editar-perfil-modal');
                    await carregarMeuPerfil();
                    openModal('meu-perfil-modal');
                }, 800);
            } catch (err) {
                fb.textContent = err.message;
                fb.className = 'login-feedback error';
            } finally {
                btn.disabled = false;
                setBtn(btn, 'fa-solid fa-floppy-disk', 'Salvar');
            }
        });
    }

    // ── Alterar Senha ────────────────────────────────────────────────────

    const alterarSenhaForm = document.getElementById('alterar-senha-form');
    const asSaveBtn = document.getElementById('alterar-senha-save');

    setupSenhaValidation('as-nova', 'as-confirmar', 'as', asSaveBtn);

    function setupSenhaValidation(novaId, confirmarId, prefix, saveBtn) {
        const nova      = document.getElementById(novaId);
        const confirmar = document.getElementById(confirmarId);
        if (!nova || !confirmar) return;
        const check = () => {
            const ok = validarSenhaRegras(nova.value, confirmar.value, prefix);
            if (saveBtn) saveBtn.disabled = !ok;
        };
        nova.addEventListener('input', check);
        confirmar.addEventListener('input', check);
    }

    if (alterarSenhaForm) {
        alterarSenhaForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fb  = document.getElementById('as-feedback');
            const btn = asSaveBtn;
            const atual     = document.getElementById('as-atual').value;
            const nova      = document.getElementById('as-nova').value;
            const confirmar = document.getElementById('as-confirmar').value;

            if (!validarSenhaRegras(nova, confirmar, 'as')) {
                fb.textContent = 'Corrija os erros acima.'; fb.className = 'login-feedback error'; return;
            }

            btn.disabled = true;
            setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Alterando...');
            fb.textContent = ''; fb.className = 'login-feedback';

            try {
                const res = await fetch('/api/perfil/senha', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ senha_atual: atual, nova_senha: nova }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Erro ao alterar senha.');
                fb.textContent = 'Senha alterada com sucesso.';
                fb.className = 'login-feedback success';
                setTimeout(() => {
                    closeModal('alterar-senha-modal');
                    alterarSenhaForm.reset();
                    validarSenhaRegras('', '', 'as');
                    if (asSaveBtn) asSaveBtn.disabled = true;
                }, 800);
            } catch (err) {
                fb.textContent = err.message;
                fb.className = 'login-feedback error';
            } finally {
                btn.disabled = false;
                setBtn(btn, 'fa-solid fa-key', 'Alterar senha');
            }
        });
    }

    // ── Criar Usuário ────────────────────────────────────────────────────

    const criarUsuarioForm = document.getElementById('criar-usuario-form');
    const cuSaveBtn = document.getElementById('criar-usuario-save');

    setupSenhaValidation('cu-senha', 'cu-confirmar', 'cu', cuSaveBtn);
    setupUsernameCheck('cu-username', 'cu-username-feedback');
    setupEmailCheck('cu-email', 'cu-email-feedback');

    // Habilita botão só quando senha válida E username/email ok
    function checkCriarBtn() {
        const nova      = document.getElementById('cu-senha')?.value || '';
        const confirmar = document.getElementById('cu-confirmar')?.value || '';
        const uFb = document.getElementById('cu-username-feedback');
        const eFb = document.getElementById('cu-email-feedback');
        const senhaOk    = validarSenhaRegras(nova, confirmar, 'cu');
        const usernameOk = uFb?.classList.contains('ok');
        const emailOk    = eFb?.classList.contains('ok');
        if (cuSaveBtn) cuSaveBtn.disabled = !(senhaOk && usernameOk && emailOk);
    }

    ['cu-senha','cu-confirmar','cu-username','cu-email'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', checkCriarBtn);
    });

    if (criarUsuarioForm) {
        criarUsuarioForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fb  = document.getElementById('cu-feedback');
            const btn = cuSaveBtn;
            const nome     = document.getElementById('cu-nome').value.trim();
            const username = document.getElementById('cu-username').value.trim();
            const email    = document.getElementById('cu-email').value.trim();
            const nivel    = document.getElementById('cu-nivel').value;
            const senha    = document.getElementById('cu-senha').value;
            const confirmar = document.getElementById('cu-confirmar').value;

            if (!validarSenhaRegras(senha, confirmar, 'cu')) {
                fb.textContent = 'Corrija os erros de senha.'; fb.className = 'login-feedback error'; return;
            }
            const uv = validarUsername(username);
            if (!uv.ok) {
                fb.textContent = uv.msg; fb.className = 'login-feedback error'; return;
            }

            btn.disabled = true;
            setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Criando...');
            fb.textContent = ''; fb.className = 'login-feedback';

            try {
                const res = await fetch('/api/criar/usuario', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ nome_completo: nome, username, email, senha, nivel_acesso: nivel }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Erro ao criar usuário.');
                fb.textContent = 'Usuário criado com sucesso.';
                fb.className = 'login-feedback success';
                fetchMetrics();
                // Recarrega a tabela de usuários se estiver na página de gerenciamento
                if (typeof window.reloadUsuarios === 'function') window.reloadUsuarios(1);
                setTimeout(() => {
                    closeModal('criar-usuario-modal');
                    criarUsuarioForm.reset();
                    validarSenhaRegras('', '', 'cu');
                    if (cuSaveBtn) cuSaveBtn.disabled = true;
                    ['cu-username-feedback','cu-email-feedback'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) { el.textContent = ''; el.className = 'hint'; }
                    });
                    ['cu-username','cu-email','cu-senha','cu-confirmar'].forEach(id => {
                        document.getElementById(id)?.classList.remove('input-ok','input-error');
                    });
                }, 900);
            } catch (err) {
                fb.textContent = err.message;
                fb.className = 'login-feedback error';
            } finally {
                btn.disabled = false;
                setBtn(btn, 'fa-solid fa-user-plus', 'Criar usuário');
            }
        });
    }

    // ── Event listeners dos modais de perfil ─────────────────────────────

    document.getElementById('open-meu-perfil')?.addEventListener('click', async (e) => {
        e.preventDefault();
        openModal('meu-perfil-modal');
        await carregarMeuPerfil();
    });

    document.getElementById('open-criar-usuario')?.addEventListener('click', (e) => {
        e.preventDefault();
        openModal('criar-usuario-modal');
        // Reset form
        criarUsuarioForm?.reset();
        validarSenhaRegras('', '', 'cu');
        if (cuSaveBtn) cuSaveBtn.disabled = true;
        setupSenhaValidation('cu-senha', 'cu-confirmar', 'cu', cuSaveBtn);
    });

    document.getElementById('meu-perfil-close')?.addEventListener('click', () => closeModal('meu-perfil-modal'));
    document.getElementById('meu-perfil-editar')?.addEventListener('click', abrirEditarPerfil);
    document.getElementById('meu-perfil-alterar-senha')?.addEventListener('click', () => {
        closeModal('meu-perfil-modal');
        openModal('alterar-senha-modal');
        document.getElementById('alterar-senha-form')?.reset();
        validarSenhaRegras('', '', 'as');
        if (asSaveBtn) asSaveBtn.disabled = true;
        // Wire up real-time validation now that the modal is visible
        setupSenhaValidation('as-nova', 'as-confirmar', 'as', asSaveBtn);
    });

    document.getElementById('editar-perfil-close')?.addEventListener('click', () => closeModal('editar-perfil-modal'));
    document.getElementById('editar-perfil-cancel')?.addEventListener('click', () => closeModal('editar-perfil-modal'));
    document.getElementById('alterar-senha-close')?.addEventListener('click', () => closeModal('alterar-senha-modal'));
    document.getElementById('alterar-senha-cancel')?.addEventListener('click', () => closeModal('alterar-senha-modal'));
    document.getElementById('criar-usuario-close')?.addEventListener('click', () => closeModal('criar-usuario-modal'));
    document.getElementById('criar-usuario-cancel')?.addEventListener('click', () => closeModal('criar-usuario-modal'));

    // Overlay click fecha
    // Profile modals — only X/Cancel buttons close them, no overlay click
    ['meu-perfil-modal','editar-perfil-modal','alterar-senha-modal','criar-usuario-modal'].forEach(id => {
        // Intentionally no overlay click handler
    });

    // Preview em tempo real no editar perfil
    document.getElementById('ep-avatar')?.addEventListener('input', () =>
        atualizarPreview('ep-avatar', 'ep-avatar-preview', 'ep-avatar-img'));
    document.getElementById('ep-capa')?.addEventListener('input', () =>
        atualizarPreview('ep-capa', 'ep-capa-preview', 'ep-capa-img'));

};

// ── Run Migrations / Seeders from Dashboard ───────────────────────────────────
(function initMigrationActions() {
    const btnMigrate = document.getElementById('btn-run-migrations');
    const btnSeed    = document.getElementById('btn-run-seeders');
    if (!btnMigrate && !btnSeed) return;

    // Modal helpers
    function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
    function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

    function showResult(success, output, type) {
        const titleEl  = document.getElementById('run-result-title');
        const outputEl = document.getElementById('run-result-output');
        const label    = type === 'migrate' ? 'Migrations' : 'Seeders';
        if (titleEl) {
            titleEl.innerHTML = success
                ? `<i class="fa-solid fa-circle-check" style="color:#10b981;"></i> ${label} concluídas`
                : `<i class="fa-solid fa-circle-xmark" style="color:#f87171;"></i> Erro ao executar ${label}`;
        }
        if (outputEl) outputEl.textContent = output || (success ? 'Executado com sucesso.' : 'Falha ao executar.');
        openModal('run-result-modal');
    }

    async function runAction(type) {
        const isMigrate = type === 'migrate';
        const endpoint  = isMigrate ? '/api/system/migrations/run' : '/api/system/seeders/run';
        const btn       = isMigrate ? btnMigrate : btnSeed;
        const origHTML  = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Executando...';

        try {
            const res  = await fetch(endpoint, { method: 'POST', credentials: 'same-origin' });
            const data = await res.json();
            showResult(res.ok, data.output || data.message || data.error, type);
            if (res.ok) {
                if (typeof loadMigrations === 'function') loadMigrations();
                else document.getElementById('migrations-refresh-btn')?.click();
            }
        } catch (e) {
            showResult(false, 'Erro de rede: ' + e.message, type);
        } finally {
            btn.disabled = false;
            btn.innerHTML = origHTML;
        }
    }

    // Migrate confirm modal
    if (btnMigrate) {
        btnMigrate.addEventListener('click', () => openModal('migrate-confirm-modal'));
        document.getElementById('migrate-confirm-close')?.addEventListener('click',  () => closeModal('migrate-confirm-modal'));
        document.getElementById('migrate-confirm-cancel')?.addEventListener('click', () => closeModal('migrate-confirm-modal'));
        document.getElementById('migrate-confirm-ok')?.addEventListener('click', () => {
            closeModal('migrate-confirm-modal');
            runAction('migrate');
        });
    }

    // Seed confirm modal
    if (btnSeed) {
        btnSeed.addEventListener('click', () => openModal('seed-confirm-modal'));
        document.getElementById('seed-confirm-close')?.addEventListener('click',  () => closeModal('seed-confirm-modal'));
        document.getElementById('seed-confirm-cancel')?.addEventListener('click', () => closeModal('seed-confirm-modal'));
        document.getElementById('seed-confirm-ok')?.addEventListener('click', () => {
            closeModal('seed-confirm-modal');
            runAction('seed');
        });
    }

    // Result modal close
    document.getElementById('run-result-close')?.addEventListener('click', () => closeModal('run-result-modal'));
    document.getElementById('run-result-ok')?.addEventListener('click',    () => closeModal('run-result-modal'));
})();
