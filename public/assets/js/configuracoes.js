'use strict';
// ── Página de Configurações — env-editor completo ────────────────────────────

// Redireciona para login quando token expira ou é revogado
(function () {
    let _redirecting = false;
    window._cfgRedirectToLogin = function () {
        if (_redirecting) return;
        _redirecting = true;
        window.location.replace('/');
    };
})();

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#x27;');
}

// ── Definição dos grupos ──────────────────────────────────────────────────────
const CFG_GROUPS = [
  { id:'app', title:'Aplicação', icon:'fa-rocket', color:'#818cf8',
    fields:[
      {key:'APP_NAME',         label:'Nome da aplicação',   type:'text',   icon:'fa-tag'},
      {key:'APP_DESCRICAO',    label:'Descrição',           type:'text',   icon:'fa-align-left'},
      {key:'APP_VERSION',      label:'Versão',              type:'text',   icon:'fa-code-branch'},
      {key:'APP_ENV',          label:'Ambiente',            type:'select', icon:'fa-server',  options:['production','development','testing','local']},
      {key:'APP_DEBUG',        label:'Modo debug',          type:'select', icon:'fa-bug',     options:['false','true']},
      {key:'APP_URL',          label:'URL da API',          type:'url',    icon:'fa-link'},
      {key:'APP_URL_FRONTEND', label:'URL do frontend',     type:'url',    icon:'fa-globe'},
      {key:'APP_DOMAIN',       label:'Domínio',             type:'text',   icon:'fa-at'},
      {key:'APP_HOST',         label:'Host',                type:'text',   icon:'fa-network-wired'},
      {key:'APP_PORT',         label:'Porta',               type:'number', icon:'fa-plug'},
      {key:'APP_TIMEZONE',     label:'Fuso horário',        type:'text',   icon:'fa-clock'},
      {key:'APP_LOGO_URL',     label:'URL do logo',         type:'text',   icon:'fa-image'},
      {key:'TRUST_PROXY',      label:'Trust Proxy',         type:'select', icon:'fa-shield',  options:['false','true']},
      {key:'CADDY_EMAIL',      label:'E-mail Caddy (HTTPS)',type:'email',  icon:'fa-envelope'},
    ]},
  { id:'db', title:'Banco de dados (core)', icon:'fa-database', color:'#4ade80',
    readonly: true,
    readonlyReason: 'Por segurança, as configurações do banco principal não podem ser alteradas pelo dashboard. Edite o arquivo .env diretamente no servidor.',
    fields:[
      {key:'DB_CONEXAO',   label:'Driver',   type:'select', icon:'fa-database', options:['postgresql','mysql','sqlite']},
      {key:'DB_HOST',      label:'Host',     type:'text',   icon:'fa-server'},
      {key:'DB_PORT',      label:'Porta',    type:'number', icon:'fa-plug'},
      {key:'DB_NOME',      label:'Banco',    type:'text',   icon:'fa-table'},
      {key:'DB_USUARIO',   label:'Usuário',  type:'text',   icon:'fa-user'},
      {key:'DB_SENHA',     label:'Senha',    type:'password',icon:'fa-lock', sensitive:true},
      {key:'DB_CHARSET',   label:'Charset',  type:'text',   icon:'fa-font'},
      {key:'DB_PREFIX',    label:'Prefixo',  type:'text',   icon:'fa-tag'},
    ]},
  { id:'db2', title:'Banco de dados (modules)', icon:'fa-database', color:'#60a5fa',
    fields:[
      {
        key:'DEFAULT_MODULE_CONNECTION',
        label:'Conexão padrão para novos módulos',
        type:'select',
        icon:'fa-plug-circle-bolt',
        options:['core','modules'],
        hint:'Define qual banco de dados os novos módulos usarão por padrão. Módulos existentes mantêm sua conexão atual (connection.php).'
      },
      {key:'DB2_CONEXAO',  label:'Driver',   type:'select', icon:'fa-database', options:['','postgresql','mysql','sqlite']},
      {key:'DB2_HOST',     label:'Host',     type:'text',   icon:'fa-server'},
      {key:'DB2_PORT',     label:'Porta',    type:'number', icon:'fa-plug'},
      {key:'DB2_NOME',     label:'Banco',    type:'text',   icon:'fa-table'},
      {key:'DB2_USUARIO',  label:'Usuário',  type:'text',   icon:'fa-user'},
      {key:'DB2_SENHA',    label:'Senha',    type:'password',icon:'fa-lock', sensitive:true},
      {key:'DB2_CHARSET',  label:'Charset',  type:'text',   icon:'fa-font'},
      {key:'DB2_PREFIX',   label:'Prefixo',  type:'text',   icon:'fa-tag'},
    ]},
  { id:'jwt', title:'Segurança / JWT', icon:'fa-shield-halved', color:'#f59e0b',
    fields:[
      {key:'JWT_SECRET',     label:'JWT Secret',           type:'password',icon:'fa-key',    sensitive:true},
      {key:'JWT_API_SECRET', label:'JWT API Secret',       type:'password',icon:'fa-key',    sensitive:true},
      {key:'JWT_ISSUER',     label:'Issuer (iss)',          type:'text',   icon:'fa-id-card'},
      {key:'JWT_AUDIENCE',   label:'Audience (aud)',        type:'text',   icon:'fa-users'},
      {key:'JWT_EXPIRATION_TIME',             label:'Expiração access (s)',  type:'number', icon:'fa-hourglass'},
      {key:'REFRESH_TOKEN_EXPIRATION_SECONDS',label:'Expiração refresh (s)', type:'number', icon:'fa-rotate'},
      {key:'REFRESH_MAX_PER_USER',            label:'Max refresh/usuário',   type:'number', icon:'fa-user-clock'},
      {key:'REVOCATION_STORAGE',             label:'Storage revogação',     type:'select', icon:'fa-database', options:['database','redis']},
      {key:'COOKIE_SAMESITE',  label:'Cookie SameSite', type:'select', icon:'fa-cookie', options:['Lax','Strict','None']},
      {key:'COOKIE_SECURE',    label:'Cookie Secure',   type:'select', icon:'fa-lock',   options:['true','false']},
      {key:'COOKIE_HTTPONLY',  label:'Cookie HttpOnly', type:'select', icon:'fa-lock',   options:['true','false']},
      {key:'COOKIE_DOMAIN',    label:'Cookie Domain',   type:'text',   icon:'fa-globe'},
    ]},
  { id:'mail', title:'E-mail (SMTP)', icon:'fa-envelope', color:'#f472b6',
    fields:[
      {key:'MAILER_HOST',       label:'Host SMTP',        type:'text',    icon:'fa-server'},
      {key:'MAILER_PORT',       label:'Porta',            type:'number',  icon:'fa-plug'},
      {key:'MAILER_USERNAME',   label:'Usuário',          type:'email',   icon:'fa-user'},
      {key:'MAILER_PASSWORD',   label:'Senha',            type:'password',icon:'fa-lock', sensitive:true},
      {key:'MAILER_ENCRYPTION', label:'Criptografia',     type:'select',  icon:'fa-shield', options:['tls','ssl','']},
      {key:'MAILER_FROM_EMAIL', label:'E-mail remetente', type:'email',   icon:'fa-at'},
      {key:'MAILER_FROM_NAME',  label:'Nome remetente',   type:'text',    icon:'fa-signature'},
      {key:'MAILER_REPLY_TO',   label:'Reply-To',         type:'email',   icon:'fa-reply'},
      {key:'MAILER_DEBUG',      label:'Debug SMTP',       type:'select',  icon:'fa-bug', options:['false','true']},
    ]},
  { id:'cors', title:'URLs permitidas (CORS)', icon:'fa-globe', color:'#34d399',
    cors: true },
  { id:'ide-limits', title:'Limites da IDE', icon:'fa-code', color:'#818cf8',
    fields:[
      {key:'IDE_MAX_PROJECTS_PER_USER', label:'Máx. projetos por desenvolvedor', type:'select', icon:'fa-layer-group',
       options:['-1','0','1','2','3','4','5','6','7','8','9','10','15','20','30','50','100'],
       labels:{'-1':'Ilimitado','0':'Bloqueado (não pode criar)','1':'1','2':'2','3':'3','4':'4','5':'5','6':'6','7':'7','8':'8','9':'9','10':'10','15':'15','20':'20','30':'30','50':'50','100':'100'}},
    ]},
  { id:'link-limits', title:'Limites de Links Encurtados', icon:'fa-link', color:'#22d3ee',
    custom: 'link-limits' },
  { id:'redis', title:'Redis', icon:'fa-bolt', color:'#fb923c',
    fields:[
      {key:'REDIS_HOST',     label:'Host',     type:'text',    icon:'fa-server'},
      {key:'REDIS_PORT',     label:'Porta',    type:'number',  icon:'fa-plug'},
      {key:'REDIS_PASSWORD', label:'Senha',    type:'password',icon:'fa-lock', sensitive:true},
      {key:'REDIS_DB',       label:'DB index', type:'number',  icon:'fa-database'},
      {key:'REDIS_PREFIX',   label:'Prefixo',  type:'text',    icon:'fa-tag'},
    ]},
  { id:'admin', title:'Admin padrão', icon:'fa-user-shield', color:'#a78bfa',
    fields:[
      {key:'ADMIN_EMAIL',    label:'E-mail',   type:'email',   icon:'fa-envelope'},
      {key:'ADMIN_NAME',     label:'Nome',     type:'text',    icon:'fa-id-card'},
      {key:'ADMIN_USERNAME', label:'Username', type:'text',    icon:'fa-at'},
      {key:'ADMIN_PASSWORD', label:'Senha',    type:'password',icon:'fa-lock', sensitive:true},
    ]},
  { id:'docker', title:'Docker / Infra', icon:'fa-box', color:'#94a3b8',
    fields:[
      {key:'POSTGRES_PORT',        label:'Porta PostgreSQL',    type:'number', icon:'fa-plug'},
      {key:'MYSQL_PORT',           label:'Porta MySQL',         type:'number', icon:'fa-plug'},
      {key:'MYSQL_ROOT_PASSWORD',  label:'Senha root MySQL',    type:'password',icon:'fa-lock', sensitive:true},
      {key:'ADMINER_PORT',         label:'Porta Adminer',       type:'number', icon:'fa-plug'},
      {key:'MIGRATE_LOCK_TIMEOUT', label:'Timeout migration (s)',type:'number',icon:'fa-hourglass'},
      {key:'API_KEY',              label:'API Key',             type:'password',icon:'fa-key', sensitive:true},
      {key:'CACHE_DRIVER',         label:'Cache driver',        type:'select', icon:'fa-database', options:['file','redis']},
      {key:'CACHE_PATH',           label:'Cache path',          type:'text',   icon:'fa-folder'},
    ]},
];

