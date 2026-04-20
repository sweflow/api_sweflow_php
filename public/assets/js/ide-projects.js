'use strict';

function $(id) { return document.getElementById(id); }

function el(tag, attrs, children) {
    var e = document.createElement(tag);
    if (attrs) {
        Object.keys(attrs).forEach(function (k) {
            if (k === 'className') e.className = attrs[k];
            else if (k === 'style' && typeof attrs[k] === 'object') Object.assign(e.style, attrs[k]);
            else if (k.startsWith('on')) e.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
            else e.setAttribute(k, attrs[k]);
        });
    }
    if (children) {
        (Array.isArray(children) ? children : [children]).forEach(function (c) {
            if (c == null) return;
            e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
    }
    return e;
}

function icon(cls) {
    var i = document.createElement('i');
    i.className = cls;
    i.setAttribute('aria-hidden', 'true');
    return i;
}

function setBtn(btn, iconClass, text) {
    btn.textContent = '';
    btn.appendChild(icon(iconClass));
    btn.appendChild(document.createTextNode(' ' + text));
}

function toast(msg) {
    var t = $('idep-toast');
    if (!t) return;
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._t);
    t._t = setTimeout(function () { t.style.opacity = '0'; }, 2800);
}

async function api(method, url, body) {
    var opts = { method: method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } };
    if (body) opts.body = JSON.stringify(body);
    var res = await fetch(url, opts);

    // Token expirado — redireciona para login apenas se não estiver em loop
    if (res.status === 401) {
        // Verifica se já tentou redirecionar recentemente (evita loop)
        var lastRedirect = sessionStorage.getItem('last_auth_redirect');
        var now = Date.now();
        if (!lastRedirect || (now - parseInt(lastRedirect)) > 5000) {
            sessionStorage.setItem('last_auth_redirect', now.toString());
            window.location.replace('/ide/login');
        }
        throw new Error('Sessão expirada');
    }

    var data = await res.json().catch(function () { return {}; });
    if (!res.ok) {
        var err = new Error(data.error || 'HTTP ' + res.status);
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data;
}

function showModal(id) { var e = $(id); if (e) { e.removeAttribute('aria-hidden'); e.classList.add('show'); } }
function hideModal(id) { 
    var e = $(id); 
    if (e) { 
        // Remove o foco de qualquer elemento dentro do modal antes de ocultá-lo
        // Isso previne o aviso de acessibilidade do navegador
        if (document.activeElement && e.contains(document.activeElement)) {
            document.activeElement.blur();
        }
        e.setAttribute('aria-hidden', 'true'); 
        e.classList.remove('show'); 
    } 
}

// ── Load Projects ─────────────────────────────────────────────────────────
async function loadProjects(bust) {
    $('idep-loading').style.display = 'flex';
    $('idep-empty').style.display = 'none';
    $('idep-grid').style.display = 'none';

    try {
        // Endpoint agregador: projetos + limites em 1 request, 1 conexão ao banco
        // bust=true adiciona timestamp para ignorar o cache de 10s do servidor
        var url  = '/api/ide/dashboard' + (bust ? '?_=' + Date.now() : '');
        var data     = await api('GET', url);
        var payload  = data.data || data;
        var projects = payload.projects || [];
        var limits   = payload.limits   || { unlimited: true, blocked: false, count: projects.length, limit: -1, remaining: null };

        updateLimitInfo(limits, projects.length);
        renderGrid(projects);
    } catch (e) {
        var container = $('idep-loading');
        container.textContent = '';
        container.appendChild(el('i', { className: 'fa-solid fa-circle-exclamation fa-2x', style: { color: '#f38ba8' } }));
        container.appendChild(el('p', { style: { color: '#e11d48' } }, [e.message]));
    }
}

var currentLimits = null;
var hasActiveDatabaseConnection = false; // Flag global para verificar se há conexão ativa

function updateLimitInfo(limits, count) {
    currentLimits = limits;
    var hero = document.querySelector('.idep-hero-text p');
    if (!hero) return;

    var btnNew = $('btn-new-project');
    var btnFirst = $('btn-first-project');
    var disabled = false;

    if (limits.blocked) {
        hero.textContent = 'Sua conta está impedida de criar novos projetos.';
        disabled = true;
        // Mostra modal automaticamente
        showLimitModal('blocked', limits, count);
    } else if (limits.unlimited) {
        hero.textContent = 'Crie, edite e publique módulos para o framework Vupi.us API';
    } else {
        var remaining = limits.remaining !== null ? limits.remaining : 0;
        hero.textContent = 'Projetos: ' + (limits.count_since || 0) + '/' + limits.limit + ' (' + remaining + ' restante' + (remaining !== 1 ? 's' : '') + ')';
        if (remaining <= 0) {
            disabled = true;
            // Mostra modal automaticamente
            showLimitModal('reached', limits, count);
        }
    }

    if (btnNew) { btnNew.disabled = disabled; btnNew.classList.toggle('idep-btn-blocked', disabled); }
    if (btnFirst) { btnFirst.disabled = disabled; btnFirst.classList.toggle('idep-btn-blocked', disabled); }
}

function showLimitModal(type, limits, totalCount) {
    var iconEl = $('modal-limit-icon');
    var titleEl = $('modal-limit-title');
    var subtitleEl = $('modal-limit-subtitle');
    var alertEl = $('modal-limit-alert');
    var msgEl = $('modal-limit-msg');
    var statsEl = $('modal-limit-stats');
    var helpEl = $('modal-limit-help');

    // Reset
    alertEl.className = 'idep-limit-alert';
    statsEl.textContent = '';

    if (type === 'blocked') {
        iconEl.style.background = 'linear-gradient(135deg,#ef4444,#f87171)';
        iconEl.textContent = '';
        iconEl.appendChild(icon('fa-solid fa-lock'));
        titleEl.textContent = 'Criação de projetos bloqueada';
        subtitleEl.textContent = 'Sua conta não tem permissão para criar novos projetos';
        alertEl.classList.add('idep-limit-alert-blocked');
        msgEl.textContent = 'O administrador do sistema bloqueou a criação de novos projetos para sua conta. Seus projetos existentes continuam acessíveis normalmente.';
        helpEl.textContent = 'Entre em contato com o suporte da Vupi.us API para solicitar a liberação.';
    } else {
        iconEl.style.background = 'linear-gradient(135deg,#f59e0b,#fbbf24)';
        iconEl.textContent = '';
        iconEl.appendChild(icon('fa-solid fa-triangle-exclamation'));
        titleEl.textContent = 'Limite de projetos atingido';
        subtitleEl.textContent = 'Você atingiu o número máximo de projetos permitidos';
        alertEl.classList.add('idep-limit-alert-reached');
        msgEl.textContent = 'Você já criou ' + (limits.count_since || 0) + ' de ' + limits.limit + ' projeto' + (limits.limit !== 1 ? 's' : '') + ' permitido' + (limits.limit !== 1 ? 's' : '') + '. Para criar um novo, exclua um projeto existente ou solicite aumento do limite.';
        helpEl.textContent = 'Entre em contato com o suporte para solicitar aumento do limite.';
    }

    // Stats
    var statTotal = el('div', { className: 'idep-limit-stat' }, [icon('fa-solid fa-folder'), ' Total: ' + (totalCount || 0)]);
    statsEl.appendChild(statTotal);

    if (!limits.blocked && limits.limit > 0) {
        var statLimit = el('div', { className: 'idep-limit-stat' }, [icon('fa-solid fa-gauge-high'), ' Limite: ' + limits.limit]);
        statsEl.appendChild(statLimit);
    }

    showModal('modal-limit');
}

function showDatabaseConnectionRequiredModal() {
    var iconEl = $('modal-limit-icon');
    var titleEl = $('modal-limit-title');
    var subtitleEl = $('modal-limit-subtitle');
    var alertEl = $('modal-limit-alert');
    var msgEl = $('modal-limit-msg');
    var statsEl = $('modal-limit-stats');
    var helpEl = $('modal-limit-help');

    // Reset
    alertEl.className = 'idep-limit-alert';
    statsEl.textContent = '';

    iconEl.style.background = 'linear-gradient(135deg,#3b82f6,#60a5fa)';
    iconEl.textContent = '';
    iconEl.appendChild(icon('fa-solid fa-database'));
    titleEl.textContent = 'Conexão de banco de dados necessária';
    subtitleEl.textContent = 'Configure uma conexão antes de criar projetos';
    alertEl.classList.add('idep-limit-alert-info');
    msgEl.textContent = 'Para criar projetos na IDE, você precisa configurar uma conexão de banco de dados personalizada. Isso garante que suas migrations e tabelas sejam criadas no banco correto.';
    helpEl.textContent = '';

    showModal('modal-limit');
}

function buildCard(p) {
    var updated = new Date(p.updated_at).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' });

    // ── Card icon com gradient ──
    var cardIcon = el('div', { className: 'idep-card-icon', 'aria-hidden': 'true' }, [icon('fa-solid fa-cube')]);

    // ── Info: nome + badge módulo ──
    var cardName = el('h2', { className: 'idep-card-name' }, [p.name]);
    var moduleBadge = el('span', { className: 'idep-card-module' }, [
        icon('fa-solid fa-code-branch'),
        document.createTextNode(' ' + p.module_name)
    ]);
    var cardInfo = el('div', { className: 'idep-card-info' }, [cardName, moduleBadge]);
    var cardHeader = el('div', { className: 'idep-card-header' }, [cardIcon, cardInfo]);

    var bodyChildren = [cardHeader];

    // ── Descrição (se houver) ──
    if (p.description) {
        bodyChildren.push(el('p', { className: 'idep-card-desc' }, [p.description]));
    }

    // ── Stats row ──
    var fileStat = el('div', { className: 'idep-card-stat' }, [
        icon('fa-solid fa-file-code'),
        el('span', {}, [p.file_count + ' arquivo' + (p.file_count !== 1 ? 's' : '')])
    ]);
    var dateStat = el('div', { className: 'idep-card-stat' }, [
        icon('fa-solid fa-clock'),
        el('span', {}, [updated])
    ]);
    bodyChildren.push(el('div', { className: 'idep-card-stats' }, [fileStat, dateStat]));

    var cardBody = el('div', { className: 'idep-card-body' }, bodyChildren);

    // ── Footer com botões ──
    var btnOpen = el('button', {
        className: 'idep-card-btn idep-card-btn-open',
        'aria-label': 'Abrir IDE do projeto ' + p.name,
        onClick: function (ev) { ev.stopPropagation(); openProject(p.id); }
    }, [icon('fa-solid fa-arrow-right'), document.createTextNode(' Abrir IDE')]);

    var btnDel = el('button', {
        className: 'idep-card-btn idep-card-btn-delete',
        'aria-label': 'Excluir projeto ' + p.name,
        onClick: function (ev) { ev.stopPropagation(); confirmDelete(p.id, p.name); }
    }, [icon('fa-solid fa-trash')]);

    var actions = el('div', { className: 'idep-card-actions' }, [btnOpen, btnDel]);
    actions.addEventListener('click', function (ev) { ev.stopPropagation(); });

    var footer = el('div', { className: 'idep-card-footer' }, [actions]);

    var card = el('article', {
        className: 'idep-card',
        tabindex: '0',
        role: 'button',
        'aria-label': 'Abrir projeto ' + p.name,
        onClick: function () { openProject(p.id); },
        onKeydown: function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openProject(p.id); } }
    }, [cardBody, footer]);

    return card;
}

