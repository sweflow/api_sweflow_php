(function () {
'use strict';

// ── State ──────────────────────────────────────────────────────────────────
var allUsers      = [];
var totalUsers    = 0;
var currentPage   = 1;
var totalPages    = 1;
var perPage       = 20;
var searchQuery   = '';
var nivelFilter   = '';
var selectedUuids = new Set();
var meUuid        = null;
var pendingDeleteUuid = null;
var pendingNivelUuid  = null;
var searchTimer   = null;

// ── DOM refs ───────────────────────────────────────────────────────────────
var tbody         = document.getElementById('usuarios-tbody');
var pagination    = document.getElementById('pagination');
var pageInfo      = document.getElementById('page-info');
var searchInput   = document.getElementById('search-input');
var filterNivel   = document.getElementById('filter-nivel');
var selectAllCb   = document.getElementById('select-all-cb');
var selectAllTh   = document.getElementById('select-all-th');
var bulkBar       = document.getElementById('bulk-bar');
var bulkCount     = document.getElementById('bulk-count');
var bulkDeleteBtn = document.getElementById('bulk-delete-btn');
var bulkCancelBtn = document.getElementById('bulk-cancel-btn');
var logoutBtn     = document.getElementById('logout-btn');
var headerStats   = document.getElementById('u-header-stats');

// ── Helpers ────────────────────────────────────────────────────────────────
function apiHeaders() {
    var token = document.cookie.split(';').map(function (c) { return c.trim(); })
        .find(function (c) { return c.startsWith('auth_token='); });
    var t = token ? token.split('=')[1] : '';
    return {
        'Content-Type': 'application/json',
        'Authorization': t ? 'Bearer ' + t : ''
    };
}

async function apiFetch(method, url, body) {
    var opts = { method: method, headers: apiHeaders(), credentials: 'same-origin' };
    if (body) opts.body = JSON.stringify(body);
    var res  = await fetch(url, opts);
    var data = await res.json().catch(function () { return {}; });
    return { ok: res.ok, status: res.status, data: data };
}

function toast(msg, type) {
    type = type || 'success';
    var el  = document.getElementById('toast');
    var ico = document.getElementById('toast-icon');
    var txt = document.getElementById('toast-msg');
    ico.className = type === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
    el.style.background = type === 'success' ? '#1a1f3b' : '#c0392b';
    txt.textContent = msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.style.display = 'none'; }, 3800);
}

function openModal(id) {
    var m = document.getElementById(id);
    if (m) { m.classList.add('show'); m.setAttribute('aria-hidden', 'false'); }
}
function closeModal(id) {
    var m = document.getElementById(id);
    if (m) { m.classList.remove('show'); m.setAttribute('aria-hidden', 'true'); }
}

function nivelBadge(nivel) {
    var map = {
        admin_system: { label: 'Admin Sistema', icon: 'fa-crown',         cls: 'u-nivel-admin_system' },
        admin:        { label: 'Admin',          icon: 'fa-star',          cls: 'u-nivel-admin'        },
        moderador:    { label: 'Moderador',      icon: 'fa-shield-halved', cls: 'u-nivel-moderador'    },
        usuario:      { label: 'Usuario',        icon: 'fa-user',          cls: 'u-nivel-usuario'      }
    };
    // Usa whitelist — se nivel nao estiver no mapa, cai no fallback seguro
    var n = map[nivel] || { label: escHtml(String(nivel || 'desconhecido')), icon: 'fa-question', cls: 'u-nivel-usuario' };
    // label do mapa e estatico (sem dados do usuario), cls e icon tambem — sem risco de XSS
    return '<span class="u-nivel ' + n.cls + '"><i class="fa-solid ' + n.icon + '"></i> ' + n.label + '</span>';
}

function statusBadge(ativo) {
    if (ativo) {
        return '<span class="u-status u-status-ativo"><i class="fa-solid fa-circle-check"></i> Ativo</span>';
    }
    return '<span class="u-status u-status-inativo"><i class="fa-solid fa-circle-xmark"></i> Inativo</span>';
}