// ── Estado global ─────────────────────────────────────────────────────────────
let envVars = {};
let corsUrls = [];

// ── Carrega variáveis ─────────────────────────────────────────────────────────
async function loadEnv() {
    try {
        const res = await fetch('/api/env', { credentials: 'same-origin' });
        if (res.status === 401) { window._cfgRedirectToLogin?.(); return; }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        envVars = data.vars || {};
        corsUrls = (envVars['CORS_ALLOWED_ORIGINS'] || '').split(',').map(s => s.trim()).filter(Boolean);
        renderAll();
    } catch(e) {
        document.getElementById('cfg-main').innerHTML =
            '<p style="color:var(--cfg-danger);padding:40px;font-size:1.1rem;"><i class="fa-solid fa-triangle-exclamation"></i> Erro ao carregar: ' + esc(e.message) + '</p>';
    }
}

// ── Renderiza todas as seções ─────────────────────────────────────────────────
function renderAll() {
    const main = document.getElementById('cfg-main');
    main.innerHTML = '';

    const grid = document.createElement('div');
    grid.className = 'cfg-sections-grid';

    CFG_GROUPS.forEach(g => {
        const sec = buildSection(g);
        // Todas as seções ocupam largura total
        sec.classList.add('cfg-full-width');
        grid.appendChild(sec);
    });

    main.appendChild(grid);
    initSidenavHighlight();
}