function renderGrid(projects) {
    $('idep-loading').style.display = 'none';

    if (!projects.length) {
        $('idep-empty').style.display = 'flex';
        return;
    }

    var grid = $('idep-grid');
    grid.style.display = 'grid';
    grid.textContent = '';
    projects.forEach(function (p) {
        grid.appendChild(buildCard(p));
    });
}

function openProject(id) {
    window.location.href = '/dashboard/ide/editor?project=' + encodeURIComponent(id);
}

// ── New Project ───────────────────────────────────────────────────────────
async function openNewProjectModal() {
    // Se limites ainda não foram carregados, busca agora
    if (!currentLimits) {
        try {
            var limits = await api('GET', '/api/ide/my-limits');
            currentLimits = limits;
        } catch (e) {}
    }

    // Verifica limite antes de abrir o modal
    if (currentLimits) {
        if (currentLimits.blocked) {
            showLimitModal('blocked', currentLimits, currentLimits.count || 0);
            return;
        }
        if (!currentLimits.unlimited && currentLimits.remaining !== null && currentLimits.remaining <= 0) {
            showLimitModal('reached', currentLimits, currentLimits.count || 0);
            return;
        }
    }

    // ── Validação: desenvolvedor deve ter conexão de banco configurada ──
    if (!hasActiveDatabaseConnection) {
        showDatabaseConnectionRequiredModal();
        return;
    }

    $('inp-project-name').value = '';
    $('inp-module-name').value = '';
    $('inp-project-desc').value = '';
    $('idep-module-preview').style.display = 'none';
    $('inp-scaffold').checked = false;
    $('modal-np-error').style.display = 'none';
    $('module-name-check').style.display = 'none';
    moduleNameValid = false;

    showModal('modal-new-project');
    setTimeout(function () { $('inp-project-name').focus(); }, 120);
}

