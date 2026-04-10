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

    // Token expirado — redireciona para login
    if (res.status === 401) {
        window.location.replace('/ide/login');
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
function hideModal(id) { var e = $(id); if (e) { e.setAttribute('aria-hidden', 'true'); e.classList.remove('show'); } }

// ── Load Projects ─────────────────────────────────────────────────────────
async function loadProjects() {
    $('idep-loading').style.display = 'flex';
    $('idep-empty').style.display = 'none';
    $('idep-grid').style.display = 'none';

    try {
        var data = await api('GET', '/api/ide/projects');
        var projects = data.projects || [];

        // Carrega limites do usuário
        try {
            var limits = await api('GET', '/api/ide/my-limits');
            updateLimitInfo(limits, projects.length);
        } catch (e) {
            // Se falhou ao carregar limites, assume ilimitado para não bloquear
            updateLimitInfo({ unlimited: true, blocked: false, count: projects.length, limit: -1, remaining: null }, projects.length);
        }

        renderGrid(projects);
    } catch (e) {
        var container = $('idep-loading');
        container.textContent = '';
        container.appendChild(el('i', { className: 'fa-solid fa-circle-exclamation fa-2x', style: { color: '#f38ba8' } }));
        container.appendChild(el('p', { style: { color: '#e11d48' } }, [e.message]));
    }
}

var currentLimits = null;

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

    $('inp-project-name').value = '';
    $('inp-module-name').value = '';
    $('inp-project-desc').value = '';
    $('idep-module-preview').style.display = 'none';
    $('inp-scaffold').checked = true;
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
        loadProjects();
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

// ── Init ──────────────────────────────────────────────────────────────────
loadProjects();
