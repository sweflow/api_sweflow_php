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

            // No overlay click-to-close — only buttons close this modal

            requestAnimationFrame(() => disableModal.classList.add('show'));
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
    function showErrorModal(message, title = 'Erro') {
        const modal   = document.getElementById('error-modal');
        const titleEl = document.getElementById('error-modal-title');
        const msgEl   = document.getElementById('error-modal-message');
        const okBtn   = document.getElementById('error-modal-ok');
        const closeBtn = document.getElementById('error-modal-close');

        if (!modal) { console.error(message); return; }

        if (titleEl) titleEl.textContent = title;
        if (msgEl)   msgEl.textContent   = message;

        const close = () => { modal.classList.remove('show'); modal.style.zIndex = ''; };
        if (okBtn)    okBtn.onclick    = close;
        if (closeBtn) closeBtn.onclick = close;
        // No overlay click-to-close
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
        try {
            const res = await fetch('/api/modules/toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // API expects { name, enabled } — NOT { module }
                body: JSON.stringify({ name, enabled: !(moduleState[name] ?? true) })
            });
            const data = await res.json();
            if (data.enabled !== undefined) {
                fetchMetrics();
                fetchModulesState();
            } else {
                showErrorModal(data.error || 'Erro desconhecido ao alterar status.', 'Erro ao alterar módulo');
            }
        } catch (e) {
            showErrorModal('Erro de conexão ao tentar alterar o módulo.', 'Erro de conexão');
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
        const installed = 'Email' in moduleState;
        emailModuleEnabled = installed && Boolean(moduleState['Email']);
        const statePill        = document.getElementById('email-module-state');
        const openEmailModalBtn = document.getElementById('open-email-modal');
        const openHistoryBtn   = document.getElementById('open-email-history');
        if (!statePill || !openEmailModalBtn) return;

        if (emailModuleEnabled) {
            statePill.textContent = 'Habilitado';
            statePill.style.backgroundColor = '#e5f7ee';
            statePill.style.color = '#0f7b3b';
            openEmailModalBtn.classList.remove('disabled');
            openEmailModalBtn.disabled = false;
            openEmailModalBtn.title = 'Enviar e-mail personalizado';
            if (openHistoryBtn) {
                openHistoryBtn.classList.remove('disabled');
                openHistoryBtn.disabled = false;
                openHistoryBtn.title = 'Ver histórico de e-mails';
            }
        } else {
            const label = installed ? 'Desabilitado' : 'Não instalado';
            const tip   = installed
                ? 'Módulo de E-mail desabilitado. Habilite em "Funcionalidades" para enviar.'
                : 'Módulo de E-mail não instalado. Instale pelo Marketplace.';
            statePill.textContent = label;
            statePill.style.backgroundColor = '#fdeaea';
            statePill.style.color = '#b3261e';
            openEmailModalBtn.classList.add('disabled');
            openEmailModalBtn.disabled = true;
            openEmailModalBtn.title = tip;
            if (openHistoryBtn) {
                openHistoryBtn.classList.add('disabled');
                openHistoryBtn.disabled = true;
                openHistoryBtn.title = tip;
            }
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
        .then(async data => {
            if (!data) return;
            renderMetrics(data);
            // Sequencial: evita conexões simultâneas no php -S (single-thread)
            await fetchModulesState();
            await loadCapabilities();
            // Polling a cada 30s — sequencial
            setInterval(async () => {
                await fetchMetrics();
                await fetchModulesState();
            }, 30000);
        })
        .catch(() => {});

    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
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
        historyList.innerHTML = '<p style="color:#888;text-align:center;padding:24px;">Carregando...</p>';
        try {
            const url = '/api/email/history' + (q ? '?q=' + encodeURIComponent(q) : '');
            const res = await fetch(url, { credentials: 'same-origin' });
            const data = await res.json();
            const items = data.items || [];
            if (!items.length) {
                historyList.innerHTML = q
                    ? '<p style="color:#888;text-align:center;padding:24px;">Nenhum resultado para "' + q + '".</p>'
                    : '<p style="color:#888;text-align:center;padding:24px;">Nenhum e-mail enviado ainda.</p>';
                return;
            }
            historyList.innerHTML = items.map(item => {
                const statusColor = item.status === 'enviado' ? '#27ae60' : '#e74c3c';
                const statusIcon  = item.status === 'enviado' ? 'fa-check-circle' : 'fa-times-circle';
                const errorHint   = item.error ? `<small style="color:#e74c3c;display:block;margin-top:2px;font-size:.8rem;">${item.error}</small>` : '';
                return `
                <div class="toggle-card" style="cursor:pointer;margin-bottom:8px;" data-id="${item.id}" role="button" tabindex="0">
                    <div class="toggle-info" style="flex:1;min-width:0;">
                        <span class="toggle-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;">${item.subject || '(sem assunto)'}</span>
                        <span class="toggle-tag" style="color:${statusColor};font-weight:600;">
                            <i class="fa-solid ${statusIcon}"></i> ${item.status}
                        </span>
                        ${errorHint}
                        <small style="color:#888;">${fmtDate(item.created_at)}</small>
                    </div>
                    <i class="fa-solid fa-chevron-right" style="color:#bbb;margin-left:8px;"></i>
                </div>`;
            }).join('');

            historyList.querySelectorAll('[data-id]').forEach(el => {
                const open = () => openEmailDetail(el.dataset.id);
                el.addEventListener('click', open);
                el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') open(); });
            });
        } catch {
            historyList.innerHTML = '<p style="color:#e74c3c;text-align:center;padding:24px;">Erro ao carregar histórico.</p>';
        }
    }

    async function openEmailDetail(id) {
        currentHistoryId = id;
        if (detailBody) detailBody.innerHTML = '<p style="color:#888;text-align:center;padding:24px;">Carregando...</p>';
        if (detailModal) detailModal.classList.add('show');

        try {
            const res = await fetch(`/api/email/history/${id}`, { credentials: 'same-origin' });
            const item = await res.json();
            if (!res.ok) throw new Error(item.error || 'Erro ao carregar.');

            const emails = recipientEmails(item.recipients);
            const statusColor = item.status === 'enviado' ? '#27ae60' : '#e74c3c';

            detailBody.innerHTML = `
                <div style="display:grid;gap:12px;">
                    <div class="input-group" style="margin:0;">
                        <label>Assunto</label>
                        <div style="padding:8px 12px;background:#f8f8f8;border-radius:6px;border:1px solid #e0e0e0;">${item.subject || '(sem assunto)'}</div>
                    </div>
                    <div class="input-group" style="margin:0;">
                        <label>Destinatários</label>
                        <div style="padding:8px 12px;background:#f8f8f8;border-radius:6px;border:1px solid #e0e0e0;word-break:break-all;">${emails || '--'}</div>
                    </div>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;">
                        <div><label style="font-size:.8rem;color:#888;">Status</label><br>
                            <span style="color:${statusColor};font-weight:600;"><i class="fa-solid ${item.status === 'enviado' ? 'fa-check-circle' : 'fa-times-circle'}"></i> ${item.status}</span>
                        </div>
                        <div><label style="font-size:.8rem;color:#888;">Data/Hora</label><br>
                            <span>${fmtDate(item.created_at)}</span>
                        </div>
                        ${item.error ? `<div style="flex:1;"><label style="font-size:.8rem;color:#e74c3c;">Erro</label><br><span style="color:#e74c3c;font-size:.9rem;">${item.error}</span></div>` : ''}
                    </div>
                    <div class="input-group" style="margin:0;">
                        <label>Conteúdo do e-mail</label>
                        <div style="border:1px solid #e0e0e0;border-radius:6px;padding:16px;background:#fff;max-height:300px;overflow-y:auto;">
                            ${item.html || '<em style="color:#888;">Sem conteúdo</em>'}
                        </div>
                    </div>
                </div>`;
        } catch (err) {
            detailBody.innerHTML = `<p style="color:#e74c3c;text-align:center;padding:24px;">${err.message}</p>`;
        }
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
            detailResend.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reenviando...';

            // Feedback element inside detail modal
            let fb = document.getElementById('detail-resend-feedback');
            if (!fb && detailBody) {
                fb = document.createElement('div');
                fb.id = 'detail-resend-feedback';
                fb.className = 'login-feedback';
                fb.style.marginTop = '12px';
                detailBody.appendChild(fb);
            }
            if (fb) { fb.textContent = ''; fb.className = 'login-feedback'; }

            try {
                const res = await fetch(`/api/email/history/${currentHistoryId}/resend`, {
                    method: 'POST', credentials: 'same-origin'
                });
                const data = await res.json();

                if (data.module_disabled) {
                    // Fecha o detalhe e mostra modal de módulo desabilitado
                    closeEmailDetail();
                    historyModal?.classList.remove('show');
                    showEmailDisabledModal();
                    loadEmailHistory(); // atualiza histórico com entrada "falhou"
                    return;
                }

                if (!res.ok) {
                    if (fb) {
                        fb.textContent = data.error || 'Falha ao reenviar.';
                        fb.className = 'login-feedback error';
                    }
                    loadEmailHistory();
                    return;
                }

                if (fb) {
                    fb.textContent = 'E-mail reenviado com sucesso.';
                    fb.className = 'login-feedback success';
                }
                setTimeout(() => { closeEmailDetail(); loadEmailHistory(); }, 800);
            } catch (err) {
                if (fb) {
                    fb.textContent = err.message || 'Erro de conexão.';
                    fb.className = 'login-feedback error';
                }
            } finally {
                detailResend.disabled = false;
                detailResend.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Reenviar';
            }
        });
    }

    if (detailEdit) {
        detailEdit.addEventListener('click', async () => {
            if (!currentHistoryId) return;
            try {
                const res = await fetch(`/api/email/history/${currentHistoryId}`, { credentials: 'same-origin' });
                const item = await res.json();
                if (!res.ok) throw new Error(item.error || 'Erro.');

                // Populate email form with history data
                if (emailTo) emailTo.value = recipientEmails(item.recipients);
                if (emailSubject) emailSubject.value = item.subject || '';
                if (emailLogo) emailLogo.value = item.logo_url || '';
                if (emailEditor) emailEditor.innerHTML = item.html || '';

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
    if (deleteClose) deleteClose.addEventListener('click', closeDeleteConfirm);
    if (deleteCancel) deleteCancel.addEventListener('click', closeDeleteConfirm);
    // No overlay click-to-close for delete confirm

    if (deleteConfirm) {
        deleteConfirm.addEventListener('click', async () => {
            if (!currentHistoryId) return;
            deleteConfirm.disabled = true;
            try {
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
        body.innerHTML = '<p style="color:#888;text-align:center;">Carregando...</p>';
        try {
            const res = await fetch('/api/perfil', { credentials: 'same-origin' });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Erro ao carregar perfil.');
            perfilAtual = data.usuario;
            renderMeuPerfil(perfilAtual);
        } catch (e) {
            body.innerHTML = `<p style="color:#e74c3c;text-align:center;">${e.message}</p>`;
        }
    }

    function renderMeuPerfil(u) {
        const body = document.getElementById('meu-perfil-body');
        if (!body || !u) return;
        const avatarHtml = u.url_avatar
            ? `<img class="perfil-avatar" src="${u.url_avatar}" alt="Avatar" onerror="this.style.display='none'" />`
            : `<div class="perfil-avatar-placeholder"><i class="fa-solid fa-user"></i></div>`;
        const capaHtml = u.url_capa
            ? `<img class="perfil-capa" src="${u.url_capa}" alt="Capa" onerror="this.style.display='none'" />`
            : '';
        const nivelClass = u.nivel_acesso || 'usuario';
        const nivelLabel = { usuario: 'Usuário', moderador: 'Moderador', admin: 'Admin', admin_system: 'Admin System' }[nivelClass] || nivelClass;

        body.innerHTML = `
            ${capaHtml}
            <div class="perfil-header">
                ${avatarHtml}
                <div>
                    <div style="font-size:1.2rem;font-weight:800;color:#1e2235;">${u.nome_completo || '--'}</div>
                    <div style="color:#888;font-size:.9rem;">@${u.username || '--'}</div>
                    <span class="nivel-badge ${nivelClass}">${nivelLabel}</span>
                </div>
            </div>
            <div class="perfil-grid">
                <div class="perfil-field"><label>E-mail</label><span>${u.email || '--'}</span></div>
                <div class="perfil-field"><label>Status</label><span>${u.ativo ? '✔ Ativo' : '✖ Inativo'}</span></div>
                <div class="perfil-field"><label>E-mail verificado</label><span>${u.verificado_email ? '✔ Sim' : '✖ Não'}</span></div>
                <div class="perfil-field"><label>Membro desde</label><span>${u.criado_em ? new Date(u.criado_em).toLocaleDateString('pt-BR') : '--'}</span></div>
            </div>
            ${u.biografia ? `<div class="perfil-field"><label>Biografia</label><span>${u.biografia}</span></div>` : ''}
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
                img.src = url;
                preview.style.display = '';
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
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando...';
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
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Salvar';
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
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Alterando...';
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
                btn.innerHTML = '<i class="fa-solid fa-key"></i> Alterar senha';
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
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Criando...';
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
                btn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Criar usuário';
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