$('btn-new-project').addEventListener('click', openNewProjectModal);

// Botão "Criar Primeiro Projeto" na tela vazia
var btnFirst = $('btn-first-project');
if (btnFirst) btnFirst.addEventListener('click', openNewProjectModal);

var moduleCheckTimer = null;
var moduleNameValid = false;

$('inp-module-name').addEventListener('input', function () {
    var v = this.value.trim();
    $('idep-module-preview').style.display = v ? 'flex' : 'none';
    $('preview-module-name').textContent = v;

    var checkEl = $('module-name-check');
    moduleNameValid = false;

    if (!v) {
        checkEl.style.display = 'none';
        return;
    }

    // Validação de formato (instantânea)
    if (!/^[A-Za-z][A-Za-z0-9]*$/.test(v)) {
        showModuleCheck('err', 'fa-solid fa-circle-xmark', 'Apenas letras e números, sem espaços (PascalCase)');
        return;
    }

    // Debounce — verifica no servidor após 400ms
    showModuleCheck('loading', 'fa-solid fa-spinner fa-spin', 'Verificando...');
    clearTimeout(moduleCheckTimer);
    moduleCheckTimer = setTimeout(function () { checkModuleAvailability(v); }, 400);
});

async function checkModuleAvailability(name) {
    try {
        var data = await api('GET', '/api/ide/check-module/' + encodeURIComponent(name));
        if (data.available) {
            moduleNameValid = true;
            showModuleCheck('ok', 'fa-solid fa-circle-check', data.reason || 'Nome disponível');
        } else {
            moduleNameValid = false;
            showModuleCheck('err', 'fa-solid fa-circle-xmark', data.reason || 'Nome indisponível');
        }
    } catch (e) {
        moduleNameValid = false;
        showModuleCheck('warn', 'fa-solid fa-triangle-exclamation', 'Erro ao verificar: ' + e.message);
    }
}

function showModuleCheck(type, iconCls, msg) {
    var checkEl = $('module-name-check');
    checkEl.style.display = 'flex';
    checkEl.textContent = '';
    checkEl.className = 'idep-module-check idep-module-check-' + type;
    var ic = document.createElement('i');
    ic.className = iconCls;
    ic.setAttribute('aria-hidden', 'true');
    checkEl.appendChild(ic);
    checkEl.appendChild(document.createTextNode(' ' + msg));
}

$('modal-np-cancel').addEventListener('click', function () { hideModal('modal-new-project'); });
$('modal-np-close').addEventListener('click', function () { hideModal('modal-new-project'); });