// ── Constrói uma seção ────────────────────────────────────────────────────────
function buildSection(group) {
    const sec = document.createElement('section');
    sec.className = 'cfg-section' + (group.readonly ? ' cfg-locked' : '');
    sec.id = 'sec-' + group.id;

    // Header
    const hdr = document.createElement('div');
    hdr.className = 'cfg-section-header';

    const titleWrap = document.createElement('div');
    titleWrap.className = 'cfg-section-title-wrap';

    const iconBox = document.createElement('div');
    iconBox.className = 'cfg-section-icon';
    iconBox.style.background = group.color + '22';
    const ico = document.createElement('i');
    ico.className = 'fa-solid ' + group.icon;
    ico.style.color = group.color;
    iconBox.appendChild(ico);

    const textWrap = document.createElement('div');
    const h2 = document.createElement('h2');
    h2.className = 'cfg-section-title';
    h2.textContent = group.title;
    textWrap.appendChild(h2);

    // Badge somente leitura
    if (group.readonly) {
        const badge = document.createElement('span');
        badge.className = 'cfg-badge readonly';
        badge.innerHTML = '<i class="fa-solid fa-lock"></i> Somente leitura';
        badge.style.marginTop = '4px';
        textWrap.appendChild(badge);
    }

    titleWrap.appendChild(iconBox);
    titleWrap.appendChild(textWrap);
    hdr.appendChild(titleWrap);

    // Botão editar — só aparece se não for readonly e não for seção customizada
    if (!group.readonly) {
        sec.appendChild(hdr);

        // Body
        const body = document.createElement('div');
        body.className = 'cfg-section-body';
        body.id = 'cfg-body-' + group.id;

        if (group.custom === 'link-limits') {
            // Seção customizada: sem botão Editar, renderiza diretamente
            renderLinkLimitsBody(body);
            sec.appendChild(body);
        } else {
            // Seção normal: botão Editar + toggleEdit
            const editBtn = document.createElement('button');
            editBtn.className = 'cfg-edit-btn';
            editBtn.dataset.group = group.id;
            editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar';
            editBtn.setAttribute('aria-label', 'Editar ' + group.title);
            hdr.appendChild(editBtn);

            if (group.cors) renderCorsBody(body, false);
            else renderFieldsBody(body, group, false);

            sec.appendChild(body);

            const fb = document.createElement('div');
            fb.className = 'cfg-feedback';
            fb.id = 'cfg-fb-' + group.id;
            fb.style.cssText = 'padding:0 28px 16px;';
            sec.appendChild(fb);

            editBtn.addEventListener('click', () => toggleEdit(group, editBtn, body, fb));
        }
    } else {
        // Seção bloqueada — só leitura, sem botão editar
        sec.appendChild(hdr);

        const body = document.createElement('div');
        body.className = 'cfg-section-body';
        body.id = 'cfg-body-' + group.id;

        // Aviso de bloqueio
        const notice = document.createElement('div');
        notice.className = 'cfg-locked-notice';
        const noticeIcon = document.createElement('i');
        noticeIcon.className = 'fa-solid fa-shield-halved';
        noticeIcon.style.cssText = 'font-size:1.1rem;flex-shrink:0;';
        const noticeText = document.createElement('span');
        noticeText.textContent = group.readonlyReason || 'Esta seção não pode ser editada pelo dashboard.';
        notice.appendChild(noticeIcon);
        notice.appendChild(noticeText);
        body.appendChild(notice);

        renderFieldsBody(body, group, false);
        sec.appendChild(body);
    }

    return sec;
}

// ── Toggle edição ─────────────────────────────────────────────────────────────
function toggleEdit(group, editBtn, body, fb) {
    const isEditing = editBtn.dataset.editing === '1';

    if (isEditing) {
        // Cancela
        editBtn.dataset.editing = '0';
        editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar';
        editBtn.className = 'cfg-edit-btn';
        fb.textContent = '';
        fb.className = 'cfg-feedback';
        if (group.cors) renderCorsBody(body, false);
        else renderFieldsBody(body, group, false);
    } else {
        // Entra em modo edição
        editBtn.dataset.editing = '1';
        editBtn.innerHTML = '<i class="fa-solid fa-xmark"></i> Cancelar';
        editBtn.className = 'cfg-cancel-btn';
        if (group.cors) renderCorsBody(body, true);
        else renderFieldsBody(body, group, true);

        // Adiciona botão salvar no body
        const saveRow = document.createElement('div');
        saveRow.style.cssText = 'display:flex;align-items:center;gap:12px;flex-wrap:wrap;';

        const saveBtn = document.createElement('button');
        saveBtn.className = 'cfg-save-btn';
        saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Salvar alterações';
        saveBtn.addEventListener('click', () => saveGroup(group, saveBtn, fb, editBtn, body));

        saveRow.appendChild(saveBtn);
        body.appendChild(saveRow);
    }
}

