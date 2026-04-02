<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo ?? 'Sweflow API', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="home-body">

    <!-- NAV -->
    <header class="home-nav" id="home-nav">
        <div class="home-nav-inner">

            <!-- Brand -->
            <a href="/" class="home-nav-brand" aria-label="Sweflow API — início">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="home-nav-logo-img" />
                <?php else: ?>
                    <img src="/favicon.ico" alt="Sweflow API" class="home-nav-logo-img" />
                <?php endif; ?>
                <span class="home-nav-name">Sweflow <span class="home-nav-name-accent">API</span></span>
            </a>

            <!-- Links desktop -->
            <nav class="home-nav-links" aria-label="Navegação principal">
                <a href="#features" class="home-nav-link">
                    <i class="fa-solid fa-layer-group"></i> Recursos
                </a>
                <a href="#docs" class="home-nav-link">
                    <i class="fa-solid fa-book-open"></i> Docs
                </a>
            </nav>

            <!-- Ações desktop -->
            <div class="home-nav-actions">
                <button class="home-theme-toggle" id="home-theme-toggle" aria-label="Alternar tema" title="Alternar dark/light">
                    <i class="fa-solid fa-sun" id="home-theme-icon"></i>
                </button>
                <button class="btn-nav-login" id="open-login">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    <span id="nav-login-text">Entrar</span>
                </button>
            </div>

            <!-- Hamburger mobile -->
            <button class="home-nav-hamburger" id="nav-hamburger" aria-label="Abrir menu" aria-expanded="false" aria-controls="nav-mobile">
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
            </button>
        </div>

        <!-- Menu mobile -->
        <div class="home-nav-mobile" id="nav-mobile" aria-hidden="true">
            <div class="home-nav-mobile-inner">
                <a href="#features" class="home-nav-mobile-link">
                    <span class="mobile-link-icon"><i class="fa-solid fa-layer-group"></i></span>
                    <span>Recursos</span>
                    <i class="fa-solid fa-chevron-right mobile-link-arrow"></i>
                </a>
                <a href="#docs" class="home-nav-mobile-link">
                    <span class="mobile-link-icon"><i class="fa-solid fa-book-open"></i></span>
                    <span>Docs</span>
                    <i class="fa-solid fa-chevron-right mobile-link-arrow"></i>
                </a>
                <div class="home-nav-mobile-divider"></div>
                <div class="home-nav-mobile-bottom">
                    <button class="home-theme-toggle home-theme-toggle-mobile" id="home-theme-toggle-mobile" aria-label="Alternar tema">
                        <i class="fa-solid fa-sun" id="home-theme-icon-mobile"></i>
                        <span id="home-theme-label-mobile">Modo claro</span>
                    </button>
                    <button class="btn-nav-login btn-nav-login-mobile" id="open-login-mobile">
                        <i class="fa-solid fa-right-to-bracket"></i>
                        <span id="nav-login-text-mobile">Entrar no Dashboard</span>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- HERO -->
    <section class="home-hero">
        <div class="home-hero-inner">
            <div class="home-hero-badge">
                <i class="fa-solid fa-bolt"></i> Modular &amp; Extensível
            </div>
            <h1 class="home-hero-title">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="home-hero-logo" />
                <?php endif; ?>
                Sweflow API
            </h1>
            <p class="home-hero-sub"><?= htmlspecialchars($descricao ?? '', ENT_QUOTES, 'UTF-8') ?></p>
            <div class="home-hero-actions">
                <button class="btn-hero-primary" id="cta-open-login">
                    <i class="fa-solid fa-door-open"></i>
                    <span id="cta-login-text">Acessar Dashboard</span>
                </button>
                <a href="#features" class="btn-hero-ghost">
                    <i class="fa-solid fa-circle-info"></i> Saiba mais
                </a>
            </div>
        </div>
        <div class="home-hero-visual" aria-hidden="true">
            <div class="hero-code-block">
                <div class="hero-code-bar">
                    <span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span>
                    <span class="hero-code-label">GET /api/status</span>
                </div>
                <pre class="hero-code-pre"><code><span class="c-brace">{</span>
  <span class="c-key">"status"</span><span class="c-colon">:</span> <span class="c-str">"ok"</span>
<span class="c-brace">}</span></code></pre>
            </div>
        </div>
    </section>

    <!-- FEATURES -->
    <section class="home-features" id="features">
        <div class="home-section-inner">
            <h2 class="home-section-title">Recursos principais</h2>
            <p class="home-section-sub">Tudo que você precisa para construir APIs robustas e seguras.</p>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-layer-group"></i></div>
                    <h3>Módulos dinâmicos</h3>
                    <p>Ative, desative e crie módulos sem reiniciar o servidor. Detecção automática de rotas.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <h3>Segurança integrada</h3>
                    <p>JWT, rate limiting, audit log, circuit breaker e headers de segurança prontos para produção.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-plug"></i></div>
                    <h3>Sistema de plugins</h3>
                    <p>Instale e gerencie plugins via CLI ou marketplace. Migrations e seeders automáticos.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-database"></i></div>
                    <h3>Multi-banco</h3>
                    <p>Suporte nativo a MySQL e PostgreSQL com factory de conexão e tratamento de exceções.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-envelope"></i></div>
                    <h3>E-mail transacional</h3>
                    <p>Templates HTML, verificação de conta, recuperação de senha e reenvio automático.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-terminal"></i></div>
                    <h3>CLI poderosa</h3>
                    <p>Comandos para setup, migrations, seeders, geração de JWT e gerenciamento de módulos.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- DOCS / QUICK START -->
    <section class="home-docs" id="docs">
        <div class="home-section-inner">
            <h2 class="home-section-title">Início rápido</h2>
            <p class="home-section-sub">Três passos para ter a API rodando.</p>
            <div class="docs-steps">
                <div class="docs-step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <h3>Configure o ambiente</h3>
                        <p>Copie o arquivo de exemplo e ajuste as variáveis.</p>
                        <div class="code-snippet"><code>cp EXEMPLO.env .env</code></div>
                    </div>
                </div>
                <div class="docs-step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <h3>Suba o banco de dados</h3>
                        <p>Use Docker Compose para subir o MySQL/PostgreSQL.</p>
                        <div class="code-snippet"><code>docker-compose up -d</code></div>
                    </div>
                </div>
                <div class="docs-step">
                    <div class="step-num">3</div>
                    <div class="step-body">
                        <h3>Execute o setup</h3>
                        <p>O CLI cuida de migrations, seeders e servidor.</p>
                        <div class="code-snippet"><code>php db setup</code></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="home-footer">
        <div class="home-footer-inner">
            <span class="home-footer-brand">
                <img src="/favicon.ico" alt="Sweflow API" style="width:20px;height:20px;border-radius:5px;object-fit:contain;vertical-align:middle;" />
                Sweflow API
            </span>
            <span class="home-footer-copy">&copy; <?= date('Y') ?> Sweflow &mdash; Desenvolvido por Adimael</span>
        </div>
    </footer>

    <!-- MODAL LOGIN -->
    <div class="modal-overlay" id="login-modal" aria-hidden="true">
        <div class="lm-box" role="dialog" aria-modal="true" aria-labelledby="login-modal-title">

            <div class="lm-panel">
                <button type="button" class="lm-close" id="login-close" aria-label="Fechar modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>

                <div class="lm-panel-top">
                    <div class="lm-brand-icon-sm">
                        <?php if (!empty($logo_url)): ?>
                            <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;border-radius:12px;" />
                        <?php else: ?>
                            <img src="/favicon.ico" alt="Sweflow API" style="width:100%;height:100%;object-fit:contain;" />
                        <?php endif; ?>
                    </div>
                    <h2 id="login-modal-title" class="lm-title">Acesso administrativo</h2>
                    <p class="lm-subtitle">Entre com suas credenciais para continuar</p>
                </div>

                <form id="login-form" class="lm-form">
                    <div class="lm-field">
                        <label for="login-user" class="lm-label">
                            Usuário ou e-mail
                        </label>
                        <div class="lm-input-wrap">
                            <span class="lm-input-icon"><i class="fa-solid fa-user"></i></span>
                            <input type="text" id="login-user" name="login"
                                   class="lm-input" placeholder="admin ou email@exemplo.com"
                                   autocomplete="username" required />
                        </div>
                    </div>

                    <div class="lm-field">
                        <label for="login-password" class="lm-label">Senha</label>
                        <div class="lm-input-wrap">
                            <span class="lm-input-icon"><i class="fa-solid fa-key"></i></span>
                            <input type="password" id="login-password" name="senha"
                                   class="lm-input" placeholder="••••••••"
                                   autocomplete="current-password" required />
                            <button type="button" class="lm-toggle-pw" id="toggle-password" aria-label="Mostrar senha" tabindex="-1">
                                <i class="fa-solid fa-eye" id="toggle-pw-icon"></i>
                            </button>
                        </div>
                    </div>

                    <div id="login-feedback" class="lm-feedback" aria-live="polite"></div>

                    <button type="submit" id="login-submit" class="lm-submit">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                        <span id="login-submit-text">Entrar</span>
                    </button>

                    <button type="button" class="lm-cancel" id="login-cancel">
                        Cancelar
                    </button>
                </form>
            </div>

        </div>
    </div>

    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
    // ── Tema home (light padrão) ─────────────────────────────
    (function () {
        const DARK_KEY = 'home-dark-mode';
        const body = document.body;
        const saved = localStorage.getItem(DARK_KEY);
        // Light é padrão — só aplica dark se o usuário salvou
        if (saved === '1') body.classList.add('home-dark');

        function applyTheme(dark) {
            body.classList.toggle('home-dark', dark);
            const icons  = ['home-theme-icon', 'home-theme-icon-mobile'];
            const labels = document.getElementById('home-theme-label-mobile');
            icons.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
            });
            if (labels) labels.textContent = dark ? 'Modo claro' : 'Modo escuro';
        }

        document.addEventListener('DOMContentLoaded', function () {
            const isDark = body.classList.contains('home-dark');
            applyTheme(isDark);

            ['home-theme-toggle', 'home-theme-toggle-mobile'].forEach(id => {
                const btn = document.getElementById(id);
                if (btn) {
                    btn.addEventListener('click', () => {
                        const nowDark = body.classList.toggle('home-dark');
                        localStorage.setItem(DARK_KEY, nowDark ? '1' : '0');
                        applyTheme(nowDark);
                    });
                }
            });
        });
    })();
    </script>
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
    function initPage() {
        const SESSION_FLAG = 'hasAuthSession';

        // ── Nav scroll shadow ───────────────────────────────────────────
        const nav = document.getElementById('home-nav');
        if (nav) {
            const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 10);
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        }

        // ── Hamburger menu ──────────────────────────────────────────────
        const hamburger = document.getElementById('nav-hamburger');
        const mobileNav = document.getElementById('nav-mobile');
        if (hamburger && mobileNav) {
            hamburger.addEventListener('click', () => {
                const open = mobileNav.classList.toggle('open');
                hamburger.setAttribute('aria-expanded', String(open));
                mobileNav.setAttribute('aria-hidden', String(!open));
                hamburger.classList.toggle('is-open', open);
            });
            // Fecha ao clicar em link mobile
            mobileNav.querySelectorAll('.home-nav-mobile-link').forEach(link => {
                link.addEventListener('click', () => {
                    mobileNav.classList.remove('open');
                    hamburger.setAttribute('aria-expanded', 'false');
                    mobileNav.setAttribute('aria-hidden', 'true');
                    hamburger.classList.remove('is-open');
                });
            });
        }

        // ── Smooth scroll para links internos ───────────────────────────
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', e => {
                const target = document.querySelector(a.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    if (mobileNav) mobileNav.classList.remove('open');
                }
            });
        });

        // ── Modal helpers ───────────────────────────────────────────────
        const loginModal  = document.getElementById('login-modal');
        const loginClose  = document.getElementById('login-close');
        const loginCancel = document.getElementById('login-cancel');

        function openModal() {
            if (!loginModal) return;
            loginModal.classList.add('show');
            loginModal.setAttribute('aria-hidden', 'false');
            const inp = document.getElementById('login-user');
            if (inp) setTimeout(() => inp.focus(), 50);
        }

        function closeModal() {
            if (!loginModal) return;
            loginModal.classList.remove('show');
            loginModal.setAttribute('aria-hidden', 'true');
        }

        if (loginClose)  loginClose.addEventListener('click', closeModal);
        if (loginCancel) loginCancel.addEventListener('click', closeModal);

        // ── Login label update ──────────────────────────────────────────
        function updateLoginLabels(isAuthed) {
            ['nav-login-text', 'nav-login-text-mobile', 'cta-login-text'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (id === 'cta-login-text') {
                    el.textContent = isAuthed ? 'Ir para Dashboard' : 'Acessar Dashboard';
                } else {
                    el.textContent = isAuthed ? 'Dashboard' : 'Login';
                }
            });
        }

        async function checkSession() {
            if (localStorage.getItem(SESSION_FLAG) !== '1') {
                updateLoginLabels(false);
                return false;
            }
            try {
                const res = await fetch('/api/auth/me', { credentials: 'same-origin' });
                if (res.ok) { updateLoginLabels(true); return true; }
                if (res.status === 401 || res.status === 403) localStorage.removeItem(SESSION_FLAG);
            } catch (_) {}
            updateLoginLabels(false);
            return false;
        }

        // ── Open login / redirect ───────────────────────────────────────
        async function handleLoginClick(e) {
            e.preventDefault();
            e.stopPropagation();
            // Se não tem flag de sessão, abre o modal direto — sem fetch
            if (localStorage.getItem(SESSION_FLAG) !== '1') {
                openModal();
                return;
            }
            // Tem flag: verifica se ainda é válida antes de redirecionar
            try {
                const res = await fetch('/api/auth/me', { credentials: 'same-origin' });
                if (res.ok) { window.location.href = '/dashboard'; return; }
                localStorage.removeItem(SESSION_FLAG);
            } catch (_) {
                localStorage.removeItem(SESSION_FLAG);
            }
            openModal();
        }

        ['open-login', 'open-login-mobile', 'cta-open-login'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', handleLoginClick);
        });

        // ── Toggle senha ────────────────────────────────────────────
        const togglePwBtn  = document.getElementById('toggle-password');
        const togglePwIcon = document.getElementById('toggle-pw-icon');
        const pwInput      = document.getElementById('login-password');
        if (togglePwBtn && pwInput) {
            togglePwBtn.addEventListener('click', () => {
                const show = pwInput.type === 'password';
                pwInput.type = show ? 'text' : 'password';
                if (togglePwIcon) {
                    togglePwIcon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
                }
                togglePwBtn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
            });
        }

        // ── Login form ──────────────────────────────────────────────────
        (function setupLogin() {
            const form         = document.getElementById('login-form');
            const loginInput   = document.getElementById('login-user');
            const passwordInput= document.getElementById('login-password');
            const feedback     = document.getElementById('login-feedback');
            const submitBtn    = document.getElementById('login-submit');
            const submitText   = document.getElementById('login-submit-text');
            const maxAttempts  = 5;
            const lockMs       = 30000;
            let attempts = 0, lockUntil = 0, lockInterval = null;
            const emailRe = /^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/;

            function normalize(v) { return (v || '').toLowerCase(); }

            function validate(raw) {
                const v = normalize(raw.trim());
                if (!v) return { ok: false, message: 'Informe o usuário ou e-mail.', normalized: v };
                if (v.includes('@')) {
                    if (/\s/.test(v)) return { ok: false, message: 'E-mail não pode conter espaços.', normalized: v };
                    if (!emailRe.test(v)) return { ok: false, message: 'E-mail inválido.', normalized: v };
                    return { ok: true, message: '', normalized: v };
                }
                if (/^[._]/.test(v)) return { ok: false, message: 'Username não pode iniciar com "." ou "_".', normalized: v };
                if (/\s/.test(v))    return { ok: false, message: 'Username não pode conter espaços.', normalized: v };
                if (v.length < 3)    return { ok: false, message: 'Username deve ter ao menos 3 caracteres.', normalized: v };
                if (!/^[a-z0-9._]+$/.test(v)) return { ok: false, message: 'Use apenas letras minúsculas, números, "." ou "_".', normalized: v };
                if ((v.match(/[._]/g) || []).length > 1) return { ok: false, message: 'Username pode ter no máximo um "." ou "_".', normalized: v };
                return { ok: true, message: '', normalized: v };
            }

            function isLocked() { return lockUntil > Date.now(); }
            function remaining() { return Math.max(0, Math.ceil((lockUntil - Date.now()) / 1000)); }

            function stopTimer() { if (lockInterval) { clearInterval(lockInterval); lockInterval = null; } }

            function applyLock(locked) {
                [loginInput, passwordInput, submitBtn].forEach(el => {
                    if (!el) return;
                    el.disabled = locked;
                    el.classList.toggle('disabled', locked);
                });
                if (locked) {
                    if (feedback) { feedback.textContent = `Muitas tentativas. Tente novamente em ${remaining()}s.`; feedback.className = 'lm-feedback error'; }
                    stopTimer();
                    lockInterval = setInterval(() => {
                        if (!isLocked()) { stopTimer(); applyLock(false); return; }
                        if (feedback) feedback.textContent = `Muitas tentativas. Tente novamente em ${remaining()}s.`;
                    }, 1000);
                } else {
                    stopTimer();
                    if (feedback && feedback.textContent.startsWith('Muitas')) { feedback.textContent = ''; feedback.className = 'lm-feedback'; }
                }
            }

            try {
                attempts  = Number(localStorage.getItem('loginAttempts') || '0') || 0;
                lockUntil = Number(localStorage.getItem('loginLockUntil') || '0') || 0;
            } catch (_) {}
            applyLock(isLocked());

            if (loginInput) {
                loginInput.addEventListener('input', () => {
                    const n = normalize(loginInput.value);
                    if (loginInput.value !== n) loginInput.value = n;
                    const validation = validate(loginInput.value);
                    if (feedback) {
                        feedback.textContent = (!validation.ok && n) ? validation.message : '';
                        feedback.className = 'lm-feedback' + ((!validation.ok && n) ? ' error' : '');
                    }
                });
            }

            if (!form) return;
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (isLocked()) { applyLock(true); return; }
                const r = validate(loginInput ? loginInput.value : '');
                if (!r.ok) {
                    if (feedback) { feedback.textContent = r.message; feedback.className = 'lm-feedback error'; }
                    if (loginInput) { loginInput.focus(); loginInput.value = r.normalized; }
                    return;
                }
                if (feedback) { feedback.textContent = ''; feedback.className = 'lm-feedback'; }
                if (submitBtn) { submitBtn.disabled = true; if (submitText) submitText.textContent = 'Autenticando...'; }

                try {
                    const res  = await fetch('/api/auth/login', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ login: r.normalized, senha: passwordInput ? passwordInput.value : '' })
                    });
                    const body = await res.json();

                    if (!res.ok) {
                        if (res.status === 403 && body.email_not_verified) {
                            const userEmail = body.email || r.normalized;
                            if (feedback) {
                                feedback.className = 'lm-feedback error';
                                feedback.innerHTML = '';
                                const msg = document.createElement('span');
                                msg.textContent = body.message || 'Confirme seu e-mail antes de fazer login.';
                                feedback.appendChild(msg);
                                feedback.appendChild(document.createElement('br'));
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.textContent = 'Reenviar e-mail de confirmação';
                                btn.style.cssText = 'margin-top:8px;padding:6px 14px;background:#4f46e5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;';
                                btn.addEventListener('click', async () => {
                                    btn.disabled = true; btn.textContent = 'Enviando...';
                                    try {
                                        const d = await (await fetch('/api/auth/reenviar-verificacao', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: userEmail }) })).json();
                                        feedback.innerHTML = '';
                                        feedback.textContent = d.message || 'E-mail enviado. Verifique sua caixa de entrada.';
                                        feedback.className = 'lm-feedback success';
                                    } catch (_) { btn.disabled = false; btn.textContent = 'Reenviar e-mail de confirmação'; }
                                });
                                feedback.appendChild(btn);
                            }
                            return;
                        }
                        if (res.status === 403) {
                            if (feedback) { feedback.textContent = 'Acesso restrito.'; feedback.className = 'lm-feedback error'; }
                            return;
                        }
                        throw new Error(body.message || body.error || body.erro || 'Falha ao autenticar.');
                    }

                    attempts = 0; lockUntil = 0;
                    try { localStorage.setItem('loginAttempts', '0'); localStorage.setItem('loginLockUntil', '0'); } catch (_) {}
                    if (feedback) { feedback.textContent = 'Login realizado. Redirecionando...'; feedback.className = 'lm-feedback success'; }
                    try { localStorage.setItem(SESSION_FLAG, '1'); } catch (_) {}
                    setTimeout(() => { window.location.href = '/dashboard'; }, 600);

                } catch (err) {
                    if (feedback) { feedback.textContent = err.message; feedback.className = 'lm-feedback error'; }
                    attempts++;
                    if (attempts >= maxAttempts) {
                        lockUntil = Date.now() + lockMs; attempts = 0;
                        try { localStorage.setItem('loginAttempts', '0'); localStorage.setItem('loginLockUntil', String(lockUntil)); } catch (_) {}
                        applyLock(true); return;
                    }
                    try { localStorage.setItem('loginAttempts', String(attempts)); } catch (_) {}
                    if (feedback && !isLocked()) feedback.textContent += ` (Tentativas restantes: ${Math.max(0, maxAttempts - attempts)})`;
                } finally {
                    if (submitBtn && !isLocked()) {
                        submitBtn.disabled = false;
                        if (submitText) submitText.textContent = 'Fazer login';
                    }
                }
            });
        })();

        // ── Init ────────────────────────────────────────────────────────
        updateLoginLabels(localStorage.getItem(SESSION_FLAG) === '1');
        checkSession();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPage);
    } else {
        initPage();
    }
    </script>
</body>
</html>