$('modal-np-confirm').addEventListener('click', async function () {
    var name = $('inp-project-name').value.trim();
    var moduleName = $('inp-module-name').value.trim();
    var description = $('inp-project-desc').value.trim();
    var errEl = $('modal-np-error');
    errEl.style.display = 'none';

    if (!name || !moduleName) {
        errEl.textContent = 'Preencha o nome do projeto e o nome do módulo.';
        errEl.style.display = 'block';
        return;
    }
    if (!/^[A-Za-z][A-Za-z0-9]*$/.test(moduleName)) {
        errEl.textContent = 'Nome do módulo deve ser PascalCase (apenas letras e números, sem espaços).';
        errEl.style.display = 'block';
        return;
    }
    if (!moduleNameValid) {
        errEl.textContent = 'O nome do módulo não está disponível. Escolha outro nome.';
        errEl.style.display = 'block';
        return;
    }

    var btn = $('modal-np-confirm');
    btn.disabled = true;
    setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Criando...');

    try {
        var data = await api('POST', '/api/ide/projects', {
            name: name,
            module_name: moduleName,
            description: description,
            scaffold: $('inp-scaffold').checked
        });
        hideModal('modal-new-project');
        openProject(data.project.id);
    } catch (e) {
        // Se 403 = bloqueio de limite, mostra modal de limite
        if (e.status === 403) {
            hideModal('modal-new-project');
            try {
                var limits = await api('GET', '/api/ide/my-limits');
                currentLimits = limits;
                updateLimitInfo(limits, limits.count || 0);
            } catch (le) {}
            showLimitModal(
                (currentLimits && currentLimits.blocked) ? 'blocked' : 'reached',
                currentLimits || { blocked: true, count: 0, limit: 0 },
                (currentLimits && currentLimits.count) || 0
            );
        } else {
            errEl.textContent = e.message;
            errEl.style.display = 'block';
        }
        btn.disabled = false;
        setBtn(btn, 'fa-solid fa-rocket', 'Criar Projeto');
    }
});

// ── Delete Project ────────────────────────────────────────────────────────
function confirmDelete(id, name) {
    $('del-project-name').textContent = name;
    $('modal-del-confirm').dataset.id = id;
    showModal('modal-delete');
}

$('modal-del-cancel').addEventListener('click', function () { hideModal('modal-delete'); });
$('modal-del-close').addEventListener('click', function () { hideModal('modal-delete'); });

$('modal-del-confirm').addEventListener('click', async function () {
    var id = this.dataset.id;
    this.disabled = true;
    setBtn(this, 'fa-solid fa-spinner fa-spin', 'Excluindo tudo...');
    try {
        var data = await api('DELETE', '/api/ide/projects/' + id);
        hideModal('modal-delete');
        var msg = 'Projeto excluído completamente.';
        if (data.tables_dropped && data.tables_dropped.length) {
            msg += ' ' + data.tables_dropped.length + ' tabela(s) removida(s).';
        }
        toast(msg);
        // Recarrega com cache-bust para ignorar o cache de 10s do agregador
        loadProjects(true);
    } catch (e) {
        toast('Erro: ' + e.message);
        this.disabled = false;
        setBtn(this, 'fa-solid fa-trash', 'Excluir tudo permanentemente');
    }
});

// ── Limit modal ───────────────────────────────────────────────────────────
$('modal-limit-ok').addEventListener('click', function () { hideModal('modal-limit'); });
$('modal-limit-close').addEventListener('click', function () { hideModal('modal-limit'); });

// ── Modal overlay close ───────────────────────────────────────────────────
document.querySelectorAll('.idep-modal-overlay').forEach(function (o) {
    o.addEventListener('click', function (e) {
        if (e.target === this) {
            this.setAttribute('aria-hidden', 'true');
            this.classList.remove('show');
        }
    });
});