function avatarCell(u) {
    var ini = initials(u.nome_completo);
    if (u.url_avatar) {
        return '<img class="u-avatar" src="' + escHtml(u.url_avatar) + '" alt="Avatar" loading="lazy" '
             + 'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';" />'
             + '<div class="u-avatar-ph" style="display:none;">' + ini + '</div>';
    }
    return '<div class="u-avatar-ph">' + ini + '</div>';
}

function initials(name) {
    return (name || '?').split(' ').slice(0, 2).map(function (w) { return w[0] || ''; }).join('').toUpperCase();
}

function escHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function fmtDate(d) {
    if (!d) return '—';
    try {
        return new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } catch (e) { return d; }
}

// ── Fetch me ───────────────────────────────────────────────────────────────
async function fetchMe() {
    var r = await apiFetch('GET', '/api/auth/me');
    if (r.ok) {
        meUuid = (r.data.usuario && r.data.usuario.uuid)
            ? r.data.usuario.uuid
            : (r.data.uuid || null);
    }
}

// ── Load users ─────────────────────────────────────────────────────────────
async function loadUsers(page) {
    page = page || 1;
    currentPage = page;
    tbody.innerHTML = '<tr><td colspan="7" class="u-loading-cell">'
        + '<i class="fa-solid fa-circle-notch fa-spin"></i> Carregando...</td></tr>';
    pagination.innerHTML = '';
    pageInfo.textContent = '';

    var params = new URLSearchParams({ pagina: page, por_pagina: perPage });
    if (searchQuery) params.set('q', searchQuery);
    if (nivelFilter) params.set('nivel', nivelFilter);

    var r = await apiFetch('GET', '/api/usuarios?' + params.toString());
    if (!r.ok) {
        tbody.innerHTML = '<tr><td colspan="7" class="u-loading-cell">'
            + '<i class="fa-solid fa-circle-exclamation"></i> Erro ao carregar usuarios.</td></tr>';
        return;
    }

    allUsers   = r.data.usuarios || [];
    totalUsers = r.data.total != null ? r.data.total : allUsers.length;
    totalPages = r.data.total_paginas || Math.ceil(totalUsers / perPage) || 1;

    renderStats();
    renderTable();
    renderPagination();
}

// ── Stats ──────────────────────────────────────────────────────────────────
function renderStats() {
    if (!headerStats) return;
    var ativos   = allUsers.filter(function (u) { return u.ativo !== false; }).length;
    var inativos = allUsers.length - ativos;
    headerStats.innerHTML =
        '<div class="u-stat-pill"><i class="fa-solid fa-users"></i>'
        + '<span><span class="u-stat-val">' + totalUsers + '</span> total</span></div>'
        + '<div class="u-stat-pill"><i class="fa-solid fa-circle-check" style="color:#1e8449;"></i>'
        + '<span><span class="u-stat-val">' + ativos + '</span> ativos</span></div>'
        + (inativos > 0
            ? '<div class="u-stat-pill"><i class="fa-solid fa-circle-xmark" style="color:#c0392b;"></i>'
              + '<span><span class="u-stat-val">' + inativos + '</span> inativos</span></div>'
            : '');
}

