<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sweflow API</title>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
                Sweflow API
            </div>
            <nav>
                <ul>
                    <li><a href="#login" id="open-login"><i class="fa-solid fa-right-to-bracket"></i> <span id="nav-login-text">Login</span></a></li>
                    <li><a href="#status"><i class="fa-solid fa-server"></i> Status</a></li>
                    <li><a href="#modulos"><i class="fa-solid fa-layer-group"></i> Módulos</a></li>
                    <li><a href="#rotas"><i class="fa-solid fa-route"></i> Rotas</a></li>
                </ul>
            </nav>
        </aside>
        <main class="content">
            <section id="descricao">
                <h1>
                    <?php if (!empty($logo_url)): ?>
                        <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" style="width:40px;height:40px;border-radius:8px;object-fit:contain;vertical-align:middle;margin-right:10px;" />
                    <?php else: ?>
                        <i class="fa-solid fa-cubes"></i>
                    <?php endif; ?>
                    Sweflow API
                </h1>
                <p><?= $descricao ?></p>
                <div class="cta-row">
                    <button class="btn primary login-btn" id="cta-open-login"><i class="fa-solid fa-right-to-bracket"></i> <span id="cta-login-text">Fazer login</span></button>
                </div>
            </section>
            <section id="status">
                <h2><i class="fa-solid fa-server"></i> Status do Servidor</h2>
                <ul class="status-list" id="status-list">
                    <li>Carregando...</li>
                </ul>
            </section>
            <section id="db-status">
                <h2><i class="fa-solid fa-database"></i> Status do Banco de Dados</h2>
                <div id="db-status-content" class="db-status"> 
                    <i class="fa-solid fa-database" style="color:gray"></i> <span style="color:gray">Carregando...</span>
                </div>
            </section>
            <section id="modulos">
                <h2><i class="fa-solid fa-layer-group"></i> Módulos Detectados</h2>
                <ul class="modules-list" id="modules-list">
                    <li>Carregando...</li>
                </ul>
            </section>
            <section id="rotas">
                <h2><i class="fa-solid fa-route"></i> Rotas dos Módulos</h2>
                <div id="routes-list">Carregando...</div>
            </section>

            <div class="modal-overlay" id="login-modal" aria-hidden="true">
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="login-modal-title">
                    <div class="modal-header">
                        <h2 id="login-modal-title"><i class="fa-solid fa-lock"></i> Login administrativo</h2>
                        <button type="button" class="modal-close" id="login-close" aria-label="Fechar modal">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <form id="login-form" class="login-form">
                        <div class="input-group">
                            <label for="login-user">Usuário ou e-mail</label>
                            <input type="text" id="login-user" name="login" placeholder="admin" autocomplete="username" required />
                        </div>
                        <div class="input-group">
                            <label for="login-password">Senha</label>
                            <input type="password" id="login-password" name="senha" placeholder="••••••••" autocomplete="current-password" required />
                        </div>
                        <div class="form-actions">
                            <button type="submit" id="login-submit" class="btn primary login-btn"><i class="fa-solid fa-door-open"></i> Fazer login</button>
                            <button type="button" class="btn ghost" id="login-cancel">Cancelar</button>
                        </div>
                        <div id="login-feedback" class="login-feedback" aria-live="polite"></div>
                    </form>
                </div>
            </div>
            <script>
            window.onload = function() {
                const statusList = document.getElementById('status-list');
                const modulesList = document.getElementById('modules-list');
                const routesList = document.getElementById('routes-list');
                const dbStatusContent = document.getElementById('db-status-content');
                const dbDetails = null;
                const loginModal = document.getElementById('login-modal');
                const openLoginNav = document.getElementById('open-login');
                const openLoginCta = document.getElementById('cta-open-login');
                const loginClose = document.getElementById('login-close');
                const loginCancel = document.getElementById('login-cancel');
                const navLoginText = document.getElementById('nav-login-text');
                const ctaLoginText = document.getElementById('cta-login-text');

                function renderStatus(data) {
                    const status = data.status || {};
                    if (!statusList) return;
                    statusList.innerHTML = `
                        <li><strong>Host:</strong> ${status.host ?? '-'}</li>
                        <li><strong>Porta:</strong> ${status.port ?? '-'}</li>
                        <li><strong>Ambiente:</strong> ${status.env ?? '-'}</li>
                        <li><strong>Debug:</strong> ${status.debug ?? '-'}</li>
                    `;
                }

                function renderModules(data) {
                    const modules = data.modules || [];
                    if (modulesList) {
                        modulesList.innerHTML = modules.map(m => `<li><strong>${m.name ?? m.nome}</strong></li>`).join('');
                    }
                }

                function renderRoutes(data) {
                    const modules = (data.modules || []).filter(m => m.enabled !== false);
                    let html = '';
                    modules.forEach(mod => {
                        if (!mod.routes || mod.routes.length === 0) return;
                        html += `<h3>${mod.name ?? mod.nome}</h3>`;
                        html += `<table class="routes-table"><thead><tr><th>Método</th><th>URI</th><th>Tipo</th></tr></thead><tbody>`;
                        (mod.routes || []).forEach(route => {
                            const tipo = route.tipo === 'pública' ? '<span class=public><i class=fa-solid fa-unlock></i> Pública</span>' : '<span class=private><i class=fa-solid fa-lock></i> Privada</span>';
                            html += `<tr><td>${route.method}</td><td>${route.uri}</td><td>${tipo}</td></tr>`;
                        });
                        html += `</tbody></table>`;
                    });
                    if (routesList) {
                        routesList.innerHTML = html;
                    }
                }

                function renderDbStatus(data) {
                    if (!dbStatusContent) return;
                    const conectado = !!data.conectado;
                    const database = data.database || {};
                    const iconColor = conectado ? 'green' : 'red';
                    const texto = conectado ? 'Conectado' : 'Desconectado';
                    let html = `<i class="fa-solid fa-database" style="color:${iconColor}"></i> <span style="color:${iconColor}">${texto}</span>`;
                    if (data.erro) {
                        html += `<div class="alert error"><strong>Erro:</strong> ${data.erro}</div>`;
                    }
                    dbStatusContent.innerHTML = html;
                }

                function updateData() {
                    fetch('/api/status')
                        .then(r => r.json())
                        .then(data => {
                            renderStatus(data);
                            renderModules(data);
                            renderRoutes(data);
                        })
                        .catch(() => {});
                }

                function updateDbStatus() {
                    fetch('/api/db-status')
                        .then(r => r.json())
                        .then(data => renderDbStatus(data))
                        .catch(() => {});
                }

                function updateLoginLabels(isAuthed) {
                    if (navLoginText) {
                        navLoginText.textContent = isAuthed ? 'Dashboard' : 'Login';
                    }
                    if (ctaLoginText) {
                        ctaLoginText.textContent = isAuthed ? 'Ir para Dashboard' : 'Fazer login';
                    }
                }

                async function checkSession() {
                    try {
                        const res = await fetch('/api/auth/me', { credentials: 'same-origin' });
                        if (res.ok) {
                            updateLoginLabels(true);
                            if (openLoginNav) {
                                openLoginNav.addEventListener('click', (e) => { e.preventDefault(); window.location.href = '/dashboard'; });
                            }
                            if (openLoginCta) {
                                openLoginCta.addEventListener('click', (e) => { e.preventDefault(); window.location.href = '/dashboard'; });
                            }
                            return true;
                        }
                    } catch (e) {
                        // ignore
                    }
                    updateLoginLabels(false);
                    return false;
                }

                function updateLoginLabels(isAuthed) {
                    if (navLoginText) {
                        navLoginText.textContent = isAuthed ? 'Dashboard' : 'Login';
                    }
                    if (ctaLoginText) {
                        ctaLoginText.textContent = isAuthed ? 'Ir para Dashboard' : 'Fazer login';
                    }
                }

                async function checkSession() {
                    try {
                        const res = await fetch('/api/auth/me', { credentials: 'same-origin' });
                        if (res.ok) {
                            updateLoginLabels(true);
                            if (openLoginNav) {
                                openLoginNav.addEventListener('click', (e) => { e.preventDefault(); window.location.href = '/dashboard'; });
                            }
                            if (openLoginCta) {
                                openLoginCta.addEventListener('click', (e) => { e.preventDefault(); window.location.href = '/dashboard'; });
                            }
                            return true;
                        }
                    } catch (e) {
                        // ignore
                    }
                    updateLoginLabels(false);
                    return false;
                }

                function setupLogin() {
                    const form = document.getElementById('login-form');
                    const loginInput = document.getElementById('login-user');
                    const passwordInput = document.getElementById('login-password');
                    const feedback = document.getElementById('login-feedback');
                    const submitBtn = document.getElementById('login-submit');
                    const modalOverlay = loginModal;
                    const maxAttempts = 5;
                    const lockDurationMs = 30 * 1000;
                    let attempts = 0;
                    let lockUntil = 0;
                    let lockInterval = null;

                    const emailRegex = /^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/;

                    function normalizeLogin(value) {
                        return (value || '').toLowerCase();
                    }

                    function detectLoginType(value) {
                        return value.includes('@') ? 'email' : 'username';
                    }

                    function validateLoginValue(raw) {
                        const value = normalizeLogin(raw.trim());
                        if (!value) {
                            return { ok: false, message: 'Informe o usuário ou e-mail.', normalized: value };
                        }

                        const type = detectLoginType(value);

                        if (type === 'email') {
                            if (/\s/.test(value)) {
                                return { ok: false, message: 'E-mail não pode conter espaços.', normalized: value, type };
                            }
                            if (!emailRegex.test(value)) {
                                return { ok: false, message: 'E-mail inválido.', normalized: value, type };
                            }
                            return { ok: true, message: '', normalized: value, type };
                        }

                        if (/^[._]/.test(value)) {
                            return { ok: false, message: 'Username não pode iniciar com "." ou "_".', normalized: value, type };
                        }
                        if (/\s/.test(value)) {
                            return { ok: false, message: 'Username não pode conter espaços.', normalized: value, type };
                        }
                        if (value.length < 3) {
                            return { ok: false, message: 'Username deve ter ao menos 3 caracteres.', normalized: value, type };
                        }
                        if (!/^[a-z0-9._]+$/.test(value)) {
                            return { ok: false, message: 'Use apenas letras minúsculas, números, "." ou "_".', normalized: value, type };
                        }

                        const specialCount = (value.match(/[._]/g) || []).length;
                        if (specialCount > 1) {
                            return { ok: false, message: 'Username pode ter no máximo um "." ou "_".', normalized: value, type };
                        }

                        return { ok: true, message: '', normalized: value, type };
                    }

                    function loadPersistedState() {
                        try {
                            attempts = Number(localStorage.getItem('loginAttempts') || '0') || 0;
                            lockUntil = Number(localStorage.getItem('loginLockUntil') || '0') || 0;
                        } catch (_) {
                            attempts = 0;
                            lockUntil = 0;
                        }
                    }

                    function persistState() {
                        try {
                            localStorage.setItem('loginAttempts', String(attempts));
                            localStorage.setItem('loginLockUntil', String(lockUntil));
                        } catch (_) {
                            // ignore persistence errors
                        }
                    }

                    function isLocked() {
                        return lockUntil > Date.now();
                    }

                    function remainingSeconds() {
                        return Math.max(0, Math.ceil((lockUntil - Date.now()) / 1000));
                    }

                    function stopLockTimer() {
                        if (lockInterval) {
                            clearInterval(lockInterval);
                            lockInterval = null;
                        }
                    }

                    function applyLockState(locked) {
                        if (!form) return;
                        const lockedMessage = `Muitas tentativas. Tente novamente em ${remainingSeconds()}s.`;
                        [loginInput, passwordInput, submitBtn].forEach(el => {
                            if (!el) return;
                            el.disabled = locked;
                            if (locked) {
                                el.classList.add('disabled');
                            } else {
                                el.classList.remove('disabled');
                            }
                        });
                        if (locked) {
                            if (feedback) {
                                feedback.textContent = lockedMessage;
                                feedback.classList.add('error');
                            }
                            stopLockTimer();
                            lockInterval = setInterval(() => {
                                if (!isLocked()) {
                                    stopLockTimer();
                                    applyLockState(false);
                                    return;
                                }
                                if (feedback) {
                                    feedback.textContent = `Muitas tentativas. Tente novamente em ${remainingSeconds()}s.`;
                                    feedback.classList.add('error');
                                }
                            }, 1000);
                        } else {
                            stopLockTimer();
                            if (feedback && feedback.textContent.startsWith('Muitas tentativas')) {
                                feedback.textContent = '';
                                feedback.classList.remove('error');
                            }
                        }
                    }

                    function lockForm() {
                        lockUntil = Date.now() + lockDurationMs;
                        attempts = 0;
                        persistState();
                        applyLockState(true);
                    }

                    loadPersistedState();
                    applyLockState(isLocked());

                    if (loginInput) {
                        loginInput.addEventListener('input', () => {
                            const normalized = normalizeLogin(loginInput.value);
                            if (loginInput.value !== normalized) {
                                loginInput.value = normalized;
                            }
                            const validation = validateLoginValue(loginInput.value);
                            if (feedback) {
                                if (!validation.ok && normalized) {
                                    feedback.textContent = validation.message;
                                    feedback.classList.add('error');
                                } else if (!normalized) {
                                    feedback.textContent = '';
                                    feedback.classList.remove('error', 'success');
                                } else {
                                    feedback.textContent = '';
                                    feedback.classList.remove('error');
                                }
                            }
                        });
                    }

                    function openModal() {
                        if (!modalOverlay) return;
                        modalOverlay.classList.add('show');
                        modalOverlay.setAttribute('aria-hidden', 'false');
                        if (loginInput) {
                            setTimeout(() => loginInput.focus(), 50);
                        }
                    }

                    function closeModal() {
                        if (!modalOverlay) return;
                        modalOverlay.classList.remove('show');
                        modalOverlay.setAttribute('aria-hidden', 'true');
                    }

                    [openLoginNav, openLoginCta].forEach(el => {
                        if (el) {
                            el.addEventListener('click', function(e) {
                                e.preventDefault();
                                openModal();
                            });
                        }
                    });

                    if (loginClose) {
                        loginClose.addEventListener('click', closeModal);
                    }
                    if (loginCancel) {
                        loginCancel.addEventListener('click', closeModal);
                    }
                    if (modalOverlay) {
                        modalOverlay.addEventListener('click', function(e) {
                            if (e.target === modalOverlay) {
                                closeModal();
                            }
                        });
                    }

                    if (!form) return;

                    form.addEventListener('submit', async function (e) {
                        e.preventDefault();
                        if (isLocked()) {
                            applyLockState(true);
                            return;
                        }
                        const validation = validateLoginValue(loginInput ? loginInput.value : '');
                        if (!validation.ok) {
                            if (feedback) {
                                feedback.textContent = validation.message;
                                feedback.classList.add('error');
                            }
                            if (loginInput) {
                                loginInput.focus();
                                loginInput.value = validation.normalized;
                            }
                            return;
                        }
                        if (feedback) {
                            feedback.textContent = '';
                            feedback.classList.remove('error', 'success');
                        }
                            if (submitBtn) {
                                submitBtn.disabled = true;
                                submitBtn.textContent = 'Autenticando...';
                            }

                        try {
                            const payload = {
                                login: validation.normalized,
                                senha: passwordInput ? passwordInput.value : ''
                            };

                            const response = await fetch('/api/auth/login', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify(payload)
                            });

                            const body = await response.json();
                            if (!response.ok) {
                                const mensagem = body.message || body.error || body.erro || 'Falha ao autenticar.';
                                throw new Error(mensagem);
                            }

                            attempts = 0;
                            lockUntil = 0;
                            persistState();

                            if (feedback) {
                                feedback.textContent = 'Login realizado. Redirecionando para o dashboard...';
                                feedback.classList.add('success');
                            }
                            setTimeout(() => {
                                window.location.href = '/dashboard';
                            }, 600);
                        } catch (err) {
                            if (feedback) {
                                feedback.textContent = err.message;
                                feedback.classList.add('error');
                            }
                            attempts += 1;
                            if (attempts >= maxAttempts) {
                                lockForm();
                                return;
                            }
                            persistState();
                            if (feedback && !isLocked()) {
                                const restantes = Math.max(0, maxAttempts - attempts);
                                feedback.textContent += ` (Tentativas restantes: ${restantes})`;
                            }
                        } finally {
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.textContent = 'Fazer login';
                            }
                            if (isLocked()) {
                                applyLockState(true);
                            }
                        }
                    });
                }

                updateData();
                updateDbStatus();
                setupLogin();
                checkSession();

                setInterval(updateData, 3000);
                setInterval(updateDbStatus, 3000);
            };
            </script>
        </main>
    </div>
</body>
</html>