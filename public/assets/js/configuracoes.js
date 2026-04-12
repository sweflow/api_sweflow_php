'use strict';
// ── Página de Configurações — env-editor completo ────────────────────────────

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
    body.style.padding = '28px 32px';

    // ── Botão Editar próprio ──────────────────────────────────────────────
    const editBar = document.createElement('div');
    editBar.style.cssText = 'display:flex;justify-content:flex-end;margin-bottom:20px;';
    const editBtn = document.createElement('button');
    editBtn.id = 'll-edit-btn';
    editBtn.style.cssText = 'display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:9px;background:transparent;border:1.5px solid var(--cfg-accent,#818cf8);color:var(--cfg-accent,#818cf8);font-size:1rem;font-weight:700;cursor:pointer;transition:background .15s,color .15s;';
    editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar';
    editBar.appendChild(editBtn);
    body.appendChild(editBar);

    // Descrição
    const desc = document.createElement('p');
    desc.style.cssText = 'color:var(--cfg-text-secondary);font-size:1rem;margin-bottom:24px;line-height:1.65;';
    desc.textContent = 'Defina quantos links cada usuário pode criar. -1 = ilimitado, 0 = bloqueado. Aplique para um usuário específico ou para todos os usuários.';
    body.appendChild(desc);

    // ── Limite por usuário ────────────────────────────────────────────────
    const h3user = document.createElement('h3');
    h3user.style.cssText = 'font-size:.95rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--cfg-accent,#818cf8);margin-bottom:14px;';
    h3user.textContent = 'Limite por usuário';
    body.appendChild(h3user);

    const rowUser = document.createElement('div');
    rowUser.style.cssText = 'display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:20px;';

    const fgUid = mkField('UUID do usuário', 'text', 'll-user-id', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
    fgUid.style.flex = '1';
    const fgSel = mkSelectField('Limite', 'll-max-links', limitOptions());
    fgSel.style.minWidth = '200px';
    const btnSave = mkButton('Salvar', 'fa-floppy-disk', 'll-save-btn');

    rowUser.appendChild(fgUid);
    rowUser.appendChild(fgSel);
    rowUser.appendChild(btnSave);
    body.appendChild(rowUser);

    const fbUser = document.createElement('div');
    fbUser.id = 'll-feedback';
    fbUser.style.cssText = 'display:none;margin-bottom:16px;padding:12px 16px;border-radius:9px;font-size:1rem;font-weight:600;';
    body.appendChild(fbUser);

    const currentDiv = document.createElement('div');
    currentDiv.id = 'll-current';
    currentDiv.style.cssText = 'display:none;margin-bottom:24px;padding:16px;background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:10px;font-size:1rem;';
    body.appendChild(currentDiv);

    // ── Limite global ─────────────────────────────────────────────────────
    const sep = document.createElement('hr');
    sep.style.cssText = 'border:none;border-top:1px solid var(--cfg-border);margin:24px 0;';
    body.appendChild(sep);

    const h3all = document.createElement('h3');
    h3all.style.cssText = 'font-size:.95rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--cfg-accent,#818cf8);margin-bottom:14px;';
    h3all.textContent = 'Limite global (todos os usuários)';
    body.appendChild(h3all);

    const descAll = document.createElement('p');
    descAll.style.cssText = 'color:var(--cfg-text-secondary);font-size:.95rem;margin-bottom:16px;line-height:1.55;';
    descAll.textContent = 'Define o limite padrão para TODOS os usuários que não têm limite individual configurado.';
    body.appendChild(descAll);

    const rowAll = document.createElement('div');
    rowAll.style.cssText = 'display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:16px;';

    const fgAllSel = mkSelectField('Limite padrão', 'll-global-max', limitOptions());
    fgAllSel.style.minWidth = '220px';
    const btnAllSave = mkButton('Aplicar para todos', 'fa-users', 'll-global-save');

    rowAll.appendChild(fgAllSel);
    rowAll.appendChild(btnAllSave);
    body.appendChild(rowAll);

    const fbAll = document.createElement('div');
    fbAll.id = 'll-global-feedback';
    fbAll.style.cssText = 'display:none;padding:12px 16px;border-radius:9px;font-size:1rem;font-weight:600;';
    body.appendChild(fbAll);

    // ── Estado inicial: tudo desabilitado ─────────────────────────────────
    setLLEditing(false);

    // ── Botão Editar / Cancelar ───────────────────────────────────────────
    editBtn.addEventListener('click', function () {
        const editing = editBtn.dataset.editing === '1';
        setLLEditing(!editing);
    });

    // ── Wire up save events ───────────────────────────────────────────────
    setTimeout(() => initLinkLimitsEvents(), 50);
}