// ── Renderiza campos em modo leitura ou edição ────────────────────────────────
function renderFieldsBody(body, group, editing) {
    body.innerHTML = '';
    const grid = document.createElement('div');
    grid.className = 'cfg-fields-grid';

    (group.fields || []).forEach(field => {
        const wrap = document.createElement('div');
        wrap.className = 'cfg-field';

        const lbl = document.createElement('label');
        lbl.className = 'cfg-field-label';
        lbl.htmlFor = 'cfg-inp-' + field.key;
        const lblIcon = document.createElement('i');
        lblIcon.className = 'fa-solid ' + field.icon;
        lbl.appendChild(lblIcon);
        lbl.appendChild(document.createTextNode(' ' + field.label));

        wrap.appendChild(lbl);

        const val = envVars[field.key] ?? '';

        if (!editing) {
            // Modo leitura
            const disp = document.createElement('div');
            disp.className = 'cfg-value-display' + (field.sensitive ? ' sensitive' : '');
            if (field.sensitive) {
                const lockIco = document.createElement('i');
                lockIco.className = 'fa-solid fa-lock';
                disp.appendChild(lockIco);
                if (val !== '') {
                    disp.appendChild(document.createTextNode(' ••••••••'));
                } else {
                    const nd = document.createElement('span');
                    nd.style.opacity = '.4';
                    nd.textContent = 'não definido';
                    disp.appendChild(nd);
                }
            } else {
                disp.textContent = val !== '' ? val : '—';
            }
            wrap.appendChild(disp);
        } else {
            // Modo edição
            let inp;
            if (field.type === 'select') {
                inp = document.createElement('select');
                inp.className = 'cfg-select';
                (field.options || []).forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt;
                    o.textContent = (field.labels && field.labels[opt]) ? field.labels[opt] : (opt === '' ? '(não definido)' : opt);
                    if (opt === val) o.selected = true;
                    inp.appendChild(o);
                });
            } else {
                inp = document.createElement('input');
                inp.type = field.type === 'password' ? 'password' : (field.type || 'text');
                inp.className = 'cfg-input';
                inp.value = field.sensitive ? '' : val;
                inp.placeholder = field.sensitive ? 'Deixe em branco para manter o valor atual' : '';
                if (field.type === 'password') inp.autocomplete = 'off';
            }
            inp.id = 'cfg-inp-' + field.key;
            inp.dataset.key = field.key;
            wrap.appendChild(inp);
        }

        // Hint — texto de ajuda abaixo do campo
        if (field.hint) {
            const hint = document.createElement('p');
            hint.className = 'cfg-field-hint';
            hint.textContent = field.hint;
            wrap.appendChild(hint);
        }

        grid.appendChild(wrap);
    });

    body.appendChild(grid);
}
function renderCorsBody(body, editing) {
    body.innerHTML = '';

    const desc = document.createElement('p');
    desc.className = 'cfg-field-desc';
    desc.style.cssText = 'font-size:1rem;margin:0 0 16px;';
    desc.textContent = 'Origens permitidas para requisições cross-origin. Cada linha é uma URL completa (ex: https://meusite.com).';
    body.appendChild(desc);

    const list = document.createElement('div');
    list.className = 'cfg-url-list';
    list.id = 'cors-url-list';

    const urls = editing ? [...corsUrls] : corsUrls;

    if (urls.length === 0 && !editing) {
        const empty = document.createElement('div');
        empty.className = 'cfg-value-display';
        empty.textContent = 'Nenhuma URL configurada';
        empty.style.opacity = '.5';
        list.appendChild(empty);
    } else {
        urls.forEach((url, i) => {
            list.appendChild(buildUrlRow(url, i, editing));
        });
    }

    body.appendChild(list);

    if (editing) {
        const addBtn = document.createElement('button');
        addBtn.className = 'cfg-add-url-btn';
        addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Adicionar URL';
        addBtn.addEventListener('click', () => {
            const listEl = document.getElementById('cors-url-list');
            const idx = listEl.querySelectorAll('.cfg-url-row').length;
            listEl.appendChild(buildUrlRow('', idx, true));
        });
        body.appendChild(addBtn);
    }
}

function buildUrlRow(url, idx, editing) {
    const row = document.createElement('div');
    row.className = 'cfg-url-row';

    if (!editing) {
        const disp = document.createElement('div');
        disp.className = 'cfg-value-display';
        disp.style.flex = '1';
        const linkIco = document.createElement('i');
        linkIco.className = 'fa-solid fa-link';
        linkIco.style.cssText = 'color:var(--cfg-accent);flex-shrink:0;';
        disp.appendChild(linkIco);
        disp.appendChild(document.createTextNode(' ' + url));
        row.appendChild(disp);
    } else {
        const inp = document.createElement('input');
        inp.type = 'url';
        inp.className = 'cfg-input cors-url-input';
        inp.value = url;
        inp.placeholder = 'https://exemplo.com';
        inp.setAttribute('aria-label', 'URL ' + (idx + 1));

        const removeBtn = document.createElement('button');
        removeBtn.className = 'cfg-url-remove';
        removeBtn.title = 'Remover URL';
        removeBtn.setAttribute('aria-label', 'Remover URL');
        removeBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
        removeBtn.addEventListener('click', () => row.remove());

        row.appendChild(inp);
        row.appendChild(removeBtn);
    }

    return row;
}

// ── Salva um grupo ────────────────────────────────────────────────────────────
async function saveGroup(group, saveBtn, fb, editBtn, body) {
    // Intercepta: se DEFAULT_MODULE_CONNECTION vai para 'modules', testa DB2 primeiro
    if (!group.cors) {
        const connEl = document.getElementById('cfg-inp-DEFAULT_MODULE_CONNECTION');
        if (connEl && connEl.value === 'modules') {
            const testResult = await testDb2Connection(body);
            if (!testResult.ok) {
                showDb2ErrorModal(testResult.message);
                connEl.value = 'core'; // reverte o select
                return;
            }
        }
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando...';
    fb.textContent = '';
    fb.className = 'cfg-feedback';

    let vars = {};

    if (group.cors) {
        // Coleta URLs do CORS
        const inputs = body.querySelectorAll('.cors-url-input');
        const urls = Array.from(inputs).map(i => i.value.trim()).filter(Boolean);
        corsUrls = urls;
        vars['CORS_ALLOWED_ORIGINS'] = urls.join(',');
    } else {
        (group.fields || []).forEach(field => {
            const el = document.getElementById('cfg-inp-' + field.key);
            if (!el) return;
            const val = el.value;
            // Campos sensíveis: só envia se o usuário digitou algo
            if (field.sensitive && val === '') return;
            vars[field.key] = val;
        });
    }

    try {
        const res = await fetch('/api/env', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ vars }),
        });
        const data = await res.json();

        if (res.ok && data.ok) {
            // Atualiza cache local
            Object.assign(envVars, vars);
            fb.innerHTML = '<i class="fa-solid fa-circle-check"></i> Salvo com sucesso';
            fb.className = 'cfg-feedback ok';
            // Sai do modo edição
            editBtn.dataset.editing = '0';
            editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar';
            editBtn.className = 'cfg-edit-btn';
            if (group.cors) renderCorsBody(body, false);
            else renderFieldsBody(body, group, false);
            setTimeout(() => { fb.textContent = ''; fb.className = 'cfg-feedback'; }, 4000);
        } else {
            fb.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(data.error || 'Erro ao salvar.');
            fb.className = 'cfg-feedback error';
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Salvar alterações';
        }
    } catch(e) {
        fb.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Erro de conexão.';
        fb.className = 'cfg-feedback error';
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Salvar alterações';
    }
}

