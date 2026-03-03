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
    const logoutBtn = document.getElementById('logout-btn');

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

    function renderModules(modules) {
        if (!modulesList) return;
        modulesList.innerHTML = modules.map(m => `<li><strong>${m.name ?? m.nome}</strong></li>`).join('');
    }

    function renderRoutes(modules) {
        if (!routesList) return;
        let html = '';
        modules.forEach(mod => {
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

    async function handleLogout(e) {
        if (e) e.preventDefault();
        try {
            const res = await fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin' });
            // ignore body; best-effort clear cookie server-side
        } catch (err) {
            // ignore
        }
        // Clear client-side tokens if stored
        try { localStorage.removeItem('access_token'); localStorage.removeItem('refresh_token'); } catch (_) {}
        window.location.href = '/';
    }

    function renderFeatureToggles(modules) {
        if (!modulesToggleList) return;
        modulesToggleList.innerHTML = modules.map(mod => {
            const enabled = mod.enabled !== false;
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
            dbMeta.textContent = db.conectado ? 'Conectado' : 'Desconectado';
        }

        if (serverStatus) {
            serverStatus.textContent = 'On-line';
            serverStatus.className = 'metric-value success';
        }
        if (serverMeta) {
            const meta = `${status.host ?? '-'}:${status.port ?? '-'} • env=${status.env ?? '-'} • debug=${status.debug ?? '-'}`;
            serverMeta.textContent = meta;
        }

        if (usersTotal) {
            usersTotal.textContent = usuarios.total ?? '--';
        }

        renderModules(modules);
        renderRoutes(modules);
        renderFeatureToggles(modules);
    }

    function handleUnauthorized(status) {
        if (status === 401 || status === 403) {
            window.location.href = '/';
            return true;
        }
        return false;
    }

    function fetchMetrics() {
        fetch('/api/dashboard/metrics')
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

    fetchMetrics();
    fetchModulesState();
    setInterval(() => {
        fetchMetrics();
        fetchModulesState();
    }, 3000);

    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
};