// ── Database Connection ───────────────────────────────────────────────────
async function loadDatabaseConnection() {
    var card = $('idep-db-card');
    if (!card) return;
    
    // Sempre mostra o card
    card.style.display = 'block';
    
    try {
        var data = await api('GET', '/api/ide/database-connections');
        var connections = data.connections || [];
        var activeConnection = connections.find(function (c) { return c.is_active; });
        
        // Atualiza flag global
        hasActiveDatabaseConnection = !!activeConnection;
        
        var icon = $('idep-db-icon');
        var name = $('idep-db-name');
        var details = $('idep-db-details');
        var status = $('idep-db-status');
        var actions = $('idep-db-actions');
        var configureBtn = $('btn-db-configure');
        var infoSection = $('idep-db-info-section');
        
        if (activeConnection) {
            // Tem conexão ativa
            var driver = activeConnection.driver || 'pgsql';
            icon.className = 'idep-db-card-icon db-' + driver;
            icon.textContent = '';
            icon.appendChild(document.createElement('i')).className = 'fa-solid fa-database';
            
            name.textContent = activeConnection.connection_name;
            
            // Dados sensíveis mascarados por padrão
            var detailsText = driver.toUpperCase() + ' • ';
            detailsText += '••••••••:' + activeConnection.port + ' • ';
            detailsText += '••••••••';
            details.textContent = detailsText;
            details.setAttribute('data-masked', 'true');
            details.setAttribute('data-host', activeConnection.host);
            details.setAttribute('data-port', activeConnection.port);
            details.setAttribute('data-database', activeConnection.database_name);
            details.setAttribute('data-driver', driver.toUpperCase());
            
            status.textContent = '';
            var statusBadge = document.createElement('span');
            statusBadge.className = 'idep-db-status-badge idep-db-status-active';
            var statusIcon = document.createElement('i');
            statusIcon.className = 'fa-solid fa-circle';
            statusBadge.appendChild(statusIcon);
            statusBadge.appendChild(document.createTextNode(' Ativa'));
            status.appendChild(statusBadge);
            
            // Adiciona botão de toggle para mostrar/ocultar dados
            var toggleBtn = document.createElement('button');
            toggleBtn.className = 'idep-db-toggle-visibility';
            toggleBtn.setAttribute('id', 'btn-toggle-db-visibility');
            toggleBtn.setAttribute('title', 'Mostrar/ocultar dados da conexão');
            toggleBtn.setAttribute('aria-label', 'Mostrar/ocultar dados da conexão');
            var eyeIcon = document.createElement('i');
            eyeIcon.className = 'fa-solid fa-eye';
            eyeIcon.setAttribute('id', 'icon-toggle-db-visibility');
            toggleBtn.appendChild(eyeIcon);
            status.appendChild(toggleBtn);
            
            actions.style.display = 'flex';
            configureBtn.style.display = 'none'; // Esconde botão quando tem conexão ativa
            infoSection.style.display = 'block';
            
            // Store connection ID for edit/delete
            card.setAttribute('data-connection-id', activeConnection.id);
            
            // Carrega status (migrations e tabelas)
            await loadDatabaseStatus();
        } else {
            // Sem conexão - mostra botão para configurar
            icon.className = 'idep-db-card-icon db-default';
            icon.textContent = '';
            icon.appendChild(document.createElement('i')).className = 'fa-solid fa-database';
            
            name.textContent = 'Conexão de Banco de Dados';
            details.textContent = 'Configure uma conexão personalizada para seus módulos';
            
            status.textContent = '';
            var statusBadge = document.createElement('span');
            statusBadge.className = 'idep-db-status-badge idep-db-status-inactive';
            var statusIcon = document.createElement('i');
            statusIcon.className = 'fa-solid fa-circle';
            statusBadge.appendChild(statusIcon);
            statusBadge.appendChild(document.createTextNode(' Inativa'));
            status.appendChild(statusBadge);
            
            actions.style.display = 'none'; // Esconde botões de editar/excluir
            configureBtn.style.display = 'block'; // Mostra botão de configurar
            infoSection.style.display = 'none'; // Esconde seção de info (migrations/tabelas)
        }
    } catch (e) {
        console.error('Erro ao carregar conexão:', e);
        
        // Se houver erro (401, etc), mostra o card com opção de configurar
        var icon = $('idep-db-icon');
        var name = $('idep-db-name');
        var details = $('idep-db-details');
        var status = $('idep-db-status');
        var actions = $('idep-db-actions');
        var configureBtn = $('btn-db-configure');
        var infoSection = $('idep-db-info-section');
        
        if (icon) {
            icon.className = 'idep-db-card-icon db-default';
            icon.textContent = '';
            icon.appendChild(document.createElement('i')).className = 'fa-solid fa-database';
        }
        
        if (name) name.textContent = 'Conexão de Banco de Dados';
        if (details) details.textContent = 'Configure uma conexão personalizada para seus módulos';
        if (status) {
            status.textContent = '';
            var statusBadge = document.createElement('span');
            statusBadge.className = 'idep-db-status-badge idep-db-status-inactive';
            var statusIcon = document.createElement('i');
            statusIcon.className = 'fa-solid fa-circle';
            statusBadge.appendChild(statusIcon);
            statusBadge.appendChild(document.createTextNode(' Inativa'));
            status.appendChild(statusBadge);
        }
        if (actions) actions.style.display = 'none';
        if (configureBtn) configureBtn.style.display = 'block';
        if (infoSection) infoSection.style.display = 'none';
    }
}

async function loadDatabaseStatus() {
    try {
        var data = await api('GET', '/api/ide/database-status');
        
        if (!data.has_connection) return;
        
        var migrationsEl = $('idep-db-migrations');
        var tablesEl = $('idep-db-tables');
        var tablesSection = $('idep-db-tables-section');
        var tablesList = $('idep-db-tables-list');
        
        // Migrations pendentes
        var pendingCount = data.pending_migrations ? data.pending_migrations.length : 0;
        if (migrationsEl) {
            migrationsEl.textContent = pendingCount;
            if (pendingCount > 0) {
                migrationsEl.classList.add('has-pending');
            } else {
                migrationsEl.classList.remove('has-pending');
            }
        }
        
        // Tabelas
        var tablesCount = data.tables ? data.tables.length : 0;
        if (tablesEl) {
            tablesEl.textContent = tablesCount;
        }
        
        // Mostra seção de tabelas se houver tabelas
        if (tablesSection && tablesCount > 0) {
            tablesSection.style.display = 'block';
            
            // Renderiza lista de tabelas
            if (tablesList) {
                tablesList.textContent = '';
                data.tables.forEach(function (table) {
                    var item = document.createElement('div');
                    item.className = 'idep-db-table-item';
                    
                    var nameDiv = document.createElement('div');
                    nameDiv.className = 'idep-db-table-name';
                    var tableIcon = document.createElement('i');
                    tableIcon.className = 'fa-solid fa-table';
                    nameDiv.appendChild(tableIcon);
                    nameDiv.appendChild(document.createTextNode(' ' + table.name));
                    
                    var sizeDiv = document.createElement('div');
                    sizeDiv.className = 'idep-db-table-size';
                    sizeDiv.textContent = table.size;
                    
                    item.appendChild(nameDiv);
                    item.appendChild(sizeDiv);
                    tablesList.appendChild(item);
                });
            }
        } else if (tablesSection) {
            tablesSection.style.display = 'none';
        }
    } catch (e) {
        console.error('Erro ao carregar status:', e);
    }
}