// ── Highlight sidebar ao rolar ────────────────────────────────────────────────
function initSidenavHighlight() {
    const links = document.querySelectorAll('.cfg-sidenav-link[data-sec]');
    const sections = CFG_GROUPS.map(g => document.getElementById('sec-' + g.id)).filter(Boolean);

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.id.replace('sec-', '');
                links.forEach(l => l.classList.toggle('active', l.dataset.sec === id));
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(s => observer.observe(s));
}

// ── Theme toggle ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('cfg-theme-btn');
    const icon = document.getElementById('cfg-theme-icon');
    const dark = localStorage.getItem('dash-dark-mode') === '1';
    if (dark) { document.body.classList.add('dark'); if (icon) icon.className = 'fa-solid fa-sun'; }

    if (btn) {
        btn.addEventListener('click', () => {
            const isDark = document.body.classList.toggle('dark');
            localStorage.setItem('dash-dark-mode', isDark ? '1' : '0');
            if (icon) icon.className = isDark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        });
    }

    // Modal DB2 — botões de fechar
    const db2Modal = document.getElementById('db2-error-modal');
    const closeDb2 = () => { if (db2Modal) db2Modal.classList.remove('show'); };
    document.getElementById('db2-error-close')?.addEventListener('click', closeDb2);
    document.getElementById('db2-error-ok')?.addEventListener('click', closeDb2);
    if (db2Modal) db2Modal.addEventListener('click', e => { if (e.target === db2Modal) closeDb2(); });

    loadEnv();
});

// ── DB2 connection test ───────────────────────────────────────────────────────

function showDb2ErrorModal(message) {
    const modal = document.getElementById('db2-error-modal');
    const msg   = document.getElementById('db2-error-msg');
    if (msg) msg.textContent = message || 'Não foi possível conectar ao banco DB2.';
    if (modal) modal.classList.add('show');
}

async function testDb2Connection(body) {
    // Coleta os valores atuais dos campos DB2 do formulário
    // Para campos sensíveis (senha), usa o valor do cache envVars se o input estiver vazio
    const fields = ['DB2_HOST', 'DB2_PORT', 'DB2_NOME', 'DB2_USUARIO', 'DB2_SENHA', 'DB2_CONEXAO'];
    const vars = {};
    fields.forEach(k => {
        const el = body?.querySelector?.('[data-key="' + k + '"]') || document.getElementById('cfg-inp-' + k);
        const val = el ? el.value.trim() : '';
        // Se o campo está vazio (ex: senha mascarada), usa o valor do .env atual via envVars
        // envVars para campos sensíveis contém '••••••••' — nesse caso não enviamos
        // e o backend usa o valor atual do $_ENV
        vars[k] = val;
    });

    try {
        const res  = await fetch('/api/env/test-db2', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(vars),
        });
        const data = await res.json();
        return { ok: data.ok === true, message: data.message || '' };
    } catch {
        return { ok: false, message: 'Erro de conexão ao testar DB2.' };
    }
}


// ── Link Limits (custom section) ──────────────────────────────────────────────
function renderLinkLimitsBody(body) {
    body.textContent = '';
    body.style.padding = '32px 36px';

    // ── Header com descrição ──────────────────────────────────────────────
    const header = document.createElement('div');
    header.style.cssText = 'margin-bottom:32px;';
    
    const title = document.createElement('div');
    title.style.cssText = 'display:flex;align-items:center;gap:12px;margin-bottom:12px;';
    title.innerHTML = '<i class="fa-solid fa-link" style="font-size:1.8rem;color:#22d3ee;"></i><h2 style="font-size:1.6rem;font-weight:800;color:var(--cfg-text-primary);margin:0;">Limites de Links Encurtados</h2>';
    
    const desc = document.createElement('p');
    desc.style.cssText = 'color:var(--cfg-text-secondary);font-size:1.05rem;margin:0;line-height:1.7;max-width:700px;';
    desc.textContent = 'Controle quantos links encurtados cada usuário pode criar. Configure limites individuais ou aplique um limite padrão para todos os usuários.';
    
    header.appendChild(title);
    header.appendChild(desc);
    body.appendChild(header);

    // ── Cards Container ───────────────────────────────────────────────────
    const cardsContainer = document.createElement('div');
    cardsContainer.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(400px,1fr));gap:24px;';

    // ── Card 1: Limite por usuário ────────────────────────────────────────
    const cardUser = createLimitCard({
        id: 'user',
        icon: 'fa-user',
        iconColor: '#818cf8',
        title: 'Limite por Usuário',
        description: 'Configure o limite de links para um usuário específico',
        fields: [
            { type: 'text', id: 'll-user-id', label: 'UUID do Usuário', placeholder: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', icon: 'fa-fingerprint' },
            { type: 'select', id: 'll-max-links', label: 'Limite de Links', options: limitOptions(), icon: 'fa-hashtag' }
        ],
        buttonText: 'Salvar Limite',
        buttonIcon: 'fa-floppy-disk',
        buttonId: 'll-save-btn'
    });

    // ── Card 2: Limite global ─────────────────────────────────────────────
    const cardGlobal = createLimitCard({
        id: 'global',
        icon: 'fa-users',
        iconColor: '#22d3ee',
        title: 'Limite Global',
        description: 'Define o limite padrão para todos os usuários sem configuração individual',
        fields: [
            { type: 'select', id: 'll-global-max', label: 'Limite Padrão', options: limitOptions(), icon: 'fa-globe' }
        ],
        buttonText: 'Aplicar para Todos',
        buttonIcon: 'fa-check-double',
        buttonId: 'll-global-save',
        warning: true
    });

    cardsContainer.appendChild(cardUser);
    cardsContainer.appendChild(cardGlobal);
    body.appendChild(cardsContainer);

    // ── Feedback areas ────────────────────────────────────────────────────
    const fbUser = document.createElement('div');
    fbUser.id = 'll-feedback';
    fbUser.className = 'll-feedback-box';
    fbUser.style.display = 'none';
    cardUser.appendChild(fbUser);

    const currentDiv = document.createElement('div');
    currentDiv.id = 'll-current';
    currentDiv.className = 'll-current-box';
    currentDiv.style.display = 'none';
    cardUser.appendChild(currentDiv);

    const fbGlobal = document.createElement('div');
    fbGlobal.id = 'll-global-feedback';
    fbGlobal.className = 'll-feedback-box';
    fbGlobal.style.display = 'none';
    cardGlobal.appendChild(fbGlobal);

    // ── Wire up events ────────────────────────────────────────────────────
    setTimeout(() => initLinkLimitsEvents(), 50);
}