// ── Render table ───────────────────────────────────────────────────────────
function renderTable() {
    if (!allUsers.length) {
        tbody.innerHTML = '<tr><td colspan="7"><div class="u-empty">'
            + '<i class="fa-solid fa-users-slash"></i>'
            + '<p>Nenhum usuario encontrado.</p></div></td></tr>';
        return;
    }

    tbody.innerHTML = allUsers.map(function (u) {
        var isMe   = u.uuid === meUuid;
        var ativo  = u.ativo !== false;
        var rowCls = selectedUuids.has(u.uuid) ? 'u-selected' : '';
        var chk    = selectedUuids.has(u.uuid) ? 'checked' : '';
        var dis    = isMe ? 'disabled' : '';
        var uuid   = escHtml(u.uuid);
        var uname  = escHtml(u.username);

        return '<tr class="' + rowCls + '" data-uuid="' + uuid + '">'
            // checkbox
            + '<td><input type="checkbox" class="u-cb row-cb" data-uuid="' + uuid + '" ' + chk + ' /></td>'
            // user cell
            + '<td><div class="u-user-cell" data-uuid="' + uuid + '" title="Ver detalhes">'
            +   avatarCell(u)
            +   '<div><div class="u-username">@' + uname + '</div>'
            +   '<div class="u-fullname">' + escHtml(u.nome_completo) + '</div></div>'
            + '</div></td>'
            // email
            + '<td class="u-hide-sm" style="color:#555;font-size:.95rem;">' + escHtml(u.email) + '</td>'
            // nivel
            + '<td>' + nivelBadge(u.nivel_acesso) + '</td>'
            // status
            + '<td>' + statusBadge(ativo) + '</td>'
            // date
            + '<td class="u-hide-md" style="color:#888;font-size:.88rem;">' + fmtDate(u.criado_em) + '</td>'
            // actions
            + '<td><div class="u-actions">'
            +   '<button class="u-action-btn" title="Ver detalhes" data-action="detail" data-uuid="' + uuid + '">'
            +     '<i class="fa-solid fa-eye"></i></button>'
            +   '<button class="u-action-btn" title="Alterar nivel" data-action="nivel" data-uuid="' + uuid + '">'
            +     '<i class="fa-solid fa-user-shield"></i></button>'
            +   '<button class="u-action-btn ' + (ativo ? 'u-act-danger' : 'u-act-success') + '" '
            +     'title="' + (ativo ? 'Desativar' : 'Ativar') + '" data-action="toggle" '
            +     'data-uuid="' + uuid + '" data-ativo="' + ativo + '" ' + dis + '>'
            +     '<i class="fa-solid ' + (ativo ? 'fa-ban' : 'fa-circle-check') + '"></i></button>'
            +   '<button class="u-action-btn u-act-danger" title="Excluir" data-action="delete" '
            +     'data-uuid="' + uuid + '" data-username="' + uname + '" ' + dis + '>'
            +     '<i class="fa-solid fa-trash"></i></button>'
            + '</div></td>'
            + '</tr>';
    }).join('');

    // Events
    tbody.querySelectorAll('.u-user-cell').forEach(function (el) {
        el.addEventListener('click', function () { openDetail(el.dataset.uuid); });
    });
    tbody.querySelectorAll('.row-cb').forEach(function (cb) {
        cb.addEventListener('change', function () { toggleSelect(cb.dataset.uuid, cb.checked); });
    });
    tbody.querySelectorAll('[data-action]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var a = btn.dataset.action;
            var id = btn.dataset.uuid;
            if (a === 'detail') openDetail(id);
            if (a === 'nivel')  openNivelModal(id);
            if (a === 'toggle') toggleAtivo(id, btn.dataset.ativo === 'true');
            if (a === 'delete') openDeleteModal(id, btn.dataset.username);
        });
    });

    updateSelectAllState();
}

// ── Pagination ─────────────────────────────────────────────────────────────
function renderPagination() {
    pageInfo.textContent = totalUsers + ' usuario' + (totalUsers !== 1 ? 's' : '');
    if (totalPages <= 1) { pagination.innerHTML = ''; return; }

    var html = '<button class="u-page-btn" id="pg-prev" '
        + (currentPage <= 1 ? 'disabled' : '') + '><i class="fa-solid fa-chevron-left"></i></button>';

    var range = [];
    for (var i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            range.push(i);
        } else if (range[range.length - 1] !== '...') {
            range.push('...');
        }
    }
    range.forEach(function (p) {
        if (p === '...') {
            html += '<span class="u-page-btn" style="cursor:default;border:none;color:#aaa;">...</span>';
        } else {
            html += '<button class="u-page-btn ' + (p === currentPage ? 'u-page-active' : '')
                + '" data-page="' + p + '">' + p + '</button>';
        }
    });

    html += '<button class="u-page-btn" id="pg-next" '
        + (currentPage >= totalPages ? 'disabled' : '') + '><i class="fa-solid fa-chevron-right"></i></button>';
    pagination.innerHTML = html;

    var prev = pagination.querySelector('#pg-prev');
    var next = pagination.querySelector('#pg-next');
    if (prev) prev.addEventListener('click', function () { loadUsers(currentPage - 1); });
    if (next) next.addEventListener('click', function () { loadUsers(currentPage + 1); });
    pagination.querySelectorAll('[data-page]').forEach(function (btn) {
        btn.addEventListener('click', function () { loadUsers(Number(btn.dataset.page)); });
    });
}