function toggleTablesList() {
    var btn = $('btn-toggle-tables');
    var list = $('idep-db-tables-list');
    
    if (!btn || !list) return;
    
    var isExpanded = list.style.display === 'block';
    
    if (isExpanded) {
        list.style.display = 'none';
        btn.classList.remove('expanded');
        btn.textContent = '';
        btn.appendChild(document.createElement('i')).className = 'fa-solid fa-chevron-down';
        btn.appendChild(document.createTextNode(' Ver Tabelas'));
    } else {
        list.style.display = 'block';
        btn.classList.add('expanded');
        btn.textContent = '';
        btn.appendChild(document.createElement('i')).className = 'fa-solid fa-chevron-up';
        btn.appendChild(document.createTextNode(' Ocultar Tabelas'));
    }
}

async function deleteDatabaseConnection() {
    var card = $('idep-db-card');
    if (!card) return;
    
    var connectionId = card.getAttribute('data-connection-id');
    if (!connectionId) return;
    
    if (!confirm('Tem certeza que deseja excluir esta conexão?\n\nAo excluir, seus módulos voltarão a usar o banco de dados padrão da Vupi.us API.')) {
        return;
    }
    
    try {
        await api('DELETE', '/api/ide/database-connections/' + connectionId);
        toast('✓ Conexão excluída com sucesso!');
        await loadDatabaseConnection();
    } catch (e) {
        toast('Erro ao excluir conexão: ' + e.message);
    }
}

function toggleDatabaseVisibility() {
    var details = $('idep-db-details');
    var icon = $('icon-toggle-db-visibility');
    
    if (!details || !icon) return;
    
    var isMasked = details.getAttribute('data-masked') === 'true';
    
    if (isMasked) {
        // Mostra dados reais
        var driver = details.getAttribute('data-driver');
        var host = details.getAttribute('data-host');
        var port = details.getAttribute('data-port');
        var database = details.getAttribute('data-database');
        
        details.textContent = driver + ' • ' + host + ':' + port + ' • ' + database;
        details.setAttribute('data-masked', 'false');
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        // Oculta dados
        var driver = details.getAttribute('data-driver');
        var port = details.getAttribute('data-port');
        
        details.textContent = driver + ' • ••••••••:' + port + ' • ••••••••';
        details.setAttribute('data-masked', 'true');
        icon.className = 'fa-solid fa-eye';
    }
}

async function openDatabaseConfig() {
    // Abre o modal de banco de dados diretamente na página de projetos
    // A conexão é do desenvolvedor, não precisa de projeto específico
    showModal('modal-database-config-projects');
    await loadDatabaseConnectionModal();
}

// ── Init ──────────────────────────────────────────────────────────────────
loadProjects();
loadDatabaseConnection();

// Event listeners para o card de banco de dados
var btnDbConfigure = $('btn-db-configure');
if (btnDbConfigure) btnDbConfigure.addEventListener('click', openDatabaseConfig);

var btnDbEdit = $('btn-db-edit');
if (btnDbEdit) btnDbEdit.addEventListener('click', openDatabaseConfig);

var btnDbDelete = $('btn-db-delete');
if (btnDbDelete) btnDbDelete.addEventListener('click', deleteDatabaseConnection);

var btnToggleTables = $('btn-toggle-tables');
if (btnToggleTables) btnToggleTables.addEventListener('click', toggleTablesList);

// Event listener para toggle de visibilidade (delegação de eventos pois o botão é criado dinamicamente)
document.addEventListener('click', function(e) {
    if (e.target && (e.target.id === 'btn-toggle-db-visibility' || e.target.id === 'icon-toggle-db-visibility')) {
        toggleDatabaseVisibility();
    }
});


// ── Database Configuration Modal ──────────────────────────────────────────
async function loadDatabaseConnectionModal() {
    // Carrega conexão existente se houver
    try {
        var data = await api('GET', '/api/ide/database-connections');
        var connections = data.connections || [];
        var activeConnection = connections.find(function (c) { return c.is_active; });
        
        if (activeConnection) {
            // Preenche formulário com dados da conexão existente
            $('dbp-connection-name').value = activeConnection.connection_name;
            $('dbp-driver').value = activeConnection.driver;
            $('dbp-database-name').value = activeConnection.database_name;
            $('dbp-host').value = activeConnection.host;
            $('dbp-port').value = activeConnection.port;
            $('dbp-username').value = activeConnection.username;
            $('dbp-ssl-mode').value = activeConnection.ssl_mode || '';
            $('dbp-ca-certificate').value = activeConnection.ca_certificate || '';
            
            // Mostra seção CA se SSL mode requer
            updateCaSectionVisibility(activeConnection.ssl_mode || '');
            
            // Se tem CA certificate, mostra o textarea
            if (activeConnection.ca_certificate) {
                $('dbp-ca-textarea-wrapper').style.display = 'block';
                var btn = $('btn-toggle-ca');
                btn.textContent = '';
                btn.appendChild(document.createElement('i')).className = 'fa-solid fa-certificate';
                btn.appendChild(document.createTextNode(' Ocultar Certificado CA'));
            }
            
            // Senha não é retornada por segurança
            $('dbp-password').value = '';
            $('dbp-password').placeholder = '(mantém a senha atual se deixar vazio)';
        } else {
            // Limpa formulário
            $('dbp-connection-name').value = '';
            $('dbp-driver').value = 'pgsql';
            $('dbp-database-name').value = '';
            $('dbp-host').value = '';
            $('dbp-port').value = '';
            $('dbp-username').value = '';
            $('dbp-password').value = '';
            $('dbp-password').placeholder = '••••••••';
            $('dbp-ssl-mode').value = '';
            $('dbp-ca-certificate').value = '';
            $('dbp-ca-section').style.display = 'none';
            $('dbp-ca-textarea-wrapper').style.display = 'none';
            var btn = $('btn-toggle-ca');
            btn.textContent = '';
            btn.appendChild(document.createElement('i')).className = 'fa-solid fa-certificate';
            btn.appendChild(document.createTextNode(' Adicionar Certificado CA'));
        }
    } catch (e) {
        console.error('Erro ao carregar conexão:', e);
    }
}