function createLimitCard(config) {
    const card = document.createElement('div');
    card.className = 'll-card';
    card.id = `ll-card-${config.id}`;
    
    const isDark = document.body.classList.contains('dark');
    const bgColor = isDark ? 'rgba(255,255,255,0.03)' : '#ffffff';
    const borderColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)';
    
    card.style.cssText = `
        background:${bgColor};
        border:1.5px solid ${borderColor};
        border-radius:16px;
        padding:28px;
        transition:all 0.3s ease;
        position:relative;
        overflow:hidden;
    `;

    // ── Card Header ───────────────────────────────────────────────────────
    const cardHeader = document.createElement('div');
    cardHeader.style.cssText = 'margin-bottom:24px;';
    
    const iconTitle = document.createElement('div');
    iconTitle.style.cssText = 'display:flex;align-items:center;gap:12px;margin-bottom:10px;';
    iconTitle.innerHTML = `
        <div style="width:48px;height:48px;border-radius:12px;background:rgba(${hexToRgb(config.iconColor)},0.12);display:flex;align-items:center;justify-content:center;">
            <i class="fa-solid ${config.icon}" style="font-size:1.4rem;color:${config.iconColor};"></i>
        </div>
        <h3 style="font-size:1.3rem;font-weight:800;color:var(--cfg-text-primary);margin:0;">${config.title}</h3>
    `;
    
    const cardDesc = document.createElement('p');
    cardDesc.style.cssText = 'color:var(--cfg-text-secondary);font-size:1rem;margin:0;line-height:1.6;';
    cardDesc.textContent = config.description;
    
    cardHeader.appendChild(iconTitle);
    cardHeader.appendChild(cardDesc);
    card.appendChild(cardHeader);

    // ── Edit Button (top right) ───────────────────────────────────────────
    const editBtn = document.createElement('button');
    editBtn.className = 'll-edit-btn';
    editBtn.dataset.cardId = config.id;
    editBtn.style.cssText = `
        position:absolute;
        top:20px;
        right:20px;
        padding:10px 18px;
        border-radius:10px;
        background:transparent;
        border:1.5px solid ${config.iconColor};
        color:${config.iconColor};
        font-size:0.95rem;
        font-weight:700;
        cursor:pointer;
        transition:all 0.2s ease;
        display:flex;
        align-items:center;
        gap:8px;
    `;
    editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar';
    card.appendChild(editBtn);

    // ── Fields Container ──────────────────────────────────────────────────
    const fieldsContainer = document.createElement('div');
    fieldsContainer.className = 'll-fields-container';
    fieldsContainer.style.cssText = 'display:none;margin-top:20px;';
    
    config.fields.forEach(field => {
        const fieldEl = field.type === 'select' 
            ? mkSelectFieldEnhanced(field.label, field.id, field.options, field.icon)
            : mkFieldEnhanced(field.label, field.type, field.id, field.placeholder, field.icon);
        fieldEl.style.marginBottom = '18px';
        fieldsContainer.appendChild(fieldEl);
    });

    card.appendChild(fieldsContainer);

    // ── Action Button ─────────────────────────────────────────────────────
    const actionBtn = document.createElement('button');
    actionBtn.id = config.buttonId;
    actionBtn.className = 'll-action-btn';
    actionBtn.style.cssText = `
        width:100%;
        padding:14px 24px;
        border-radius:10px;
        background:${config.iconColor};
        border:none;
        color:#ffffff;
        font-size:1.05rem;
        font-weight:700;
        cursor:pointer;
        transition:all 0.2s ease;
        display:none;
        align-items:center;
        justify-content:center;
        gap:10px;
        margin-top:20px;
        box-shadow:0 4px 12px rgba(${hexToRgb(config.iconColor)},0.3);
    `;
    actionBtn.innerHTML = `<i class="fa-solid ${config.buttonIcon}"></i> ${config.buttonText}`;
    
    if (config.warning) {
        actionBtn.style.background = '#f59e0b';
        actionBtn.style.boxShadow = '0 4px 12px rgba(245,158,11,0.3)';
    }
    
    card.appendChild(actionBtn);

    // ── Edit Button Logic ─────────────────────────────────────────────────
    editBtn.addEventListener('click', function() {
        const isEditing = editBtn.dataset.editing === '1';
        toggleCardEditing(card, config, !isEditing);
    });

    return card;
}