// ── Selection ──────────────────────────────────────────────────────────────
function toggleSelect(uuid, checked) {
    if (checked) selectedUuids.add(uuid);
    else selectedUuids.delete(uuid);
    updateBulkBar();
    updateSelectAllState();
    var row = tbody.querySelector('tr[data-uuid="' + uuid + '"]');
    if (row) row.classList.toggle('u-selected', checked);
}

function updateBulkBar() {
    var n = selectedUuids.size;
    bulkBar.classList.toggle('visible', n > 0);
    bulkCount.textContent = n + ' selecionado' + (n !== 1 ? 's' : '');
}

function updateSelectAllState() {
    var cbs = Array.from(tbody.querySelectorAll('.row-cb'));
    var all = cbs.length > 0 && cbs.every(function (cb) { return selectedUuids.has(cb.dataset.uuid); });
    if (selectAllCb) selectAllCb.checked = all;
    if (selectAllTh) selectAllTh.checked = all;
}

function selectAll(checked) {
    allUsers.forEach(function (u) {
        if (checked) selectedUuids.add(u.uuid);
        else selectedUuids.delete(u.uuid);
    });
    tbody.querySelectorAll('.row-cb').forEach(function (cb) { cb.checked = checked; });
    tbody.querySelectorAll('tr[data-uuid]').forEach(function (row) {
        row.classList.toggle('u-selected', checked);
    });
    updateBulkBar();
    updateSelectAllState();
}

if (selectAllCb) selectAllCb.addEventListener('change', function (e) { selectAll(e.target.checked); });
if (selectAllTh) selectAllTh.addEventListener('change', function (e) { selectAll(e.target.checked); });
if (bulkCancelBtn) bulkCancelBtn.addEventListener('click', function () { selectAll(false); });

// ── Detail modal ───────────────────────────────────────────────────────────
async function openDetail(uuid) {
    openModal('detail-modal');
    document.getElementById('detail-nome').textContent = 'Carregando...';
    document.getElementById('detail-grid').innerHTML   = '';

    var r = await apiFetch('GET', '/api/usuario/' + uuid);
    if (!r.ok) {
        toast('Erro ao carregar usuario.', 'error');
        closeModal('detail-modal');
        return;
    }

    var u = r.data.usuario || r.data;

    // Avatar
    var wrap = document.getElementById('detail-avatar-wrap');
    if (u.url_avatar) {
        wrap.outerHTML = '<img class="u-detail-avatar" id="detail-avatar-wrap" src="'
            + escHtml(u.url_avatar) + '" alt="Avatar" '
            + 'onerror="this.className=\'u-detail-avatar-ph\';this.outerHTML=\'<div class=u-detail-avatar-ph id=detail-avatar-wrap>'
            + escHtml(initials(u.nome_completo)) + '</div>\';" />';
    } else {
        var w2 = document.getElementById('detail-avatar-wrap');
        w2.className   = 'u-detail-avatar-ph';
        w2.textContent = initials(u.nome_completo);
    }

    document.getElementById('detail-nome').textContent           = u.nome_completo || '—';
    document.getElementById('detail-username-label').textContent = '@' + (u.username || '—');
    document.getElementById('detail-nivel-badge').innerHTML      = nivelBadge(u.nivel_acesso);

    var capaWrap = document.getElementById('detail-capa-wrap');
    if (u.url_capa) {
        capaWrap.style.display = '';
        document.getElementById('detail-capa').src = u.url_capa;
    } else {
        capaWrap.style.display = 'none';
    }

    var fields = [
        ['UUID',              u.uuid],
        ['E-mail',            u.email],
        ['Status',            u.ativo !== false ? 'Ativo' : 'Inativo'],
        ['E-mail verificado', u.verificado_email ? 'Sim' : 'Nao'],
        ['Cadastro',          fmtDate(u.criado_em)],
        ['Atualizado',        fmtDate(u.atualizado_em)]
    ];
    document.getElementById('detail-grid').innerHTML = fields.map(function (f) {
        return '<div class="u-detail-field"><label>' + escHtml(f[0]) + '</label>'
            + '<span>' + escHtml(String(f[1] == null ? '—' : f[1])) + '</span></div>';
    }).join('');

    var bioWrap = document.getElementById('detail-bio-wrap');
    if (u.biografia) {
        bioWrap.hidden = false;
        document.getElementById('detail-bio').textContent = u.biografia;
    } else {
        bioWrap.hidden = true;
    }
}

