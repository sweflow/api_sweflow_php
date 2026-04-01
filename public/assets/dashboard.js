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
    let emailModuleEnabled = true;
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
                return resolve(window.confirm(`Deseja desabilitar o módulo "${moduleName}"?`));
            }

            disableModalName.textContent = moduleName;
            disableModalText.innerHTML = `Tem certeza que deseja desabilitar o módulo <strong>${moduleName}</strong>?\nTodas as rotas e serviços desse módulo ficarão indisponíveis.`;

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

            disableModal.addEventListener('click', function overlayClick(e) {
                if (e.target === disableModal) {
                    cancelHandler();
                    disableModal.removeEventListener('click', overlayClick);
                }
            });

            requestAnimationFrame(() => disableModal.classList.add('show'));
        });
    }

    function showProtectedModal(moduleName) {
        if (!protectedModal) {
            alert(`O módulo "${moduleName}" é essencial e não pode ser desabilitado.`);
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

        protectedModal.addEventListener('click', function overlayClick(e) {
            if (e.target === protectedModal) {
                closeHandler();
                protectedModal.removeEventListener('click', overlayClick);
            }
        });

        requestAnimationFrame(() => protectedModal.classList.add('show'));
    }

    function showEmailDisabledModal() {
        if (!emailDisabledModal) {
            alert('O módulo de E-mail está desabilitado. Habilite em "Funcionalidades" para usar os envios.');
            return;
        }

        const cleanup = () => {
            emailDisabledModal.classList.remove('show');
            if (emailDisabledOk) emailDisabledOk.onclick = null;
            if (emailDisabledClose) emailDisabledClose.onclick = null;
        };

        const closeHandler = () => cleanup();

        if (emailDisabledOk) emailDisabledOk.onclick = closeHandler;
        if (emailDisabledClose) emailDisabledClose.onclick = closeHandler;

        emailDisabledModal.addEventListener('click', function overlayClick(e) {
            if (e.target === emailDisabledModal) {
                closeHandler();
                emailDisabledModal.removeEventListener('click', overlayClick);
            }
        });

        requestAnimationFrame(() => emailDisabledModal.classList.add('show'));
    }

    function renderModules(modules) {
        if (!modulesList) return;
        
        if (!modules || modules.length === 0) {
            modulesList.innerHTML = '<div class="muted" style="grid-column: 1/-1; text-align: center; padding: 40px; color: #95a5a6;">Nenhum módulo encontrado.</div>';
            return;
        }

        modulesList.innerHTML = modules.map(mod => {
            const isEnabled = mod.enabled !== false; // API might return explicit false
            const isProtected = mod.protected || ['Auth', 'Usuario'].includes(mod.name);
            const statusClass = isEnabled ? 'active' : 'inactive';
            const statusText = isEnabled ? 'Ativo' : 'Inativo';
            
            let cardStatusClass = isProtected ? 'status-system' : (isEnabled ? 'status-active' : 'status-inactive');
            
            // Determine icon based on module name
            let iconClass = 'fa-cube';
            const nameLower = (mod.name || mod.nome || '').toLowerCase();
            if (nameLower.includes('auth')) iconClass = 'fa-shield-halved';
            else if (nameLower.includes('user') || nameLower.includes('usuario')) iconClass = 'fa-users';
            else if (nameLower.includes('email')) iconClass = 'fa-envelope';
            else if (nameLower.includes('payment') || nameLower.includes('pagamento')) iconClass = 'fa-credit-card';
            else if (nameLower.includes('report') || nameLower.includes('relatorio')) iconClass = 'fa-chart-pie';
            else if (nameLower.includes('plugin')) iconClass = 'fa-plug';

            let actionElement = '';
            if (!isProtected) {
                const btnClass = isEnabled ? 'toggle-on' : 'toggle-off';
                const btnIcon = isEnabled ? 'fa-power-off' : 'fa-play';
                const btnText = isEnabled ? 'Desativar' : 'Ativar';
                // Note: toggleModule must be globally available or attached via event delegation. 
                // Since we render HTML string, onclick needs global scope. We'll attach toggleModule to window.
                actionElement = `<button class="module-btn ${btnClass}" onclick="window.toggleModule('${mod.name ?? mod.nome}')"><i class="fa-solid ${btnIcon}"></i> ${btnText}</button>`;
            } else {
                actionElement = `<span style="font-size:0.85rem;color:#95a5a6;font-style:italic;"><i class="fa-solid fa-lock"></i> Protegido</span>`;
            }

            const routeCount = (mod.routes || []).length;
            const routeText = routeCount === 1 ? 'rota' : 'rotas';

            return `
            <div class="module-card ${cardStatusClass}">
                <div class="module-header">
                    <div class="module-info">
                        <div class="module-icon"><i class="fa-solid ${iconClass}"></i></div>
                        <div class="module-meta">
                            <h3 class="module-title">${mod.name ?? mod.nome}</h3>
                            <span class="module-version">v${mod.version || '1.0.0'}</span>
                        </div>
                    </div>
                    <span class="module-badge ${isProtected ? 'system' : statusClass}">${statusText}</span>
                </div>
                
                <div class="module-description" title="${mod.description || ''}">
                    ${mod.description || 'Sem descrição disponível para este módulo.'}
                </div>
                
                <div class="module-stats">
                    <div class="stat-item" title="Rotas registradas">
                        <i class="fa-solid fa-route"></i> ${routeCount} ${routeText}
                    </div>
                    <div class="stat-item" title="Tipo de módulo">
                            ${isProtected ? '<i class="fa-solid fa-lock"></i> Core' : '<i class="fa-solid fa-puzzle-piece"></i> Extensão'}
                    </div>
                </div>

                <div class="module-footer">
                    ${actionElement}
                </div>
            </div>
            `;
        }).join('');
    }

    // Expose toggleModule globally
    window.toggleModule = async function(name) {
        if (protectedModules.includes(name)) {
            showProtectedModal(name);
            return;
        }
        
        // Optimistic UI update could go here, but for now we rely on re-fetch
        try {
            // Check current state from DOM or cache if needed, but endpoint handles toggle
            const res = await fetch('/api/modules/toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ module: name }) // StatusController expects 'module', not 'name'
            });
            const data = await res.json();
            if (data.enabled !== undefined) {
                fetchMetrics(); // Refresh UI
                fetchModulesState(); // Refresh toggles list
            } else {
                alert('Erro ao alterar status: ' + (data.error || 'Desconhecido'));
            }
        } catch (e) {
            alert('Erro de conexão.');
        }
    };

    function renderRoutes(modules) {
        if (!routesList) return;
        const enabled = modules.filter(m => m.enabled !== false && Array.isArray(m.routes) && m.routes.length > 0);
        let html = '';
        enabled.forEach(mod => {
            html += `<h3>${mod.name ?? mod.nome}</h3>`;
            html += `<table class="routes-table"><thead><tr><th>Método</th><th>URI</th><th>Tipo</th></tr></thead><tbody>`;
            (mod.routes || []).forEach(route => {
                const tipo = route.tipo === 'pública' ? '<span class=public><i class=fa-solid fa-unlock></i> Pública</span>' : '<span class=private><i class=fa-solid fa-lock></i> Privada</span>';
                html += `<tr><td>${route.method}</td><td>${route.uri}</td><td>${tipo}</td></tr>`;
            });
            html += `</tbody></table>`;
        });
        routesList.innerHTML = html;
    }

    async function loadCapabilities() {
        const el = document.getElementById('capabilities-list');
        if (!el) return;
        el.textContent = 'Carregando...';
        try {
            const res = await fetch('/api/capabilities');
            const data = await res.json();
            const items = data.items || [];
            if (items.length === 0) {
                el.innerHTML = '<div class="muted">Nenhuma capacidade detectada.</div>';
                return;
            }
            const rows = items.map(it => {
                const options = (it.providers || []).map(p => `<option value="${p}" ${it.active === p ? 'selected' : ''}>${p}</option>`).join('');
                
                // Opção "Nenhum" (para desativar/limpar) se houver providers
                const noneOption = `<option value="">-- Selecione --</option>`;
                
                return `
                <div class="toggle-card">
                    <div class="toggle-info">
                        <span class="toggle-name">${it.capability}</span>
                        <span class="toggle-tag">Ativo: ${it.active || 'nenhum'}</span>
                    </div>
                    <div>
                        <label class="pill">Provedor</label>
                        <select data-capability="${it.capability}" class="capability-select">
                            ${noneOption}
                            ${options}
                        </select>
                    </div>
                </div>`;
            }).join('');
            el.innerHTML = rows;
            el.querySelectorAll('select.capability-select').forEach(sel => {
                sel.addEventListener('change', async () => {
                    const cap = sel.getAttribute('data-capability');
                    const plugin = sel.value;
                    sel.disabled = true;
                    try {
                        const res = await fetch('/api/capabilities/provider', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ capability: cap, plugin })
                        });
                        if (!res.ok) {
                            alert('Falha ao definir provedor.');
                        } else {
                            await loadCapabilities();
                        }
                    } catch (e) {
                        alert('Erro ao definir provedor.');
                    } finally {
                        sel.disabled = false;
                    }
                });
            });
        } catch (e) {
            el.innerHTML = '<div class="muted">Erro ao carregar capacidades.</div>';
        }
    }

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
            emailFeedback.className = 'login-feedback';
        }
        if (emailForm) emailForm.reset();
        if (emailSend) {
            emailSend.disabled = false;
            emailSend.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar';
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
        img.src = url;
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
        const rect = img.getBoundingClientRect();
        const top = window.scrollY + rect.top - pop.offsetHeight - 8;
        const left = window.scrollX + rect.left;
        pop.style.top = `${Math.max(8, top)}px`;
        pop.style.left = `${Math.max(8, left)}px`;
    }

    function showImagePopover(img) {
        const pop = ensureImagePopover();
        const rangeInput = pop.querySelector('.image-size-range');
        const numberInput = pop.querySelector('.image-size-number');
        const resetBtn = pop.querySelector('.image-reset');
        const currentWidth = parseInt(img.style.width || '', 10) || img.getBoundingClientRect().width;

        imagePopoverTarget = img;
        pop.classList.add('show');
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
        return parts
            .map(p => p.trim())
            .filter(p => p !== '' && /.+@.+\..+/.test(p));
    }

    async function submitEmail(e) {
        e.preventDefault();
        if (!emailForm || !emailEditor || !emailSubject) return;

        const recipients = extractEmailsFromText(emailTo?.value || '').map(e => ({ email: e, name: e }));
        const payload = {
            recipients,
            subject: emailSubject.value.trim(),
            logo_url: emailLogo?.value.trim() || '',
            html: emailEditor.innerHTML.trim(),
        };

        if (!payload.recipients.length || !payload.subject || !payload.html) {
            if (emailFeedback) {
                emailFeedback.textContent = 'Informe destinatários, assunto e conteúdo.';
                emailFeedback.className = 'login-feedback error';
            }
            return;
        }

        if (emailSend) {
            emailSend.disabled = true;
            emailSend.innerHTML = 'Enviando...';
        }

        try {
            const res = await fetch('/api/email/custom', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });
            const body = await res.json();
            if (!res.ok) {
                throw new Error(body.error || body.message || 'Falha ao enviar e-mail');
            }
            if (emailFeedback) {
                emailFeedback.textContent = 'E-mail enviado com sucesso.';
                emailFeedback.className = 'login-feedback success';
            }
            setTimeout(closeEmailModal, 800);
        } catch (err) {
            if (emailFeedback) {
                emailFeedback.textContent = err.message;
                emailFeedback.className = 'login-feedback error';
            }
        } finally {
            if (emailSend) {
                emailSend.disabled = false;
                emailSend.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar';
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
        moduleState = {};

        modulesToggleList.innerHTML = modules.map(mod => {
            const enabled = mod.enabled !== false;
            moduleState[mod.name] = enabled;
            return `
                <div class="toggle-card">
                    <div class="toggle-info">
                        <span class="toggle-name">${mod.name}</span>
                        <span class="toggle-tag">${enabled ? 'Ativo' : 'Inativo'}</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" data-module="${mod.name}" ${enabled ? 'checked' : ''} />
                        <span class="slider"></span>
                    </label>
                </div>
            `;
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
                        body: JSON.stringify({ name, enabled })
                    });
                    const body = await res.json();
                    if (!res.ok) {
                        throw new Error(body.error || body.message || 'Erro ao atualizar módulo');
                    }
                    fetchModulesState();
                    fetchMetrics();
                } catch (err) {
                    e.target.checked = !enabled; // rollback
                    alert(err.message);
                }
            });
        });

        updateEmailCardState();
        fetchAuthPolicy();
    }

    async function fetchModulesState() {
        try {
            const res = await fetch('/api/modules/state');
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/';
                return;
            }
            const data = await res.json();
            const modules = data.modules || [];
            renderFeatureToggles(modules);
        } catch (err) {
            // silencioso
        }
    }

    function renderMetrics(data) {
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

        renderModules(modules);
        renderRoutes(modules);
        renderFeatureToggles(modules);
    }

    function updateEmailCardState() {
        emailModuleEnabled = Boolean(moduleState['Email']);
        const statePill = document.getElementById('email-module-state');
        const openEmailModalBtn = document.getElementById('open-email-modal');
        if (!statePill || !openEmailModalBtn) return;

        if (emailModuleEnabled) {
            statePill.textContent = 'Habilitado';
            statePill.style.backgroundColor = '#e5f7ee';
            statePill.style.color = '#0f7b3b';
            openEmailModalBtn.classList.remove('disabled');
            openEmailModalBtn.title = 'Enviar e-mail personalizado';
        } else {
            statePill.textContent = 'Desabilitado';
            statePill.style.backgroundColor = '#fdeaea';
            statePill.style.color = '#b3261e';
            openEmailModalBtn.classList.add('disabled');
            openEmailModalBtn.title = 'Módulo de E-mail desabilitado. Habilite em "Funcionalidades" para enviar.';
        }

        updateAuthVerifyUI(authRequireEmailVerification, false);
    }

    function handleUnauthorized(status) {
        if (status === 401 || status === 403) {
            window.location.href = '/';
            return true;
        }
        return false;
    }

    function updateAuthVerifyUI(state, loading = false) {
        if (!authVerifyToggle || !authVerifyTag) return;
        const disabledByModule = !emailModuleEnabled;
        const effectiveState = disabledByModule ? false : state;
        authVerifyToggle.checked = effectiveState;
        authVerifyToggle.disabled = loading || disabledByModule;
        if (loading) {
            authVerifyTag.textContent = 'Sincronizando...';
            authVerifyTag.style.backgroundColor = '#fff3cd';
            authVerifyTag.style.color = '#8a6d3b';
            return;
        }
        if (disabledByModule) {
            authVerifyTag.textContent = 'Requer módulo E-mail';
            authVerifyTag.style.backgroundColor = '#fdeaea';
            authVerifyTag.style.color = '#b3261e';
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
            const res = await fetch('/api/auth/email-verification');
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
        if (!emailModuleEnabled && enabled) {
            alert('Ative o módulo de E-mail para exigir verificação por e-mail.');
            updateAuthVerifyUI(authRequireEmailVerification, false);
            authVerifyToggle.checked = false;
            return;
        }
        updateAuthVerifyUI(enabled, true);
        try {
            const res = await fetch('/api/auth/email-verification', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ require_verification: enabled })
            });
            const body = await res.json();
            if (!res.ok) throw new Error(body.error || body.message || 'Erro ao salvar política.');
            authRequireEmailVerification = enabled;
            updateAuthVerifyUI(authRequireEmailVerification, false);
        } catch (err) {
            updateAuthVerifyUI(authRequireEmailVerification, false);
            if (authVerifyToggle) authVerifyToggle.checked = authRequireEmailVerification;
            alert(err.message);
        }
    }

    function fetchMetrics() {
        fetch('/api/dashboard/metrics', { credentials: 'same-origin' })
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
                    renderMetrics(data);
                }
            })
            .catch(() => {});
    }


    // Inicialização: verifica sessão via API antes de carregar o resto
    fetch('/api/dashboard/metrics', { credentials: 'same-origin' })
        .then(async (res) => {
            if (handleUnauthorized(res.status)) return null; // redireciona para / se 401/403
            const body = await res.json();
            if (!res.ok) return null;
            return body;
        })
        .then(data => {
            if (!data) return;
            renderMetrics(data);
            // Só carrega o resto após confirmar que a sessão é válida
            loadCapabilities();
            fetchModulesState();
            // Polling a cada 30s
            setInterval(() => {
                fetchMetrics();
                fetchModulesState();
            }, 30000);
        })
        .catch(() => {});

    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }

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
    if (authVerifyToggle) authVerifyToggle.addEventListener('change', (e) => {
        persistAuthPolicy(e.target.checked);
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
};
