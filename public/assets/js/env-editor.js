'use strict';

// ── Env Editor — cards de configuração do .env ───────────────────────────────
// Carregado após dashboard.js. Usa esc() e setBtn() definidos lá.

(function () {

    // Grupos de variáveis — define os cards e a ordem dos campos
    const ENV_GROUPS = [
        {
            id: 'app',
            title: 'Aplicação',
            icon: 'fa-rocket',
            color: '#818cf8',
            keys: [
                { key: 'APP_NAME',        label: 'Nome',          type: 'text' },
                { key: 'APP_DESCRICAO',   label: 'Descrição',     type: 'text' },
                { key: 'APP_VERSION',     label: 'Versão',        type: 'text' },
                { key: 'APP_ENV',         label: 'Ambiente',      type: 'select', options: ['production','development','testing','local'] },
                { key: 'APP_DEBUG',       label: 'Debug',         type: 'select', options: ['false','true'] },
                { key: 'APP_URL',         label: 'URL da API',    type: 'url' },
                { key: 'APP_URL_FRONTEND',label: 'URL Frontend',  type: 'url' },
                { key: 'APP_DOMAIN',      label: 'Domínio',       type: 'text' },
                { key: 'APP_HOST',        label: 'Host',          type: 'text' },
                { key: 'APP_PORT',        label: 'Porta',         type: 'number' },
                { key: 'APP_TIMEZONE',    label: 'Fuso horário',  type: 'text' },
                { key: 'APP_LOGO_URL',    label: 'URL do logo',   type: 'text' },
                { key: 'CORS_ALLOWED_ORIGINS', label: 'CORS Origins', type: 'text' },
                { key: 'TRUST_PROXY',     label: 'Trust Proxy',   type: 'select', options: ['false','true'] },
                { key: 'CADDY_EMAIL',     label: 'E-mail Caddy',  type: 'email' },
            ],
        },
        {
            id: 'db',
            title: 'Banco de dados (core)',
            icon: 'fa-database',
            color: '#4ade80',
            keys: [
                { key: 'DB_CONEXAO',   label: 'Driver',    type: 'select', options: ['postgresql','mysql','sqlite'] },
                { key: 'DB_HOST',      label: 'Host',      type: 'text' },
                { key: 'DB_PORT',      label: 'Porta',     type: 'number' },
                { key: 'DB_NOME',      label: 'Banco',     type: 'text' },
                { key: 'DB_USUARIO',   label: 'Usuário',   type: 'text' },
                { key: 'DB_SENHA',     label: 'Senha',     type: 'password', sensitive: true },
                { key: 'DB_CHARSET',   label: 'Charset',   type: 'text' },
                { key: 'DB_PREFIX',    label: 'Prefixo',   type: 'text' },
            ],
        },
        {
            id: 'db2',
            title: 'Banco de dados (modules)',
            icon: 'fa-database',
            color: '#60a5fa',
            keys: [
                { key: 'DB2_CONEXAO',  label: 'Driver',    type: 'select', options: ['','postgresql','mysql','sqlite'] },
                { key: 'DB2_HOST',     label: 'Host',      type: 'text' },
                { key: 'DB2_PORT',     label: 'Porta',     type: 'number' },
                { key: 'DB2_NOME',     label: 'Banco',     type: 'text' },
                { key: 'DB2_USUARIO',  label: 'Usuário',   type: 'text' },
                { key: 'DB2_SENHA',    label: 'Senha',     type: 'password', sensitive: true },
                { key: 'DB2_CHARSET',  label: 'Charset',   type: 'text' },
                { key: 'DB2_PREFIX',   label: 'Prefixo',   type: 'text' },
            ],
        },
        {
            id: 'jwt',
            title: 'Segurança / JWT',
            icon: 'fa-shield-halved',
            color: '#f59e0b',
            keys: [
                { key: 'JWT_SECRET',      label: 'JWT Secret',      type: 'password', sensitive: true },
                { key: 'JWT_API_SECRET',  label: 'JWT API Secret',  type: 'password', sensitive: true },
                { key: 'JWT_ISSUER',      label: 'Issuer (iss)',     type: 'text' },
                { key: 'JWT_AUDIENCE',    label: 'Audience (aud)',   type: 'text' },
                { key: 'JWT_EXPIRATION_TIME',          label: 'Expiração access (s)',  type: 'number' },
                { key: 'REFRESH_TOKEN_EXPIRATION_SECONDS', label: 'Expiração refresh (s)', type: 'number' },
                { key: 'REFRESH_MAX_PER_USER',         label: 'Max refresh/usuário',   type: 'number' },
                { key: 'REVOCATION_STORAGE',           label: 'Storage revogação',     type: 'select', options: ['database','redis'] },
                { key: 'COOKIE_SAMESITE',  label: 'Cookie SameSite', type: 'select', options: ['Lax','Strict','None'] },
                { key: 'COOKIE_SECURE',    label: 'Cookie Secure',   type: 'select', options: ['true','false'] },
                { key: 'COOKIE_HTTPONLY',  label: 'Cookie HttpOnly', type: 'select', options: ['true','false'] },
                { key: 'COOKIE_DOMAIN',    label: 'Cookie Domain',   type: 'text' },
            ],
        },
        {
            id: 'mail',
            title: 'E-mail (SMTP)',
            icon: 'fa-envelope',
            color: '#f472b6',
            keys: [
                { key: 'MAILER_HOST',       label: 'Host SMTP',     type: 'text' },
                { key: 'MAILER_PORT',       label: 'Porta',         type: 'number' },
                { key: 'MAILER_USERNAME',   label: 'Usuário',       type: 'email' },
                { key: 'MAILER_PASSWORD',   label: 'Senha',         type: 'password', sensitive: true },
                { key: 'MAILER_ENCRYPTION', label: 'Criptografia',  type: 'select', options: ['tls','ssl',''] },
                { key: 'MAILER_FROM_EMAIL', label: 'E-mail remetente', type: 'email' },
                { key: 'MAILER_FROM_NAME',  label: 'Nome remetente',   type: 'text' },
                { key: 'MAILER_REPLY_TO',   label: 'Reply-To',         type: 'email' },
                { key: 'MAILER_DEBUG',      label: 'Debug SMTP',       type: 'select', options: ['false','true'] },
            ],
        },
        {
            id: 'redis',
            title: 'Redis',
            icon: 'fa-bolt',
            color: '#fb923c',
            keys: [
                { key: 'REDIS_HOST',     label: 'Host',     type: 'text' },
                { key: 'REDIS_PORT',     label: 'Porta',    type: 'number' },
                { key: 'REDIS_PASSWORD', label: 'Senha',    type: 'password', sensitive: true },
                { key: 'REDIS_DB',       label: 'DB index', type: 'number' },
                { key: 'REDIS_PREFIX',   label: 'Prefixo',  type: 'text' },
            ],
        },
        {
            id: 'admin',
            title: 'Admin padrão',
            icon: 'fa-user-shield',
            color: '#a78bfa',
            keys: [
                { key: 'ADMIN_EMAIL',    label: 'E-mail',   type: 'email' },
                { key: 'ADMIN_NAME',     label: 'Nome',     type: 'text' },
                { key: 'ADMIN_USERNAME', label: 'Username', type: 'text' },
                { key: 'ADMIN_PASSWORD', label: 'Senha',    type: 'password', sensitive: true },
            ],
        },
        {
            id: 'docker',
            title: 'Docker / Infra',
            icon: 'fa-box',
            color: '#94a3b8',
            keys: [
                { key: 'POSTGRES_PORT',       label: 'Porta PostgreSQL', type: 'number' },
                { key: 'MYSQL_PORT',          label: 'Porta MySQL',      type: 'number' },
                { key: 'MYSQL_ROOT_PASSWORD', label: 'Senha root MySQL', type: 'password', sensitive: true },
                { key: 'ADMINER_PORT',        label: 'Porta Adminer',    type: 'number' },
                { key: 'MIGRATE_LOCK_TIMEOUT',label: 'Timeout migration (s)', type: 'number' },
                { key: 'API_KEY',             label: 'API Key',          type: 'password', sensitive: true },
                { key: 'CACHE_DRIVER',        label: 'Cache driver',     type: 'select', options: ['file','redis'] },
                { key: 'CACHE_PATH',          label: 'Cache path',       type: 'text' },
            ],
        },
    ];

    let envVars = {};       // valores carregados da API
    let dirtyGroups = {};   // grupos com alterações não salvas

    // ── Carrega variáveis da API ──────────────────────────────────────────────
    async function loadEnv() {
        const grid = document.getElementById('env-cards-grid');
        if (!grid) return;

        try {
            const res = await fetch('/api/env', { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            envVars = data.vars || {};
            renderCards(grid);
        } catch (e) {
            grid.innerHTML = '<p style="color:#f87171;padding:16px;">Erro ao carregar variáveis: ' + esc(e.message) + '</p>';
        }
    }

    // ── Renderiza todos os cards ──────────────────────────────────────────────
    function renderCards(grid) {
        grid.textContent = '';
        ENV_GROUPS.forEach(group => {
            grid.appendChild(buildCard(group));
        });
    }

    // ── Constrói um card de grupo ─────────────────────────────────────────────
    function buildCard(group) {
        const card = document.createElement('div');
        card.className = 'dash-card env-card';
        card.dataset.group = group.id;
        card.style.cssText = 'padding:20px;display:flex;flex-direction:column;gap:14px;';

        // Header
        const header = document.createElement('div');
        header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:10px;';

        const titleWrap = document.createElement('div');
        titleWrap.style.cssText = 'display:flex;align-items:center;gap:10px;';

        const iconBox = document.createElement('div');
        iconBox.style.cssText = `width:36px;height:36px;border-radius:10px;background:${group.color}22;display:flex;align-items:center;justify-content:center;flex-shrink:0;`;
        const icon = document.createElement('i');
        icon.className = 'fa-solid ' + group.icon;
        icon.style.color = group.color;
        iconBox.appendChild(icon);

        const titleEl = document.createElement('span');
        titleEl.style.cssText = 'font-weight:700;font-size:0.97rem;';
        titleEl.textContent = group.title;

        titleWrap.appendChild(iconBox);
        titleWrap.appendChild(titleEl);

        const saveBtn = document.createElement('button');
        saveBtn.className = 'dash-btn-primary env-save-btn';
        saveBtn.dataset.group = group.id;
        saveBtn.style.cssText = 'font-size:0.8rem;padding:6px 14px;opacity:0.4;pointer-events:none;transition:opacity .2s;';
        setBtn(saveBtn, 'fa-solid fa-floppy-disk', 'Salvar');

        header.appendChild(titleWrap);
        header.appendChild(saveBtn);
        card.appendChild(header);

        // Campos
        const fields = document.createElement('div');
        fields.style.cssText = 'display:flex;flex-direction:column;gap:10px;';

        group.keys.forEach(field => {
            fields.appendChild(buildField(field, group.id, saveBtn));
        });

        card.appendChild(fields);

        // Feedback
        const fb = document.createElement('div');
        fb.id = 'env-fb-' + group.id;
        fb.style.cssText = 'font-size:0.82rem;min-height:18px;';
        card.appendChild(fb);

        // Save handler
        saveBtn.addEventListener('click', () => saveGroup(group, saveBtn, fb));

        return card;
    }

    // ── Constrói um campo individual ──────────────────────────────────────────
    function buildField(field, groupId, saveBtn) {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;flex-direction:column;gap:4px;';

        const label = document.createElement('label');
        label.style.cssText = 'font-size:0.78rem;font-weight:600;color:var(--text-secondary,#94a3b8);letter-spacing:.02em;';
        label.textContent = field.label;
        label.htmlFor = 'env-' + field.key;

        let input;
        const currentVal = envVars[field.key] ?? '';

        if (field.type === 'select') {
            input = document.createElement('select');
            input.style.cssText = inputStyle();
            (field.options || []).forEach(opt => {
                const o = document.createElement('option');
                o.value = opt;
                o.textContent = opt === '' ? '(não definido)' : opt;
                if (opt === currentVal) o.selected = true;
                input.appendChild(o);
            });
        } else {
            input = document.createElement('input');
            input.type = field.type === 'password' ? 'password' : (field.type || 'text');
            input.style.cssText = inputStyle();
            input.value = currentVal;
            input.placeholder = field.sensitive && currentVal === '••••••••' ? '(protegido — deixe em branco para manter)' : '';
            if (field.type === 'password') {
                input.autocomplete = 'off';
            }
        }

        input.id = 'env-' + field.key;
        input.dataset.key = field.key;
        input.dataset.group = groupId;

        // Marca grupo como dirty ao editar
        input.addEventListener('input', () => {
            dirtyGroups[groupId] = true;
            saveBtn.style.opacity = '1';
            saveBtn.style.pointerEvents = 'auto';
        });
        input.addEventListener('change', () => {
            dirtyGroups[groupId] = true;
            saveBtn.style.opacity = '1';
            saveBtn.style.pointerEvents = 'auto';
        });

        // Ícone de cadeado para campos sensíveis
        if (field.sensitive) {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:6px;';
            row.appendChild(input);
            const lock = document.createElement('i');
            lock.className = 'fa-solid fa-lock';
            lock.title = 'Campo sensível — valor não exibido';
            lock.style.cssText = 'color:#f59e0b;font-size:0.8rem;flex-shrink:0;';
            row.appendChild(lock);
            wrap.appendChild(label);
            wrap.appendChild(row);
        } else {
            wrap.appendChild(label);
            wrap.appendChild(input);
        }

        return wrap;
    }

    // ── Salva um grupo ────────────────────────────────────────────────────────
    async function saveGroup(group, saveBtn, fb) {
        const vars = {};
        group.keys.forEach(field => {
            const el = document.getElementById('env-' + field.key);
            if (el) vars[field.key] = el.value;
        });

        setBtn(saveBtn, 'fa-solid fa-spinner fa-spin', 'Salvando...');
        saveBtn.disabled = true;
        fb.textContent = '';
        fb.style.color = '';

        try {
            const res = await fetch('/api/env', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ vars }),
            });
            const data = await res.json();

            if (res.ok && data.ok) {
                fb.textContent = '✓ Salvo com sucesso';
                fb.style.color = '#4ade80';
                dirtyGroups[group.id] = false;
                saveBtn.style.opacity = '0.4';
                saveBtn.style.pointerEvents = 'none';
                // Atualiza cache local
                Object.assign(envVars, vars);
                setTimeout(() => { fb.textContent = ''; }, 3000);
            } else {
                fb.textContent = data.error || 'Erro ao salvar.';
                fb.style.color = '#f87171';
            }
        } catch (e) {
            fb.textContent = 'Erro de conexão.';
            fb.style.color = '#f87171';
        } finally {
            setBtn(saveBtn, 'fa-solid fa-floppy-disk', 'Salvar');
            saveBtn.disabled = false;
        }
    }

    function inputStyle() {
        return 'width:100%;padding:8px 12px;border:1.5px solid var(--border-input,rgba(0,0,0,0.12));border-radius:8px;font-size:0.88rem;background:var(--bg-input,#f8fafc);color:var(--text-primary,#1e293b);font-family:inherit;box-sizing:border-box;outline:none;transition:border-color .15s;';
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    function init() {
        const grid = document.getElementById('env-cards-grid');
        if (!grid) return;

        loadEnv();

        const refreshBtn = document.getElementById('env-refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                dirtyGroups = {};
                loadEnv();
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