var detailClose = document.getElementById('detail-close');
if (detailClose) detailClose.addEventListener('click', function () { closeModal('detail-modal'); });

// ── Nivel modal ────────────────────────────────────────────────────────────
function openNivelModal(uuid) {
    var u = allUsers.find(function (x) { return x.uuid === uuid; });
    if (!u) return;
    pendingNivelUuid = uuid;
    document.getElementById('nivel-username').textContent = '@' + u.username;
    document.getElementById('nivel-select-input').value   = u.nivel_acesso || 'usuario';
    document.getElementById('nivel-feedback').textContent = '';
    document.getElementById('nivel-feedback').className   = 'login-feedback';
    openModal('nivel-modal');
}

var nivelClose   = document.getElementById('nivel-close');
var nivelCancel  = document.getElementById('nivel-cancel');
var nivelConfirm = document.getElementById('nivel-confirm');
if (nivelClose)   nivelClose.addEventListener('click',  function () { closeModal('nivel-modal'); });
if (nivelCancel)  nivelCancel.addEventListener('click', function () { closeModal('nivel-modal'); });
if (nivelConfirm) nivelConfirm.addEventListener('click', async function () {
    if (!pendingNivelUuid) return;
    var nivel = document.getElementById('nivel-select-input').value;
    var fb    = document.getElementById('nivel-feedback');
    fb.textContent = 'Salvando...';
    fb.className   = 'login-feedback';
    var r = await apiFetch('PUT', '/api/usuario/atualizar/' + pendingNivelUuid, { nivel_acesso: nivel });
    if (r.ok) {
        toast('Nivel de acesso atualizado.');
        closeModal('nivel-modal');
        loadUsers(currentPage);
    } else {
        fb.textContent = (r.data && r.data.message) ? r.data.message : 'Erro ao atualizar.';
        fb.className   = 'login-feedback error';
    }
});

// ── Toggle ativo ───────────────────────────────────────────────────────────
async function toggleAtivo(uuid, isAtivo) {
    var endpoint = isAtivo
        ? '/api/usuario/' + uuid + '/desativar'
        : '/api/usuario/' + uuid + '/ativar';
    var r = await apiFetch('PATCH', endpoint);
    if (r.ok) {
        toast(isAtivo ? 'Usuario desativado.' : 'Usuario ativado.');
        loadUsers(currentPage);
    } else {
        toast((r.data && r.data.message) ? r.data.message : 'Erro ao alterar status.', 'error');
    }
}

// ── Delete modal ───────────────────────────────────────────────────────────
function openDeleteModal(uuid, username) {
    pendingDeleteUuid = uuid;
    document.getElementById('delete-username-label').textContent = username;
    document.getElementById('delete-confirm-input').value        = '';
    document.getElementById('delete-confirm-btn').disabled       = true;
    document.getElementById('delete-feedback').textContent       = '';
    document.getElementById('delete-feedback').className         = 'login-feedback';
    openModal('delete-modal');
    setTimeout(function () {
        var inp = document.getElementById('delete-confirm-input');
        if (inp) inp.focus();
    }, 80);
}

var deleteInput = document.getElementById('delete-confirm-input');
if (deleteInput) deleteInput.addEventListener('input', function () {
    var label = document.getElementById('delete-username-label').textContent;
    document.getElementById('delete-confirm-btn').disabled = this.value.trim() !== label;
});

var deleteClose   = document.getElementById('delete-close');
var deleteCancel  = document.getElementById('delete-cancel');
var deleteConfirm = document.getElementById('delete-confirm-btn');
if (deleteClose)   deleteClose.addEventListener('click',  function () { closeModal('delete-modal'); });
if (deleteCancel)  deleteCancel.addEventListener('click', function () { closeModal('delete-modal'); });
if (deleteConfirm) deleteConfirm.addEventListener('click', async function () {
    if (!pendingDeleteUuid) return;
    var fb  = document.getElementById('delete-feedback');
    var btn = document.getElementById('delete-confirm-btn');
    btn.disabled   = true;
    fb.textContent = 'Excluindo...';
    var r = await apiFetch('DELETE', '/api/usuario/deletar/' + pendingDeleteUuid);
    if (r.ok) {
        toast('Usuario excluido com sucesso.');
        closeModal('delete-modal');
        selectedUuids.delete(pendingDeleteUuid);
        pendingDeleteUuid = null;
        loadUsers(currentPage);
    } else {
        fb.textContent = (r.data && r.data.message) ? r.data.message : 'Erro ao excluir.';
        fb.className   = 'login-feedback error';
        btn.disabled   = false;
    }
});