function setLLEditing(editing) {
    const editBtn   = document.getElementById('ll-edit-btn');
    const userIdInp = document.getElementById('ll-user-id');
    const maxSel    = document.getElementById('ll-max-links');
    const saveBtn   = document.getElementById('ll-save-btn');
    const globalSel = document.getElementById('ll-global-max');
    const globalBtn = document.getElementById('ll-global-save');

    if (!editBtn) return;

    editBtn.dataset.editing = editing ? '1' : '0';

    if (editing) {
        editBtn.innerHTML = '<i class="fa-solid fa-xmark"></i> Cancelar';
        editBtn.style.background = 'rgba(239,68,68,.1)';
        editBtn.style.borderColor = '#ef4444';
        editBtn.style.color = '#ef4444';
    } else {
        editBtn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Editar';
        editBtn.style.background = 'transparent';
        editBtn.style.borderColor = 'var(--cfg-accent,#818cf8)';
        editBtn.style.color = 'var(--cfg-accent,#818cf8)';
        // Limpa feedbacks ao cancelar
        const fb1 = document.getElementById('ll-feedback');
        const fb2 = document.getElementById('ll-global-feedback');
        const cur = document.getElementById('ll-current');
        if (fb1) fb1.style.display = 'none';
        if (fb2) fb2.style.display = 'none';
        if (cur) cur.style.display = 'none';
    }

    const disabledStyle = 'opacity:.45;cursor:not-allowed;pointer-events:none;';
    const enabledStyle  = 'opacity:1;cursor:pointer;pointer-events:auto;';

    [userIdInp, maxSel, globalSel].forEach(el => {
        if (!el) return;
        el.disabled = !editing;
        el.style.opacity = editing ? '1' : '.45';
        el.style.cursor  = editing ? '' : 'not-allowed';
    });

    [saveBtn, globalBtn].forEach(el => {
        if (!el) return;
        el.disabled = !editing;
        el.style.cssText = el.style.cssText.replace(/opacity:[^;]+;?/g, '');
        el.style.opacity = editing ? '1' : '.45';
        el.style.cursor  = editing ? 'pointer' : 'not-allowed';
        el.style.pointerEvents = editing ? 'auto' : 'none';
    });
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

function mkField(label, type, id, placeholder) {
    const fg = document.createElement('div');
    const lbl = document.createElement('label');
    lbl.textContent = label;
    lbl.setAttribute('for', id);
    lbl.style.cssText = 'display:block;font-size:1rem;font-weight:700;color:var(--cfg-text-secondary);margin-bottom:8px;';

    const isDark = document.body.classList.contains('dark');
    const bgColor = isDark ? '#1e2235' : '#f8fafc';
    const textColor = isDark ? '#f1f5f9' : '#0f172a';
    const borderColor = isDark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.14)';

    const inp = document.createElement('input');
    inp.type = type; inp.id = id; inp.placeholder = placeholder || '';
    inp.autocomplete = 'off'; inp.spellcheck = false;
    inp.style.cssText = [
        'width:100%',
        'padding:13px 16px',
        'border-radius:10px',
        'border:1.5px solid ' + borderColor,
        'background:' + bgColor,
        'color:' + textColor,
        'font-size:1rem',
        'outline:none',
        'font-family:inherit',
        'transition:border-color .2s',
        'min-height:50px',
    ].join(';') + ';';
    inp.addEventListener('focus', () => inp.style.borderColor = 'var(--cfg-accent,#818cf8)');
    inp.addEventListener('blur',  () => inp.style.borderColor = borderColor);
    fg.appendChild(lbl); fg.appendChild(inp);
    return fg;
}

