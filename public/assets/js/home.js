'use strict';

function initPage() {
    const SESSION_FLAG = 'hasAuthSession';

    // ── Nav scroll shadow ─────────────────────────────────────────────────
    const nav = document.getElementById('home-nav');
    if (nav) {
        const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 10);
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // ── Hamburger menu ────────────────────────────────────────────────────
    const hamburger = document.getElementById('nav-hamburger');
    const mobileNav = document.getElementById('nav-mobile');
    if (hamburger && mobileNav) {
        hamburger.addEventListener('click', () => {
            const open = mobileNav.classList.toggle('open');
            hamburger.setAttribute('aria-expanded', String(open));
            mobileNav.setAttribute('aria-hidden', String(!open));
            hamburger.classList.toggle('is-open', open);
        });
        mobileNav.querySelectorAll('.home-nav-mobile-link').forEach(link => {
            link.addEventListener('click', () => {
                mobileNav.classList.remove('open');
                hamburger.setAttribute('aria-expanded', 'false');
                mobileNav.setAttribute('aria-hidden', 'true');
                hamburger.classList.remove('is-open');
            });
        });
    }

    // ── Smooth scroll ─────────────────────────────────────────────────────
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

    // ── Modal helpers ─────────────────────────────────────────────────────
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

    // ── Login label update ────────────────────────────────────────────────
    function updateLoginLabels(isAuthed) {
        ['nav-login-text', 'nav-login-text-mobile', 'cta-login-text'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = id === 'cta-login-text'
                ? (isAuthed ? 'Ir para Dashboard' : 'Acessar Dashboard')
                : (isAuthed ? 'Dashboard' : 'Login');
        });
    }

    async function checkSession() {
        try {
            const res = await fetch('/api/auth/me', { credentials: 'same-origin' });
            if (res.ok) {
                try { localStorage.setItem(SESSION_FLAG, '1'); } catch(_) {}
                updateLoginLabels(true);
                return true;
            }
            // Sessão inválida — limpa flag
            try { localStorage.removeItem(SESSION_FLAG); } catch(_) {}
        } catch (_) {}
        updateLoginLabels(false);
        return false;
    }

    // ── Open login / redirect ─────────────────────────────────────────────
    async function handleLoginClick(e) {
        e.preventDefault();
        e.stopPropagation();
        try {
            const res = await fetch('/api/auth/me', { credentials: 'same-origin' });
            if (res.ok) {
                try { localStorage.setItem(SESSION_FLAG, '1'); } catch(_) {}
                window.location.href = '/dashboard';
                return;
            }
            try { localStorage.removeItem(SESSION_FLAG); } catch(_) {}
        } catch (_) {}
        openModal();
    }

    ['open-login', 'open-login-mobile', 'cta-open-login'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', handleLoginClick);
    });

    // ── Toggle senha ──────────────────────────────────────────────────────
    const togglePwBtn  = document.getElementById('toggle-password');
    const togglePwIcon = document.getElementById('toggle-pw-icon');
    const pwInput      = document.getElementById('login-password');
    if (togglePwBtn && pwInput) {
        togglePwBtn.addEventListener('click', () => {
            const show = pwInput.type === 'password';
            pwInput.type = show ? 'text' : 'password';
            if (togglePwIcon) togglePwIcon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            togglePwBtn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
        });
    }

    // ── Login form ────────────────────────────────────────────────────────
    (function setupLogin() {
        const form          = document.getElementById('login-form');
        const loginInput    = document.getElementById('login-user');
        const passwordInput = document.getElementById('login-password');
        const feedback      = document.getElementById('login-feedback');
        const submitBtn     = document.getElementById('login-submit');
        const submitText    = document.getElementById('login-submit-text');
        const maxAttempts   = 5;
        const lockMs        = 30000;
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
            attempts  = Number(localStorage.getItem('loginAttempts')  || '0') || 0;
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
                    body: JSON.stringify({ login: r.normalized, senha: passwordInput ? passwordInput.value : '' }),
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
                                    const d = await (await fetch('/api/auth/reenviar-verificacao', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ email: userEmail }),
                                    })).json();
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

    updateLoginLabels(false); // estado inicial — checkSession() corrige se houver sessão ativa
    checkSession();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPage);
} else {
    initPage();
}