// ── Bulk delete ────────────────────────────────────────────────────────────
if (bulkDeleteBtn) bulkDeleteBtn.addEventListener('click', function () {
    if (!selectedUuids.size) return;
    document.getElementById('bulk-delete-count').textContent    = selectedUuids.size;
    document.getElementById('bulk-confirm-input').value         = '';
    document.getElementById('bulk-delete-confirm-btn').disabled = true;
    document.getElementById('bulk-delete-feedback').textContent = '';
    openModal('bulk-delete-modal');
    setTimeout(function () {
        var inp = document.getElementById('bulk-confirm-input');
        if (inp) inp.focus();
    }, 80);
});

var bulkInput = document.getElementById('bulk-confirm-input');
if (bulkInput) bulkInput.addEventListener('input', function () {
    document.getElementById('bulk-delete-confirm-btn').disabled = this.value.trim() !== 'CONFIRMAR';
});

var bulkDeleteClose   = document.getElementById('bulk-delete-close');
var bulkDeleteCancel  = document.getElementById('bulk-delete-cancel');
var bulkDeleteConfirm = document.getElementById('bulk-delete-confirm-btn');
if (bulkDeleteClose)   bulkDeleteClose.addEventListener('click',  function () { closeModal('bulk-delete-modal'); });
if (bulkDeleteCancel)  bulkDeleteCancel.addEventListener('click', function () { closeModal('bulk-delete-modal'); });
if (bulkDeleteConfirm) bulkDeleteConfirm.addEventListener('click', async function () {
    var uuids = Array.from(selectedUuids);
    var fb    = document.getElementById('bulk-delete-feedback');
    var btn   = document.getElementById('bulk-delete-confirm-btn');
    btn.disabled   = true;
    fb.textContent = 'Excluindo ' + uuids.length + ' usuarios...';

    var ok = 0, fail = 0;
    for (var i = 0; i < uuids.length; i++) {
        var r = await apiFetch('DELETE', '/api/usuario/deletar/' + uuids[i]);
        if (r.ok) { ok++; selectedUuids.delete(uuids[i]); }
        else fail++;
    }

    closeModal('bulk-delete-modal');
    updateBulkBar();
    if (fail === 0) {
        toast(ok + ' usuario' + (ok !== 1 ? 's' : '') + ' excluido' + (ok !== 1 ? 's' : '') + ' com sucesso.');
    } else {
        toast(ok + ' excluido' + (ok !== 1 ? 's' : '') + ', ' + fail + ' falha' + (fail !== 1 ? 's' : '') + '.', 'error');
    }
    loadUsers(currentPage);
});

// ── Search & filter ────────────────────────────────────────────────────────
if (searchInput) searchInput.addEventListener('input', function () {
    clearTimeout(searchTimer);
    var val = this.value.trim();
    searchTimer = setTimeout(function () {
        searchQuery = val;
        loadUsers(1);
    }, 350);
});

if (filterNivel) filterNivel.addEventListener('change', function () {
    nivelFilter = this.value;
    loadUsers(1);
});

// ── Logout ─────────────────────────────────────────────────────────────────
if (logoutBtn) logoutBtn.addEventListener('click', async function (e) {
    e.preventDefault();
    await apiFetch('POST', '/api/auth/logout');
    try { localStorage.removeItem('hasAuthSession'); } catch (_) {}
    window.location.href = '/';
});

// ── Close modals on overlay click ──────────────────────────────────────────
document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) overlay.classList.remove('show');
    });
});

// ── Init ───────────────────────────────────────────────────────────────────
(async function () {
    await fetchMe();
    await loadUsers(1);
})();

})();