function updateCaSectionVisibility(sslMode) {
    var caSection = $('dbp-ca-section');
    
    // Mostra seção CA apenas para verify-ca e verify-full
    if (sslMode === 'verify-ca' || sslMode === 'verify-full') {
        caSection.style.display = 'block';
    } else {
        caSection.style.display = 'none';
        $('dbp-ca-textarea-wrapper').style.display = 'none';
        var btn = $('btn-toggle-ca');
        btn.textContent = '';
        btn.appendChild(document.createElement('i')).className = 'fa-solid fa-certificate';
        btn.appendChild(document.createTextNode(' Adicionar Certificado CA'));
    }
}

function toggleCaTextarea() {
    var wrapper = $('dbp-ca-textarea-wrapper');
    var btn = $('btn-toggle-ca');
    
    if (wrapper.style.display === 'none') {
        wrapper.style.display = 'block';
        btn.textContent = '';
        btn.appendChild(document.createElement('i')).className = 'fa-solid fa-certificate';
        btn.appendChild(document.createTextNode(' Ocultar Certificado CA'));
        // Foca no textarea
        setTimeout(function() {
            $('dbp-ca-certificate').focus();
        }, 100);
    } else {
        wrapper.style.display = 'none';
        btn.textContent = '';
        btn.appendChild(document.createElement('i')).className = 'fa-solid fa-certificate';
        btn.appendChild(document.createTextNode(' Adicionar Certificado CA'));
    }
}

async function testDatabaseConnection() {
    var btn = $('modal-dbp-test');
    var errorEl = $('modal-dbp-error');
    var successEl = $('modal-dbp-success');
    
    errorEl.style.display = 'none';
    successEl.style.display = 'none';
    
    var connectionName = $('dbp-connection-name').value.trim();
    var driver = $('dbp-driver').value;
    var databaseName = $('dbp-database-name').value.trim();
    var host = $('dbp-host').value.trim();
    var port = $('dbp-port').value.trim();
    var username = $('dbp-username').value.trim();
    var password = $('dbp-password').value;
    var sslMode = $('dbp-ssl-mode').value;
    var caCertificate = $('dbp-ca-certificate').value.trim();
    
    if (!connectionName || !databaseName || !host || !port || !username) {
        errorEl.textContent = 'Preencha todos os campos obrigatórios';
        errorEl.style.display = 'block';
        return;
    }
    
    if (!password) {
        errorEl.textContent = 'A senha é obrigatória';
        errorEl.style.display = 'block';
        return;
    }
    
    btn.disabled = true;
    setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Testando...');
    
    try {
        var payload = {
            connection_name: connectionName,
            driver: driver,
            database_name: databaseName,
            host: host,
            port: parseInt(port),
            username: username,
            password: password
        };
        
        if (sslMode) {
            payload.ssl_mode = sslMode;
        }
        
        if (caCertificate) {
            payload.ca_certificate = caCertificate;
        }
        
        var result = await api('POST', '/api/ide/database-connections/test', payload);
        
        if (result.success) {
            successEl.textContent = '✓ Conexão testada com sucesso!';
            if (result.database_created) {
                successEl.textContent += ' Banco de dados criado automaticamente.';
            }
            successEl.style.display = 'block';
        } else {
            // Exibe mensagem de erro do backend
            var errorMsg = result.message || result.error || 'Falha ao testar conexão';
            
            // Se houver erros de validação, exibe todos
            if (result.errors && Array.isArray(result.errors)) {
                errorMsg = result.errors.join(', ');
            }
            
            errorEl.textContent = errorMsg;
            errorEl.style.display = 'block';
        }
    } catch (e) {
        var errorMsg = 'Erro ao testar conexão: ' + e.message;
        
        // Se a resposta tiver detalhes, exibe (e.data é populado pela função api())
        if (e.data) {
            if (e.data.errors && Array.isArray(e.data.errors)) {
                errorMsg = 'Erro de validação: ' + e.data.errors.join(', ');
            } else if (e.data.message) {
                errorMsg = e.data.message;
            } else if (e.data.error) {
                errorMsg = e.data.error;
            }
        }
        
        errorEl.textContent = errorMsg;
        errorEl.style.display = 'block';
    } finally {
        btn.disabled = false;
        setBtn(btn, 'fa-solid fa-vial', 'Testar Conexão');
    }
}

