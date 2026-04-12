<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($titulo ?? 'Vupi.us IDE — Login', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= htmlspecialchars((string)(filemtime(dirname(__DIR__, 3) . '/public/assets/css/style.css') ?: '1'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="/assets/css/ide-login.css?v=<?= htmlspecialchars((string)(filemtime(dirname(__DIR__, 3) . '/public/assets/css/ide-login.css') ?: '1'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        (function(){
            var dark = localStorage.getItem('home-dark-mode') === '1';
            if (dark) document.documentElement.classList.add('ide-pre-dark');
            document.addEventListener('DOMContentLoaded', function(){
                if (dark) document.body.classList.add('home-dark');
                document.documentElement.classList.remove('ide-pre-dark');
            });
        })();
    </script>
</head>
<body class="home-body ide-login-body">

<!-- ── NAVBAR ─────────────────────────────────────────────────────────── -->
<header class="home-nav" id="home-nav">
    <div class="home-nav-inner">
        <a href="/" class="home-nav-brand" aria-label="Vupi.us API — início">
            <?php if (!empty($logo_url)): ?>
                <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="home-nav-logo-img" />
            <?php else: ?>
                <img src="/favicon.ico" alt="Vupi.us API" class="home-nav-logo-img" />
            <?php endif; ?>
            <span class="home-nav-name">Vupi.us <span class="home-nav-name-accent">API</span></span>
        </a>
        <nav class="home-nav-links" aria-label="Navegação principal">
            <a href="/#features" class="home-nav-link"><i class="fa-solid fa-layer-group"></i> Recursos</a>
            <a href="/doc"       class="home-nav-link"><i class="fa-solid fa-book-open"></i> Docs</a>
            <a href="/dashboard/ide" class="home-nav-link home-nav-link-ide"><i class="fa-solid fa-code"></i> IDE</a>
        </nav>
        <div class="home-nav-actions">
            <button class="home-theme-toggle" id="home-theme-toggle" aria-label="Alternar tema">
                <i class="fa-solid fa-sun" id="home-theme-icon"></i>
            </button>
        </div>
        <button class="home-nav-hamburger" id="nav-hamburger" aria-label="Abrir menu" aria-expanded="false" aria-controls="nav-mobile">
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
        </button>
    </div>
    <div class="home-nav-mobile" id="nav-mobile" aria-hidden="true">
        <div class="home-nav-mobile-inner">
            <a href="/#features" class="home-nav-mobile-link">
                <span class="mobile-link-icon"><i class="fa-solid fa-layer-group"></i></span>
                <span>Recursos</span>
                <i class="fa-solid fa-chevron-right mobile-link-arrow"></i>
            </a>
            <a href="/doc" class="home-nav-mobile-link">
                <span class="mobile-link-icon"><i class="fa-solid fa-book-open"></i></span>
                <span>Docs</span>
                <i class="fa-solid fa-chevron-right mobile-link-arrow"></i>
            </a>
            <a href="/dashboard/ide" class="home-nav-mobile-link" style="color:#818cf8;">
                <span class="mobile-link-icon"><i class="fa-solid fa-code"></i></span>
                <span>IDE</span>
                <i class="fa-solid fa-chevron-right mobile-link-arrow"></i>
            </a>
            <div class="home-nav-mobile-divider"></div>
            <div class="home-nav-mobile-bottom">
                <button class="home-theme-toggle home-theme-toggle-mobile" id="home-theme-toggle-mobile" aria-label="Alternar tema">
                    <i class="fa-solid fa-sun" id="home-theme-icon-mobile"></i>
                    <span id="home-theme-label-mobile">Modo claro</span>
                </button>
            </div>
        </div>
    </div>
</header>

<!-- Background decorativo -->
<div class="ide-login-bg" aria-hidden="true">
    <div class="ide-login-orb ide-login-orb-1"></div>
    <div class="ide-login-orb ide-login-orb-2"></div>
    <div class="ide-login-orb ide-login-orb-3"></div>
</div>

<main class="ide-login-page">
    <div class="ide-login-card">

        <div class="ide-login-brand">
            <div class="ide-login-logo">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;border-radius:14px;">
                <?php else: ?>
                    <i class="fa-solid fa-code"></i>
                <?php endif; ?>
            </div>
            <h1 class="ide-login-title">Vupi.us <span>IDE</span></h1>
            <p class="ide-login-subtitle">Module Builder — Faça login para continuar</p>
        </div>

        <form id="ide-login-form" class="ide-login-form" autocomplete="off" novalidate>

            <div class="ide-lf-group">
                <label class="ide-lf-label" for="ide-login-input">
                    <i class="fa-solid fa-user"></i> Login
                </label>
                <input type="text" id="ide-login-input" class="ide-lf-input"
                       name="login"
                       placeholder="email ou username"
                       autocomplete="username" aria-required="true"
                       spellcheck="false" autocorrect="off" autocapitalize="none"
                       maxlength="254">
                <span class="ide-lf-type-hint" id="ide-login-type-hint"></span>
                <span class="ide-lf-field-error" id="ide-login-input-hint" style="display:none;"></span>
            </div>

            <div class="ide-lf-group">
                <label class="ide-lf-label" for="ide-password-input">
                    <i class="fa-solid fa-lock"></i> Senha
                </label>
                <div class="ide-lf-input-wrap">
                    <input type="password" id="ide-password-input" class="ide-lf-input"
                           name="senha"
                           placeholder="••••••••"
                           autocomplete="current-password" aria-required="true"
                           maxlength="128">
                    <button type="button" class="ide-lf-eye" id="ide-toggle-password" aria-label="Mostrar senha">
                        <i class="fa-solid fa-eye" id="ide-eye-icon"></i>
                    </button>
                </div>
            </div>

            <div id="ide-login-error" class="ide-lf-error" role="alert" aria-live="assertive" style="display:none;"></div>

            <button type="submit" class="ide-lf-submit" id="ide-btn-login">
                <i class="fa-solid fa-right-to-bracket"></i>
                <span>Entrar na IDE</span>
            </button>

        </form>

        <p class="ide-login-footer">
            <a href="/" class="ide-login-back-link">
                <i class="fa-solid fa-arrow-left"></i> Voltar ao início
            </a>
        </p>

    </div>
</main>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
    'use strict';

    // ── Tema ──────────────────────────────────────────────────────────────
    var DARK_KEY = 'home-dark-mode';

    function applyTheme(dark) {
        document.body.classList.toggle('home-dark', dark);
        ['home-theme-icon', 'home-theme-icon-mobile'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        });
        var lbl = document.getElementById('home-theme-label-mobile');
        if (lbl) lbl.textContent = dark ? 'Modo claro' : 'Modo escuro';
    }

    applyTheme(localStorage.getItem(DARK_KEY) === '1');

    ['home-theme-toggle', 'home-theme-toggle-mobile'].forEach(function (id) {
        var btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', function () {
            var nowDark = document.body.classList.toggle('home-dark');
            localStorage.setItem(DARK_KEY, nowDark ? '1' : '0');
            applyTheme(nowDark);
        });
    });

    // ── Hamburger mobile ──────────────────────────────────────────────────
    var hamburger  = document.getElementById('nav-hamburger');
    var mobileMenu = document.getElementById('nav-mobile');
    if (hamburger && mobileMenu) {
        hamburger.addEventListener('click', function () {
            var isOpen = mobileMenu.getAttribute('aria-hidden') === 'false';
            mobileMenu.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
            hamburger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            mobileMenu.style.display = isOpen ? '' : 'block';
        });
    }

    // ── Validação ─────────────────────────────────────────────────────────
    function isEmail(val)        { return val.indexOf('@') !== -1; }
    function validateEmail(val)  { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val); }
    function validateUsername(v) { return /^[a-zA-Z0-9_\-\.]{3,64}$/.test(v); }
    function sanitize(val)       { return val.replace(/\s/g, ''); }

    function showFieldError(id, msg) {
        var inp  = document.getElementById(id);
        var hint = document.getElementById(id + '-hint');
        if (inp)  inp.classList.add('ide-lf-input-error');
        if (hint) { hint.textContent = msg; hint.style.display = 'flex'; }
    }

    function clearFieldError(id) {
        var inp  = document.getElementById(id);
        var hint = document.getElementById(id + '-hint');
        if (inp)  inp.classList.remove('ide-lf-input-error');
        if (hint) hint.style.display = 'none';
    }

    // Exibe erro global usando DOM seguro (sem innerHTML)
    function showError(msg) {
        var errEl = document.getElementById('ide-login-error');
        if (!errEl) return;
        errEl.textContent = '';
        var ic = document.createElement('i');
        ic.className = 'fa-solid fa-circle-exclamation';
        ic.setAttribute('aria-hidden', 'true');
        var txt = document.createTextNode(' ' + msg);
        errEl.appendChild(ic);
        errEl.appendChild(txt);
        errEl.style.display = 'flex';
    }

    function hideError() {
        var errEl = document.getElementById('ide-login-error');
        if (errEl) { errEl.textContent = ''; errEl.style.display = 'none'; }
    }

    // Atualiza botão com ícone + texto sem innerHTML
    function setBtn(btn, iconClass, text) {
        btn.textContent = '';
        var ic = document.createElement('i');
        ic.className = iconClass;
        ic.setAttribute('aria-hidden', 'true');
        var sp = document.createElement('span');
        sp.textContent = text;
        btn.appendChild(ic);
        btn.appendChild(document.createTextNode(' '));
        btn.appendChild(sp);
    }

    function updateHint(val) {
        var hint = document.getElementById('ide-login-type-hint');
        if (!hint) return;
        if (!val) { hint.textContent = ''; hint.className = 'ide-lf-type-hint'; return; }
        if (isEmail(val)) {
            var ok = validateEmail(val);
            hint.textContent = ok ? '✓ E-mail válido' : '✗ E-mail inválido';
            hint.className   = 'ide-lf-type-hint ' + (ok ? 'hint-ok' : 'hint-err');
        } else {
            var ok2 = validateUsername(val);
            hint.textContent = ok2 ? '✓ Username válido' : '✗ Username inválido (3-64 chars, sem espaços)';
            hint.className   = 'ide-lf-type-hint ' + (ok2 ? 'hint-ok' : 'hint-err');
        }
    }

    // ── Campo login: bloqueia caracteres inválidos em tempo real ─────────
    var loginInp = document.getElementById('ide-login-input');
    if (loginInp) {

        // Bloqueia antes de inserir — mais responsivo que corrigir depois
        loginInp.addEventListener('beforeinput', function (e) {
            if (!e.data) return; // backspace, delete, etc.

            var current  = loginInp.value;
            var cursorAt = loginInp.selectionStart != null ? loginInp.selectionStart : current.length;
            var emailMode = current.indexOf('@') !== -1 || e.data.indexOf('@') !== -1;

            // Espaço nunca permitido
            if (/\s/.test(e.data)) { e.preventDefault(); return; }

            if (!emailMode) {
                var normalized = e.data.toLowerCase();
                // Username: só letras minúsculas, números, ponto e underline
                if (!/^[a-z0-9._]+$/.test(normalized)) { e.preventDefault(); return; }
                // Não pode iniciar com '.' ou '_'
                if (cursorAt === 0 && /^[._]/.test(normalized)) { e.preventDefault(); return; }
                // Máximo de um caractere especial (. ou _) no total
                var specialCount = (current.match(/[._]/g) || []).length;
                if (/[._]/.test(normalized) && specialCount >= 1) { e.preventDefault(); return; }
            }
        });

        // Sanitiza colagem
        loginInp.addEventListener('paste', function (e) {
            e.preventDefault();
            var pasted  = (e.clipboardData || window.clipboardData).getData('text');
            var current = loginInp.value;
            var emailMode = current.indexOf('@') !== -1 || pasted.indexOf('@') !== -1;
            var clean;
            if (emailMode) {
                clean = pasted.replace(/\s/g, '').toLowerCase();
            } else {
                clean = pasted.toLowerCase().replace(/[^a-z0-9._]/g, '');
                var specials = 0;
                clean = clean.split('').filter(function (c) {
                    if (/[._]/.test(c)) { specials++; return specials <= 1; }
                    return true;
                }).join('');
            }
            var start  = loginInp.selectionStart != null ? loginInp.selectionStart : current.length;
            var end    = loginInp.selectionEnd   != null ? loginInp.selectionEnd   : current.length;
            var newVal = current.slice(0, start) + clean + current.slice(end);
            loginInp.value = newVal;
            var newCursor = start + clean.length;
            try { loginInp.setSelectionRange(newCursor, newCursor); } catch (_) {}
            loginInp.dispatchEvent(new Event('input'));
        });

        loginInp.addEventListener('input', function () {
            var pos   = loginInp.selectionStart;
            var clean = loginInp.value.toLowerCase().replace(/\s/g, '');
            if (clean !== loginInp.value) {
                loginInp.value = clean;
                try { loginInp.setSelectionRange(pos, pos); } catch (_) {}
            }
            clearFieldError('ide-login-input');
            updateHint(clean);
        });

        loginInp.addEventListener('blur', function () {
            var val = sanitize(this.value);
            this.value = val;
            if (val && isEmail(val) && !validateEmail(val)) {
                showFieldError('ide-login-input', 'Endereço de e-mail inválido.');
            } else if (val && !isEmail(val) && !validateUsername(val)) {
                showFieldError('ide-login-input', 'Username inválido. Use 3-64 caracteres, sem espaços.');
            }
        });
    }

    // ── Toggle senha ──────────────────────────────────────────────────────
    var togglePw = document.getElementById('ide-toggle-password');
    if (togglePw) {
        togglePw.addEventListener('click', function () {
            var inp  = document.getElementById('ide-password-input');
            var ic   = document.getElementById('ide-eye-icon');
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            if (ic) ic.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            this.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
        });
    }

    // ── Submit ────────────────────────────────────────────────────────────
    var form = document.getElementById('ide-login-form');
    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            var loginVal = sanitize(document.getElementById('ide-login-input').value);
            var senhaVal = document.getElementById('ide-password-input').value.trim();
            var btn      = document.getElementById('ide-btn-login');

            hideError();
            clearFieldError('ide-login-input');

            // Validação client-side
            if (!loginVal) {
                showFieldError('ide-login-input', 'Informe seu e-mail ou username.');
                showError('Preencha todos os campos.');
                document.getElementById('ide-login-input').focus();
                return;
            }

            if (isEmail(loginVal) && !validateEmail(loginVal)) {
                showFieldError('ide-login-input', 'Endereço de e-mail inválido.');
                showError('Endereço de e-mail inválido.');
                return;
            }

            if (!isEmail(loginVal) && !validateUsername(loginVal)) {
                showFieldError('ide-login-input', 'Username inválido. Use 3-64 caracteres, sem espaços.');
                showError('Username inválido.');
                return;
            }

            if (!senhaVal) {
                showError('Informe sua senha.');
                document.getElementById('ide-password-input').focus();
                return;
            }

            if (senhaVal.length < 6) {
                showError('Senha deve ter pelo menos 6 caracteres.');
                return;
            }

            btn.disabled = true;
            setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Entrando...');

            try {
                var res  = await fetch('/api/login', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ login: loginVal, senha: senhaVal })
                });
                var data = await res.json();

                if (!res.ok) {
                    throw new Error(data.message || data.error || 'Credenciais inválidas.');
                }

                // O cookie auth_token já foi setado pelo servidor via Set-Cookie (HttpOnly, Secure)
                // O JS não precisa e não deve setar cookies de autenticação
                setBtn(btn, 'fa-solid fa-check', 'Sucesso!');
                setTimeout(function () { window.location.replace('/dashboard/ide'); }, 300);

            } catch (err) {
                showError(String(err.message));
                btn.disabled = false;
                setBtn(btn, 'fa-solid fa-right-to-bracket', 'Entrar na IDE');
                document.getElementById('ide-password-input').select();
            }
        });
    }

})();
</script>
</body>
</html>