function toggleCardEditing(card, config, editing) {
    const editBtn = card.querySelector('.ll-edit-btn');
    const fieldsContainer = card.querySelector('.ll-fields-container');
    const actionBtn = card.querySelector('.ll-action-btn');
    const inputs = card.querySelectorAll('input, select');

    editBtn.dataset.editing = editing ? '1' : '0';

    if (editing) {
        // Modo edição
        editBtn.innerHTML = '<i class="fa-solid fa-xmark"></i> Cancelar';
        editBtn.style.background = 'rgba(239,68,68,0.1)';
        editBtn.style.borderColor = '#ef4444';
        editBtn.style.color = '#ef4444';
        
        fieldsContainer.style.display = 'block';
        actionBtn.style.display = 'flex';
        
        inputs.forEach(inp => {
            inp.disabled = false;
            inp.style.opacity = '1';
        });

        // Animação de entrada
        fieldsContainer.style.animation = 'slideDown 0.3s ease';
        actionBtn.style.animation = 'fadeIn 0.3s ease';
        
        // Limpa feedbacks
        const feedbacks = card.querySelectorAll('.ll-feedback-box, .ll-current-box');
        feedbacks.forEach(fb => fb.style.display = 'none');
        
    } else {
        // Modo visualização
        editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar';
        editBtn.style.background = 'transparent';
        editBtn.style.borderColor = config.iconColor;
        editBtn.style.color = config.iconColor;
        
        fieldsContainer.style.display = 'none';
        actionBtn.style.display = 'none';
        
        inputs.forEach(inp => {
            inp.disabled = true;
            inp.style.opacity = '0.5';
        });
    }
}

function hexToRgb(hex) {
    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? `${parseInt(result[1], 16)},${parseInt(result[2], 16)},${parseInt(result[3], 16)}` : '0,0,0';
}

function limitOptions() {
    return [
        { value: '-1', label: 'Ilimitado' },
        { value: '0',  label: '0 — Bloqueado' },
        { value: '1',  label: '1 link' },
        { value: '2',  label: '2 links' },
        { value: '3',  label: '3 links' },
        { value: '5',  label: '5 links' },
        { value: '10', label: '10 links' },
        { value: '20', label: '20 links' },
        { value: '50', label: '50 links' },
        { value: '100',label: '100 links' },
    ];
}

function mkFieldEnhanced(label, type, id, placeholder, icon) {
    const fg = document.createElement('div');
    fg.style.cssText = 'position:relative;';
    
    const lbl = document.createElement('label');
    lbl.textContent = label;
    lbl.setAttribute('for', id);
    lbl.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:1.05rem;font-weight:700;color:var(--cfg-text-secondary);margin-bottom:10px;';
    if (icon) {
        lbl.innerHTML = `<i class="fa-solid ${icon}" style="color:var(--cfg-accent);"></i> ${label}`;
    }

    const isDark = document.body.classList.contains('dark');
    const bgColor = isDark ? '#1e2235' : '#f8fafc';
    const textColor = isDark ? '#f1f5f9' : '#0f172a';
    const borderColor = isDark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.12)';

    const inp = document.createElement('input');
    inp.type = type;
    inp.id = id;
    inp.placeholder = placeholder || '';
    inp.autocomplete = 'off';
    inp.spellcheck = false;
    inp.style.cssText = `
        width:100%;
        padding:14px 16px;
        border-radius:10px;
        border:1.5px solid ${borderColor};
        background:${bgColor};
        color:${textColor};
        font-size:1.05rem;
        outline:none;
        font-family:inherit;
        transition:all 0.2s ease;
        min-height:52px;
    `;
    
    inp.addEventListener('focus', () => {
        inp.style.borderColor = 'var(--cfg-accent,#818cf8)';
        inp.style.boxShadow = '0 0 0 3px rgba(129,140,248,0.1)';
    });
    inp.addEventListener('blur', () => {
        inp.style.borderColor = borderColor;
        inp.style.boxShadow = 'none';
    });
    
    fg.appendChild(lbl);
    fg.appendChild(inp);
    return fg;
}

function mkSelectFieldEnhanced(label, id, options, icon) {
    const fg = document.createElement('div');
    fg.style.cssText = 'position:relative;';
    
    const lbl = document.createElement('label');
    lbl.textContent = label;
    lbl.setAttribute('for', id);
    lbl.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:1.05rem;font-weight:700;color:var(--cfg-text-secondary);margin-bottom:10px;';
    if (icon) {
        lbl.innerHTML = `<i class="fa-solid ${icon}" style="color:var(--cfg-accent);"></i> ${label}`;
    }

    const isDark = document.body.classList.contains('dark');
    const bgColor = isDark ? '#1e2235' : '#f8fafc';
    const textColor = isDark ? '#f1f5f9' : '#0f172a';
    const borderColor = isDark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.12)';

    const sel = document.createElement('select');
    sel.id = id;
    sel.style.cssText = `
        width:100%;
        padding:14px 16px;
        border-radius:10px;
        border:1.5px solid ${borderColor};
        background:${bgColor};
        color:${textColor};
        font-size:1.05rem;
        outline:none;
        font-family:inherit;
        cursor:pointer;
        min-height:52px;
        transition:all 0.2s ease;
        appearance:none;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23818cf8' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
        background-repeat:no-repeat;
        background-position:right 16px center;
        padding-right:44px;
    `;
    
    sel.addEventListener('focus', () => {
        sel.style.borderColor = 'var(--cfg-accent,#818cf8)';
        sel.style.boxShadow = '0 0 0 3px rgba(129,140,248,0.1)';
    });
    sel.addEventListener('blur', () => {
        sel.style.borderColor = borderColor;
        sel.style.boxShadow = 'none';
    });
    
    options.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt.value;
        option.textContent = opt.label;
        sel.appendChild(option);
    });
    
    fg.appendChild(lbl);
    fg.appendChild(sel);
    return fg;
}