async function saveDatabaseConnection() {
    var btn = $('modal-dbp-confirm');
    var errorEl = $('modal-dbp-error');
    var successEl = $('modal-dbp-success');
    
    errorEl.style.display = 'none';
    successEl.style.display = 'none';
    
    var connectionName = $('dbp-connection-name').value.trim();
    var driver = $('dbp-driver').value;
    var databaseName = $('dbp-database-name').value.trim();
    var host = $('dbp-host').value.trim();
    var port = $('dbp-port').value.trim();
    var username = $('dbp-username').value.trim();
    var password = $('dbp-password').value;
    var sslMode = $('dbp-ssl-mode').value;
    var caCertificate = $('dbp-ca-certificate').value.trim();
    
    if (!connectionName || !databaseName || !host || !port || !username) {
        errorEl.textContent = 'Preencha todos os campos obrigatórios';
        errorEl.style.display = 'block';
        return;
    }
    
    btn.disabled = true;
    setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Conectando...');
    
    try {
        // Verifica se já existe conexão
        var listData = await api('GET', '/api/ide/database-connections');
        var connections = listData.connections || [];
        var existingConnection = connections.find(function (c) { return c.is_active; });
        
        if (existingConnection) {
            // Atualiza conexão existente
            var updateData = {
                connection_name: connectionName,
                driver: driver,
                database_name: databaseName,
                host: host,
                port: parseInt(port),
                username: username
            };
            
            // Só envia senha se foi preenchida
            if (password) {
                updateData.password = password;
            }
            
            // Adiciona SSL se configurado
            if (sslMode) {
                updateData.ssl_mode = sslMode;
            }
            
            if (caCertificate) {
                updateData.ca_certificate = caCertificate;
            }
            
            await api('PUT', '/api/ide/database-connections/' + existingConnection.id, updateData);
            
            // Ativa a conexão
            await api('POST', '/api/ide/database-connections/' + existingConnection.id + '/activate');
        } else {
            // Cria nova conexão
            if (!password) {
                errorEl.textContent = 'Senha é obrigatória para nova conexão';
                errorEl.style.display = 'block';
                btn.disabled = false;
                setBtn(btn, 'fa-solid fa-plug', 'Conectar');
                return;
            }
            
            var createData = {
                connection_name: connectionName,
                driver: driver,
                database_name: databaseName,
                host: host,
                port: parseInt(port),
                username: username,
                password: password
            };
            
            // Adiciona SSL se configurado (não envia se vazio)
            if (sslMode && sslMode !== '') {
                createData.ssl_mode = sslMode;
            }
            
            if (caCertificate && caCertificate !== '') {
                createData.ca_certificate = caCertificate;
            }
            
            var result = await api('POST', '/api/ide/database-connections', createData);
            
            console.log('Conexão criada:', result);
            
            // Ativa automaticamente a nova conexão
            if (result.connection && result.connection.id) {
                console.log('Ativando conexão ID:', result.connection.id);
                try {
                    var activateResult = await api('POST', '/api/ide/database-connections/' + result.connection.id + '/activate');
                    console.log('Resultado da ativação:', activateResult);
                } catch (activateError) {
                    console.error('Erro ao ativar conexão:', activateError);
                }
            } else if (result.success) {
                console.warn('Conexão criada mas ID não retornado, recarregando lista...');
            }
        }
        
        toast('✓ Conexão configurada com sucesso!');
        hideModal('modal-database-config-projects');
        
        // Recarrega o card de banco de dados
        await loadDatabaseConnection();
    } catch (e) {
        var errorMsg = 'Erro ao salvar conexão: ' + e.message;
        
        // Se a resposta tiver detalhes, exibe (e.data é populado pela função api())
        if (e.data) {
            if (e.data.errors && Array.isArray(e.data.errors)) {
                errorMsg = 'Erro de validação: ' + e.data.errors.join(', ');
            } else if (e.data.message) {
                errorMsg = e.data.message;
            } else if (e.data.error) {
                errorMsg = e.data.error;
            }
        }
        
        errorEl.textContent = errorMsg;
        errorEl.style.display = 'block';
    } finally {
        btn.disabled = false;
        setBtn(btn, 'fa-solid fa-plug', 'Conectar');
    }
}

// Event listeners para o modal de banco de dados
var btnDbpClose = $('modal-dbp-close');
if (btnDbpClose) btnDbpClose.addEventListener('click', function () { hideModal('modal-database-config-projects'); });

var btnDbpCancel = $('modal-dbp-cancel');
if (btnDbpCancel) btnDbpCancel.addEventListener('click', function () { hideModal('modal-database-config-projects'); });

var btnDbpTest = $('modal-dbp-test');
if (btnDbpTest) btnDbpTest.addEventListener('click', testDatabaseConnection);

var btnDbpConfirm = $('modal-dbp-confirm');
if (btnDbpConfirm) btnDbpConfirm.addEventListener('click', saveDatabaseConnection);

// Event listener para mudança de driver - removido auto-preenchimento de porta
// O usuário deve digitar a porta manualmente

// Event listener para mudança de SSL Mode (mostra/oculta CA)
var dbpSslMode = $('dbp-ssl-mode');
if (dbpSslMode) {
    dbpSslMode.addEventListener('change', function () {
        updateCaSectionVisibility(this.value);
    });
}

// Event listener para toggle do CA Certificate
var btnToggleCa = $('btn-toggle-ca');
if (btnToggleCa) {
    btnToggleCa.addEventListener('click', toggleCaTextarea);
}

// Password eye toggle para o modal de banco de dados
document.querySelectorAll('.idep-pwd-eye').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var targetId = btn.getAttribute('data-target');
        var allowed = ['dbp-password'];
        if (!targetId || !allowed.includes(targetId)) return;
        var inp = document.getElementById(targetId);
        if (!inp) return;
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        var ic = btn.querySelector('i');
        if (ic) ic.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    });
});
