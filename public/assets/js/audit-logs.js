// ── Logs & Monitoramento ──────────────────────────────────────────────────
(function () {
    'use strict';

    // ── Estado ────────────────────────────────────────────────────────────
    let currentPage    = 1;
    let lastPage       = 1;
    let totalLogs      = 0;
    let realtimeTimer  = null;
    let lastLogId      = 0;       // ID do log mais recente — detecta novos registros
    let activeCategory = '';      // filtro de categoria ativo nos stat cards
    let searchTimer    = null;

    // ── Elementos ─────────────────────────────────────────────────────────
    const tbody       = document.getElementById('audit-log-tbody');
    const searchInput = document.getElementById('audit-search');
    const catSelect   = document.getElementById('audit-categoria');
    const periodoSel  = document.getElementById('audit-periodo');
    const realtimeChk = document.getElementById('audit-realtime');
    const prevBtn     = document.getElementById('audit-prev');
    const nextBtn     = document.getElementById('audit-next');
    const pageLabel   = document.getElementById('audit-page-label');
    const totalLabel  = document.getElementById('audit-total-label');
    const refreshBtn  = document.getElementById('audit-refresh-btn');
    const clearBtn    = document.getElementById('audit-clear-btn');

    if (!tbody) return; // seção não está na página

    // ── Helpers ───────────────────────────────────────────────────────────
    function esc(s) {
        return String(s ?? '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmtDate(iso) {
        if (!iso) return '—';
        try {
            const d = new Date(iso);
            return d.toLocaleString('pt-BR', { dateStyle:'short', timeStyle:'medium' });
        } catch { return iso; }
    }

    function badgeClass(evento) {
        if (!evento) return 'audit-badge-default';
        if (evento.startsWith('auth.'))                                    return 'audit-badge-auth';
        if (evento.startsWith('user.'))                                    return 'audit-badge-user';
        if (evento.startsWith('bot.')    || evento.startsWith('rate_limit.')
         || evento.startsWith('brute_') || evento.startsWith('honeypot.')
         || evento.startsWith('http.'))                                    return 'audit-badge-security';
        if (evento.startsWith('module.') || evento.startsWith('admin.'))  return 'audit-badge-admin';
        return 'audit-badge-default';
    }

    function badgeIcon(evento) {
        if (!evento) return 'fa-circle-dot';
        if (evento.startsWith('auth.login.success'))  return 'fa-circle-check';
        if (evento.startsWith('auth.login.failed'))   return 'fa-circle-xmark';
        if (evento.startsWith('auth.logout'))         return 'fa-right-from-bracket';
        if (evento.startsWith('auth.'))               return 'fa-key';
        if (evento.startsWith('user.created'))        return 'fa-user-plus';
        if (evento.startsWith('user.deleted'))        return 'fa-user-minus';
        if (evento.startsWith('user.'))               return 'fa-user';
        if (evento.startsWith('bot.'))                return 'fa-robot';
        if (evento.startsWith('rate_limit.'))         return 'fa-gauge-high';
        if (evento.startsWith('brute_force.'))        return 'fa-skull-crossbones';
        if (evento.startsWith('honeypot.'))           return 'fa-spider';
        if (evento.startsWith('http.'))               return 'fa-triangle-exclamation';
        if (evento.startsWith('module.'))             return 'fa-puzzle-piece';
        if (evento.startsWith('admin.'))              return 'fa-gear';
        return 'fa-circle-dot';
    }

    function buildParams(page) {
        const p = new URLSearchParams();
        p.set('page',  page);
        p.set('limit', '50');
        const q = searchInput?.value.trim();
        if (q)              p.set('q', q);
        const cat = catSelect?.value || activeCategory;
        if (cat)            p.set('categoria', cat);
        const horas = periodoSel?.value;
        if (horas) {
            const desde = new Date(Date.now() - horas * 3600000);
            p.set('desde', desde.toISOString());
        }
        return p.toString();
    }

    // ── Carregar logs ─────────────────────────────────────────────────────
    async function loadLogs(page, silent = false) {
        if (!silent && tbody) {
            tbody.innerHTML = '<tr><td colspan="5" style="padding:32px;text-align:center;color:#64748b;"><i class="fa-solid fa-spinner fa-spin"></i> Carregando...</td></tr>';
        }
        try {
            const res  = await fetch('/api/audit/logs?' + buildParams(page), { credentials: 'same-origin' });
            if (res.status === 401) { window.location.replace('/'); return; }
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            currentPage = data.page   ?? 1;
            lastPage    = data.last_page ?? 1;
            totalLogs   = data.total  ?? 0;
            const logs  = data.logs   ?? [];

            renderTable(logs, silent);
            updatePagination();

            // Atualiza lastLogId para detecção de novos registros
            if (logs.length > 0 && logs[0].id > lastLogId) {
                lastLogId = logs[0].id;
            }
        } catch (err) {
            if (tbody) {
                tbody.textContent = '';
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.setAttribute('colspan', '5');
                td.style.cssText = 'padding:32px;text-align:center;color:#f87171;';
                td.textContent = 'Erro ao carregar logs: ' + (err.message || 'Erro desconhecido');
                tr.appendChild(td);
                tbody.appendChild(tr);
            }
        }
    }

    // ── Polling em tempo real ─────────────────────────────────────────────
    async function pollNewLogs() {
        if (!realtimeChk?.checked || currentPage !== 1) return;
        try {
            const p = new URLSearchParams(buildParams(1));
            p.set('limit', '10');
            const res  = await fetch('/api/audit/logs?' + p.toString(), { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            const logs = data.logs ?? [];
            if (!logs.length) return;

            const newLogs = logs.filter(l => l.id > lastLogId);
            if (!newLogs.length) return;

            lastLogId  = logs[0].id;
            totalLogs += newLogs.length;
            updatePagination();

            // Insere novas linhas no topo com animação
            newLogs.reverse().forEach(log => {
                const tr = buildRow(log, true);
                tbody?.insertBefore(tr, tbody.firstChild);
            });

            // Remove linhas excedentes do final para não crescer infinitamente
            const rows = tbody?.querySelectorAll('tr');
            if (rows && rows.length > 50) {
                for (let i = 50; i < rows.length; i++) rows[i].remove();
            }

            // Atualiza stats silenciosamente
            loadStats();
        } catch { /* silencioso */ }
    }

    function startRealtime() {
        stopRealtime();
        realtimeTimer = setInterval(pollNewLogs, 5000);
    }

    function stopRealtime() {
        if (realtimeTimer) { clearInterval(realtimeTimer); realtimeTimer = null; }
    }

    // ── Renderização ──────────────────────────────────────────────────────
    function buildRow(log, isNew = false) {
        const tr = document.createElement('tr');
        tr.className = 'audit-log-row' + (isNew ? ' audit-new-row' : '');

        const ctx = log.contexto || {};

        // Monta detalhes legíveis priorizando campos mais informativos
        const priorityKeys = ['reason', 'identifier', 'username', 'nivel_acesso', 'email', 'user_id'];
        const otherKeys    = Object.keys(ctx).filter(k => !['status','uri'].includes(k) && !priorityKeys.includes(k));
        const orderedKeys  = [...priorityKeys.filter(k => k in ctx), ...otherKeys].slice(0, 4);

        const details = orderedKeys
            .map(k => {
                const v = ctx[k];
                const label = { reason: 'motivo', identifier: 'login', username: 'usuário', nivel_acesso: 'nível', email: 'e-mail', user_id: 'uuid' }[k] || k;
                return `<span style="color:#94a3b8;">${esc(label)}:</span> <strong style="color:#e2e8f0;">${esc(String(v))}</strong>`;
            })
            .join(' &nbsp;·&nbsp; ');

        const badge = badgeClass(log.evento);
        const icon  = badgeIcon(log.evento);
        const ep    = (log.endpoint || '').replace(/^(GET|POST|PUT|PATCH|DELETE)\s+/, '');

        // Tooltip completo com todos os campos do contexto
        const tooltipCtx = Object.entries(ctx)
            .map(([k, v]) => `${k}: ${String(v)}`)
            .join('\n');

        // All user data is escaped via esc() — safe against XSS
        // lgtm[js/xss]
        tr.innerHTML = `
            <td style="white-space:nowrap;color:#94a3b8;font-size:0.9rem;">${fmtDate(log.criado_em)}</td>
            <td>
                <span class="audit-badge ${badge}">
                    <i class="fa-solid ${icon}"></i>
                    ${esc(log.evento)}
                </span>
            </td>
            <td style="font-family:monospace;font-size:0.92rem;color:#94a3b8;white-space:nowrap;">${esc(log.ip || '—')}</td>
            <td style="font-size:0.9rem;color:#64748b;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(log.endpoint || '')}">${esc(ep || '—')}</td>
            <td style="font-size:0.88rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(tooltipCtx)}">${details || '<span style="color:#475569;">—</span>'}</td>`;
        return tr;
    }

    function renderTable(logs, silent = false) {
        if (!tbody) return;
        if (!logs.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="padding:32px;text-align:center;color:#64748b;"><i class="fa-solid fa-inbox"></i> Nenhum log encontrado.</td></tr>';
            return;
        }
        const frag = document.createDocumentFragment();
        logs.forEach(log => frag.appendChild(buildRow(log)));
        tbody.innerHTML = '';
        tbody.appendChild(frag);
    }

    function updatePagination() {
        if (prevBtn)    prevBtn.disabled    = currentPage <= 1;
        if (nextBtn)    nextBtn.disabled    = currentPage >= lastPage;
        if (pageLabel)  pageLabel.textContent = `Página ${currentPage} de ${lastPage}`;
        if (totalLabel) totalLabel.textContent = `${totalLogs.toLocaleString('pt-BR')} registro${totalLogs !== 1 ? 's' : ''}`;
    }

    // ── Stats ─────────────────────────────────────────────────────────────
    async function loadStats() {
        try {
            const res  = await fetch('/api/audit/stats', { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            const c    = data.contagens || {};

            // Atualiza cada card com o valor e estilo adequado
            const setCard = (id, val, cat) => {
                const el = document.getElementById(id);
                if (!el) return;
                const n = Number(val || 0);
                el.textContent = n.toLocaleString('pt-BR');
                // Cards com zero ficam levemente opacos para indicar "sem eventos"
                const card = el.closest('.audit-stat-card');
                if (card) {
                    card.style.opacity = n === 0 ? '0.55' : '1';
                    card.title = n === 0
                        ? `Nenhum evento de "${cat}" nas últimas 24h`
                        : `${n.toLocaleString('pt-BR')} evento(s) de "${cat}" nas últimas 24h`;
                }
            };

            setCard('stat-total',    c.total,    'todos os tipos');
            setCard('stat-auth',     c.auth,     'autenticação');
            setCard('stat-usuarios', c.usuarios, 'usuários');
            setCard('stat-seguranca',c.seguranca,'segurança');
            setCard('stat-admin',    c.admin,    'ações administrativas (module.*, admin.*)');
        } catch { /* silencioso */ }
    }

    // ── Eventos ───────────────────────────────────────────────────────────
    if (prevBtn) prevBtn.addEventListener('click', () => { if (currentPage > 1) loadLogs(currentPage - 1); });
    if (nextBtn) nextBtn.addEventListener('click', () => { if (currentPage < lastPage) loadLogs(currentPage + 1); });

    if (searchInput) {
        // Restaura busca salva
        const savedSearch = localStorage.getItem('audit_search');
        if (savedSearch) searchInput.value = savedSearch;

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            localStorage.setItem('audit_search', searchInput.value);
            searchTimer = setTimeout(() => { currentPage = 1; loadLogs(1); }, 400);
        });
    }

    if (catSelect)   catSelect.addEventListener('change',   () => { currentPage = 1; activeCategory = ''; localStorage.setItem('audit_categoria', catSelect.value); loadLogs(1); });
    if (periodoSel)  periodoSel.addEventListener('change',  () => { currentPage = 1; localStorage.setItem('audit_periodo', periodoSel.value); loadLogs(1); });
    if (refreshBtn)  refreshBtn.addEventListener('click',   () => { loadLogs(currentPage); loadStats(); });

    // ── Restaura preferências salvas ──────────────────────────────────────
    const savedCategoria = localStorage.getItem('audit_categoria');
    const savedPeriodo   = localStorage.getItem('audit_periodo');
    if (catSelect  && savedCategoria !== null) catSelect.value  = savedCategoria;
    if (periodoSel && savedPeriodo   !== null) periodoSel.value = savedPeriodo;

    if (realtimeChk) {
        realtimeChk.addEventListener('change', () => {
            realtimeChk.checked ? startRealtime() : stopRealtime();
        });
    }

    // Filtro por stat card
    document.querySelectorAll('.audit-stat-card[data-cat]').forEach(card => {
        card.addEventListener('click', () => {
            const cat = card.dataset.cat === 'total' ? '' : card.dataset.cat;
            activeCategory = cat;
            if (catSelect) catSelect.value = cat;
            document.querySelectorAll('.audit-stat-card').forEach(c => c.classList.remove('active'));
            card.classList.add('active');
            currentPage = 1;
            loadLogs(1);
        });
    });

    // Limpar logs antigos — usa modal em vez de confirm()
    const auditClearModal   = document.getElementById('audit-clear-modal');
    const auditClearClose   = document.getElementById('audit-clear-close');
    const auditClearCancel  = document.getElementById('audit-clear-cancel');
    const auditClearConfirm = document.getElementById('audit-clear-confirm');

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (auditClearModal) auditClearModal.classList.add('show');
        });
    }

    const closeAuditClearModal = () => auditClearModal?.classList.remove('show');
    if (auditClearClose)  auditClearClose.addEventListener('click',  closeAuditClearModal);
    if (auditClearCancel) auditClearCancel.addEventListener('click', closeAuditClearModal);

    if (auditClearConfirm) {
        auditClearConfirm.addEventListener('click', async () => {
            auditClearConfirm.disabled = true;
            auditClearConfirm.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removendo...';
            try {
                const res  = await fetch('/api/audit/logs', {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ dias: 90 }),
                });
                const data = await res.json();
                closeAuditClearModal();
                loadLogs(1);
                loadStats();
                if (totalLabel) {
                    totalLabel.textContent = data.message || 'Logs removidos.';
                    setTimeout(() => { if (totalLabel) totalLabel.textContent = ''; }, 4000);
                }
            } catch {
                closeAuditClearModal();
            } finally {
                auditClearConfirm.disabled = false;
                auditClearConfirm.innerHTML = '<i class="fa-solid fa-trash"></i> Remover logs antigos';
            }
        });
    }

    // ── Inicialização ─────────────────────────────────────────────────────
    // Carrega quando a seção entra na viewport (lazy)
    const section = document.getElementById('audit-logs');
    if (section && 'IntersectionObserver' in window) {
        let loaded = false;
        const obs = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && !loaded) {
                loaded = true;
                loadLogs(1);
                loadStats();
                startRealtime();
                obs.disconnect();
            }
        }, { threshold: 0.1 });
        obs.observe(section);
    } else {
        loadLogs(1);
        loadStats();
        startRealtime();
    }

    // Para o polling quando a aba fica inativa
    document.addEventListener('visibilitychange', () => {
        document.hidden ? stopRealtime() : (realtimeChk?.checked && startRealtime());
    });
})();