function mkButton(text, icon, id) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'align-self:flex-end;';
    const btn = document.createElement('button');
    btn.id = id;
    btn.className = 'cfg-save-btn';
    btn.style.cssText = 'display:inline-flex;align-items:center;gap:9px;padding:13px 24px;border-radius:10px;background:linear-gradient(135deg,#818cf8,#6366f1);color:#fff;font-size:1rem;font-weight:700;border:none;cursor:pointer;transition:opacity .2s,transform .15s;white-space:nowrap;min-height:50px;box-shadow:0 2px 12px rgba(99,102,241,.25);';
    btn.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + text;
    btn.addEventListener('mouseenter', () => btn.style.opacity = '.88');
    btn.addEventListener('mouseleave', () => btn.style.opacity = '1');
    wrap.appendChild(btn);
    return wrap;
}

function showLLFeedback(id, msg, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    
    // Remove classes antigas
    el.classList.remove('success', 'error');
    
    // Adiciona classe apropriada
    el.classList.add(ok ? 'success' : 'error');
    
    // Adiciona ícone e mensagem
    const icon = ok ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-circle-exclamation"></i>';
    el.innerHTML = icon + ' ' + msg;
    
    el.style.display = 'flex';
    
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 5000);
}

function initLinkLimitsEvents() {
    const saveBtn    = document.getElementById('ll-save-btn');
    const userIdInp  = document.getElementById('ll-user-id');
    const maxSel     = document.getElementById('ll-max-links');
    const currentDiv = document.getElementById('ll-current');

    if (!saveBtn) return;

    // Ao sair do campo userId, carrega o limite atual
    userIdInp.addEventListener('blur', async function () {
        const uid = this.value.trim();
        if (!uid || !/^[0-9a-f-]{36}$/i.test(uid)) return;
        try {
            const res = await fetch('/api/links/user-limit/' + uid, { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            if (currentDiv) {
                currentDiv.style.display = 'flex';
                const limit = data.max_links;
                const total = data.total ?? 0;
                
                let limitText = '';
                if (limit === -1) {
                    limitText = '<i class="fa-solid fa-infinity"></i> Limite atual: <strong>Ilimitado</strong> | Links criados: <strong>' + total + '</strong>';
                } else if (limit === 0) {
                    limitText = '<i class="fa-solid fa-ban"></i> Limite atual: <strong>Bloqueado (0)</strong> | Links criados: <strong>' + total + '</strong>';
                } else {
                    const remaining = Math.max(0, limit - total);
                    limitText = '<i class="fa-solid fa-chart-simple"></i> Limite atual: <strong>' + limit + '</strong> | Links criados: <strong>' + total + '</strong> | Restante: <strong>' + remaining + '</strong>';
                }
                
                currentDiv.innerHTML = limitText;
            }
            const val = String(data.max_links ?? -1);
            if (maxSel.querySelector('option[value="' + val + '"]')) maxSel.value = val;
        } catch (_) {}
    });

    // Salvar limite individual
    saveBtn.addEventListener('click', async function () {
        const uid      = userIdInp.value.trim();
        const maxLinks = parseInt(maxSel.value, 10);
        if (!uid) { showLLFeedback('ll-feedback', 'Informe o UUID do usuário.', false); return; }
        if (!/^[0-9a-f-]{36}$/i.test(uid)) { showLLFeedback('ll-feedback', 'UUID inválido.', false); return; }

        saveBtn.disabled = true;
        const orig = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando...';
        try {
            const res = await fetch('/api/links/user-limit/' + uid, {
                method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ max_links: maxLinks }),
            });
            const data = await res.json();
            if (!res.ok) { showLLFeedback('ll-feedback', data.error || 'Erro ao salvar.', false); return; }
            showLLFeedback('ll-feedback', 'Limite salvo com sucesso!', true);
        } catch (e) { showLLFeedback('ll-feedback', 'Erro: ' + e.message, false); }
        finally { saveBtn.disabled = false; saveBtn.innerHTML = orig; }
    });

    // Salvar limite global
    const globalBtn = document.getElementById('ll-global-save');
    if (globalBtn) {
        const confirmModal  = document.getElementById('ll-global-confirm-modal');
        const confirmText   = document.getElementById('ll-global-confirm-text');
        const confirmOk     = document.getElementById('ll-global-confirm-ok');
        const confirmCancel = document.getElementById('ll-global-confirm-cancel');
        const confirmClose  = document.getElementById('ll-global-confirm-close');

        const closeConfirm = () => confirmModal?.classList.remove('show');
        confirmCancel?.addEventListener('click', closeConfirm);
        confirmClose?.addEventListener('click',  closeConfirm);

        globalBtn.addEventListener('click', function () {
            const maxLinks = parseInt(document.getElementById('ll-global-max').value, 10);
            if (confirmText) {
                confirmText.textContent = 'Aplicar limite de ' +
                    (maxLinks === -1 ? 'Ilimitado' : maxLinks) +
                    ' para TODOS os usuários?';
            }
            confirmModal?.classList.add('show');

            // Remove listener anterior para evitar duplicação
            const newOk = confirmOk?.cloneNode(true);
            confirmOk?.parentNode?.replaceChild(newOk, confirmOk);

            newOk?.addEventListener('click', async function () {
                closeConfirm();
                globalBtn.disabled = true;
                const orig = globalBtn.innerHTML;
                globalBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Aplicando...';
                try {
                    const res = await fetch('/api/links/user-limit/all', {
                        method: 'PUT', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ max_links: maxLinks }),
                    });
                    const data = await res.json();
                    if (!res.ok) { showLLFeedback('ll-global-feedback', data.error || 'Erro.', false); return; }
                    showLLFeedback('ll-global-feedback', 'Limite aplicado para ' + (data.updated ?? 'todos') + ' usuário(s)!', true);
                } catch (e) { showLLFeedback('ll-global-feedback', 'Erro: ' + e.message, false); }
                finally { globalBtn.disabled = false; globalBtn.innerHTML = orig; }
            });
        });
    }
}