function mkSelectField(label, id, options) {
    const fg = document.createElement('div');
    fg.style.cssText = 'position:relative;';
    const lbl = document.createElement('label');
    lbl.textContent = label;
    lbl.setAttribute('for', id);
    lbl.style.cssText = 'display:block;font-size:1rem;font-weight:700;color:var(--cfg-text-secondary);margin-bottom:8px;';

    // Detecta o tema atual para aplicar a cor correta
    const isDark = document.body.classList.contains('dark');
    const bgColor = isDark ? '#1e2235' : '#f8fafc';
    const textColor = isDark ? '#f1f5f9' : '#0f172a';
    const borderColor = isDark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.14)';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative;';

    const sel = document.createElement('select');
    sel.id = id;
    sel.style.cssText = [
        'width:100%',
        'padding:13px 40px 13px 16px',
        'border-radius:10px',
        'border:1.5px solid ' + borderColor,
        'background:' + bgColor,
        'color:' + textColor,
        'font-size:1rem',
        'outline:none',
        'font-family:inherit',
        'cursor:pointer',
        'min-height:50px',
        'transition:border-color .2s',
        '-webkit-appearance:none',
        '-moz-appearance:none',
        'appearance:none',
    ].join(';') + ';';

    sel.addEventListener('focus', () => sel.style.borderColor = 'var(--cfg-accent,#818cf8)');
    sel.addEventListener('blur',  () => sel.style.borderColor = borderColor);

    options.forEach(o => {
        const opt = document.createElement('option');
        opt.value = o.value; opt.textContent = o.label;
        // Força cor de fundo nas options também
        opt.style.background = bgColor;
        opt.style.color = textColor;
        sel.appendChild(opt);
    });

    // Seta customizada
    const arrow = document.createElement('span');
    arrow.setAttribute('aria-hidden', 'true');
    arrow.style.cssText = 'position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:' + (isDark ? '#94a3b8' : '#64748b') + ';font-size:.85rem;';
    arrow.innerHTML = '&#9660;';

    wrap.appendChild(sel);
    wrap.appendChild(arrow);
    fg.appendChild(lbl);
    fg.appendChild(wrap);
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
    el.textContent = msg;
    el.style.display = 'block';
    el.style.background = ok ? 'rgba(34,197,94,.1)' : 'rgba(239,68,68,.1)';
    el.style.border = ok ? '1px solid rgba(34,197,94,.25)' : '1px solid rgba(239,68,68,.25)';
    el.style.color = ok ? '#22c55e' : '#ef4444';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 4000);
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
                currentDiv.style.display = 'block';
                const limit = data.max_links;
                const total = data.total ?? 0;
                currentDiv.textContent = limit === -1
                    ? 'Limite atual: Ilimitado | Links criados: ' + total
                    : limit === 0
                        ? 'Limite atual: Bloqueado (0) | Links criados: ' + total
                        : 'Limite atual: ' + limit + ' | Links criados: ' + total + ' | Restante: ' + Math.max(0, limit - total);
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
        globalBtn.addEventListener('click', async function () {
            const maxLinks = parseInt(document.getElementById('ll-global-max').value, 10);
            const confirmed = window.confirm(
                'Aplicar limite de ' + (maxLinks === -1 ? 'Ilimitado' : maxLinks) +
                ' para TODOS os usuários?\n\nIsso sobrescreve limites individuais.'
            );
            if (!confirmed) return;

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
    }
}
