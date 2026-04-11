'use strict';

// ── State ─────────────────────────────────────────────────────────────────
const S = {
    project:      null,
    moduleStatus: null,
    tabs:         [],
    activeTab:    null,
    editor:       null,
    monacoReady:  false,
    saveTimer:    null,
    panelFiles:   true,
    panelDeploy:  true,
    pendingIssues: [],
    selectedFolder: '', // pasta selecionada no file tree
};

// ── Helpers ───────────────────────────────────────────────────────────────
function $(id) { return document.getElementById(id); }

function toast(msg, duration) {
    const t = $('ide-toast');
    if (!t) return;
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._t);
    t._t = setTimeout(() => { t.style.opacity = '0'; }, duration || 2500);
}

function setBtn(btn, iconClass, text) {
    if (!btn) return;
    btn.textContent = '';
    var ic = document.createElement('i');
    ic.className = iconClass;
    ic.setAttribute('aria-hidden', 'true');
    btn.appendChild(ic);
    btn.appendChild(document.createTextNode(' ' + text));
}

// ── DOM helpers (Trusted Types safe) ──────────────────────────────────────
function domIcon(cls, color) {
    var i = document.createElement('i');
    i.className = cls;
    i.setAttribute('aria-hidden', 'true');
    if (color) i.style.color = color;
    return i;
}

function domEl(tag, cls, children) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (children) {
        (Array.isArray(children) ? children : [children]).forEach(function (c) {
            if (c == null) return;
            e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
    }
    return e;
}

function domBtn(cls, iconCls, text, action, extra) {
    var b = document.createElement('button');
    b.className = cls;
    b.setAttribute('data-action', action);
    if (extra) Object.keys(extra).forEach(function (k) { b.setAttribute('data-' + k, extra[k]); });
    b.appendChild(domIcon(iconCls));
    b.appendChild(document.createTextNode(' ' + text));
    return b;
}

function setTitleIcon(el, iconCls, color, text) {
    el.textContent = '';
    el.appendChild(domIcon(iconCls, color));
    el.appendChild(document.createTextNode(' ' + text));
}

/**
 * Modal de confirmação — substitui confirm() nativo.
 * Retorna Promise<boolean>.
 */
function ideConfirm(opts) {
    return new Promise(function (resolve) {
        var titleEl  = $('modal-confirm-title');
        var msgEl    = $('modal-confirm-msg');
        var detailEl = $('modal-confirm-detail');
        var okBtn    = $('modal-confirm-ok');
        var cancelBtn = $('modal-confirm-cancel');
        var closeBtn  = $('modal-confirm-close');

        // Título com ícone
        titleEl.textContent = '';
        var iconCls = opts.icon || 'fa-solid fa-circle-question';
        var iconColor = opts.danger ? '#ef4444' : '#6366f1';
        titleEl.appendChild(domIcon(iconCls, iconColor));
        titleEl.appendChild(document.createTextNode(' ' + (opts.title || 'Confirmar')));

        // Mensagem
        msgEl.textContent = opts.message || '';

        // Detalhe (opcional)
        if (opts.detail) {
            detailEl.textContent = opts.detail;
            detailEl.style.display = 'block';
        } else {
            detailEl.style.display = 'none';
        }

        // Botão OK
        okBtn.textContent = '';
        okBtn.className = opts.danger ? 'ide-btn-primary ide-btn-danger-confirm' : 'ide-btn-primary';
        var okIcon = opts.okIcon || (opts.danger ? 'fa-solid fa-trash' : 'fa-solid fa-check');
        okBtn.appendChild(domIcon(okIcon));
        okBtn.appendChild(document.createTextNode(' ' + (opts.okText || 'Confirmar')));

        cancelBtn.textContent = '';
        cancelBtn.appendChild(domIcon('fa-solid fa-xmark'));
        cancelBtn.appendChild(document.createTextNode(' Cancelar'));

        function cleanup(result) {
            hideModal('modal-confirm');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            closeBtn.removeEventListener('click', onCancel);
            resolve(result);
        }
        function onOk() { cleanup(true); }
        function onCancel() { cleanup(false); }

        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        closeBtn.addEventListener('click', onCancel);

        showModal('modal-confirm');
    });
}

/**
 * Modal de input — substitui prompt() nativo.
 * Retorna Promise<string|null> (null se cancelou).
 */
function ideInput(opts) {
    return new Promise(function (resolve) {
        var titleEl = $('modal-input-title');
        var labelEl = $('modal-input-label');
        var inputEl = $('modal-input-value');
        var okBtn   = $('modal-input-ok');
        var cancelBtn = $('modal-input-cancel');
        var closeBtn  = $('modal-input-close');

        titleEl.textContent = '';
        titleEl.appendChild(domIcon(opts.icon || 'fa-solid fa-pen', '#6366f1'));
        titleEl.appendChild(document.createTextNode(' ' + (opts.title || 'Entrada')));

        labelEl.textContent = opts.label || '';
        inputEl.value = opts.value || '';
        inputEl.placeholder = opts.placeholder || '';

        okBtn.textContent = '';
        okBtn.appendChild(domIcon(opts.okIcon || 'fa-solid fa-check'));
        okBtn.appendChild(document.createTextNode(' ' + (opts.okText || 'OK')));

        function cleanup(val) {
            hideModal('modal-input');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            closeBtn.removeEventListener('click', onCancel);
            inputEl.removeEventListener('keydown', onKey);
            resolve(val);
        }
        function onOk() { var v = inputEl.value.trim(); cleanup(v || null); }
        function onCancel() { cleanup(null); }
        function onKey(e) { if (e.key === 'Enter') { e.preventDefault(); onOk(); } }

        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        closeBtn.addEventListener('click', onCancel);
        inputEl.addEventListener('keydown', onKey);

        showModal('modal-input');
        setTimeout(function () { inputEl.focus(); inputEl.select(); }, 100);
    });
}

async function api(method, url, body) {
    const opts = { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } };
    if (body) opts.body = JSON.stringify(body);
    const res  = await fetch(url, opts);

    // Token expirado — redireciona para login
    if (res.status === 401) {
        window.location.replace('/ide/login');
        throw new Error('Sessão expirada');
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const err  = new Error(data.error || `HTTP ${res.status}`);
        err.status = res.status;
        err.data   = data;
        throw err;
    }
    return data;
}

function showModal(id) { const el=$(id); if(el){el.removeAttribute('aria-hidden');el.classList.add('show');} }
function hideModal(id) { const el=$(id); if(el){el.setAttribute('aria-hidden','true');el.classList.remove('show');} }

// ── Get project ID from URL ───────────────────────────────────────────────
function getProjectId() {
    return new URLSearchParams(window.location.search).get('project') || '';
}

// ── Load Project ──────────────────────────────────────────────────────────
async function loadProject() {
    const id = getProjectId();
    if (!id) {
        window.location.href = '/dashboard/ide';
        return;
    }

    try {
        const data = await api('GET', `/api/ide/projects/${id}`);
        S.project = data.project;
        $('topbar-project-name').textContent = S.project.name;
        $('topbar-module-name').textContent = S.project.module_name;
        $('filetree-module-name').textContent = S.project.module_name;
        $('btn-new-file').disabled = false;
        $('btn-new-folder').disabled = false;
        renderFileTree();
        await renderDeployPanel();

        // Open first file automatically
        const files = Object.keys(S.project.files || {}).sort();
        if (files.length) openFile(files[0]);
    } catch (e) {
        toast('Erro ao carregar projeto: ' + e.message);
        setTimeout(() => { window.location.href = '/dashboard/ide'; }, 2000);
    }
}

// ── Monaco ────────────────────────────────────────────────────────────────
require.config({
    paths: { vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs' },
    'vs/nls': { availableLanguages: { '*': '' } },
    trustedTypesPolicy: window.trustedTypes
        ? window.trustedTypes.createPolicy('monaco-editor', {
            createHTML: function (s) { return s; },
            createScriptURL: function (s) { return s; },
            createScript: function (s) { return s; }
        })
        : undefined
});

require(['vs/editor/editor.main'], function () {
    const dark = document.body.classList.contains('dark');
    S.editor = monaco.editor.create($('monaco-editor'), {
        value: '',
        language: 'php',
        theme: dark ? 'vs-dark' : 'vs',
        fontSize: 15,
        fontFamily: "'JetBrains Mono','Fira Code','Cascadia Code',Consolas,monospace",
        minimap: { enabled: window.innerWidth > 900 },
        scrollBeyondLastLine: false,
        automaticLayout: true,
        tabSize: 4,
        insertSpaces: true,
        wordWrap: 'off',
        renderLineHighlight: 'all',
        smoothScrolling: true,
        cursorBlinking: 'smooth',
        bracketPairColorization: { enabled: true },
        lineNumbers: 'on',
        glyphMargin: true,
        folding: true,
        quickSuggestions: { other: true, comments: false, strings: true },
        suggestOnTriggerCharacters: true,
        acceptSuggestionOnEnter: 'on',
        snippetSuggestions: 'top',
        wordBasedSuggestions: 'currentDocument',
        parameterHints: { enabled: true },
        hover: { enabled: true },
        lightbulb: { enabled: true },
    });

    S.monacoReady = true;

    // ── PHP Language Services ──────────────────────────────────────────────
    initPhpLanguageServices();

    S.editor.onDidChangeCursorPosition(e => {
        const p = e.position;
        $('status-cursor').textContent = `Ln ${p.lineNumber}  Col ${p.column}`;
    });

    S.editor.onDidChangeModelContent(() => {
        if (!S.activeTab) return;
        const tab = S.tabs.find(t => t.path === S.activeTab);
        if (tab) { tab.content = S.editor.getValue(); tab.modified = true; renderTabs(); }
        clearTimeout(S.saveTimer);
        S.saveTimer = setTimeout(autoSave, 1500);
    });

    loadProject();
}, function (err) {
    // Monaco falhou ao carregar — carrega o projeto mesmo assim
    console.error('Monaco Editor falhou ao carregar:', err);
    toast('Editor de código não carregou. Verifique sua conexão.', 5000);
    loadProject();
});

});

// ══════════════════════════════════════════════════════════════════════════
// PHP Language Services — Autocomplete + Diagnósticos em tempo real
// ══════════════════════════════════════════════════════════════════════════
function initPhpLanguageServices() {
    if (!monaco || !S.editor) return;

    // ── 1. PHP Autocomplete (snippets + framework classes) ──
    monaco.languages.registerCompletionItemProvider('php', {
        triggerCharacters: ['$', '-', '>', ':', '\\', ' '],
        provideCompletionItems: function (model, position) {
            var word = model.getWordUntilPosition(position);
            var range = {
                startLineNumber: position.lineNumber, endLineNumber: position.lineNumber,
                startColumn: word.startColumn, endColumn: word.endColumn
            };
            var line = model.getLineContent(position.lineNumber);
            var suggestions = [];

            // Snippets PHP comuns
            var snippets = [
                { label: 'Response::json', kind: monaco.languages.CompletionItemKind.Function,
                  insertText: 'Response::json([${1:\'key\' => \'value\'}], ${2:200})', insertTextRules: 4,
                  detail: 'Retorna resposta JSON', documentation: 'Retorna uma resposta JSON com status HTTP.' },
                { label: 'Response::html', kind: monaco.languages.CompletionItemKind.Function,
                  insertText: 'Response::html(${1:\'<p>conteudo</p>\'})', insertTextRules: 4,
                  detail: 'Retorna resposta HTML' },
                { label: 'AuthHybridMiddleware', kind: monaco.languages.CompletionItemKind.Class,
                  insertText: 'AuthHybridMiddleware::class', detail: 'Middleware de autenticação (cookie ou Bearer)' },
                { label: 'AdminOnlyMiddleware', kind: monaco.languages.CompletionItemKind.Class,
                  insertText: 'AdminOnlyMiddleware::class', detail: 'Apenas admin_system' },
                { label: 'RateLimitMiddleware', kind: monaco.languages.CompletionItemKind.Class,
                  insertText: "RateLimitMiddleware::class, ['limit' => ${1:60}, 'window' => ${2:60}, 'key' => '${3:modulo}']",
                  insertTextRules: 4, detail: 'Rate limiting por janela de tempo' },
                { label: 'CircuitBreakerMiddleware', kind: monaco.languages.CompletionItemKind.Class,
                  insertText: "CircuitBreakerMiddleware::class, ['service' => '${1:database}', 'threshold' => ${2:5}, 'cooldown' => ${3:30}]",
                  insertTextRules: 4, detail: 'Circuit breaker para tolerância a falhas' },
                { label: 'router->get', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "\\$router->get('${1:/api/rota}', [${2:Controller}::class, '${3:metodo}'], \\$${4:auth});",
                  insertTextRules: 4, detail: 'Rota GET' },
                { label: 'router->post', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "\\$router->post('${1:/api/rota}', [${2:Controller}::class, '${3:metodo}'], \\$${4:auth});",
                  insertTextRules: 4, detail: 'Rota POST' },
                { label: 'router->put', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "\\$router->put('${1:/api/rota/{id}}', [${2:Controller}::class, '${3:metodo}'], \\$${4:auth});",
                  insertTextRules: 4, detail: 'Rota PUT' },
                { label: 'router->delete', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "\\$router->delete('${1:/api/rota/{id}}', [${2:Controller}::class, '${3:metodo}'], \\$${4:auth});",
                  insertTextRules: 4, detail: 'Rota DELETE' },
                { label: 'auth_user', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "\\$request->attribute('auth_user')", insertTextRules: 4,
                  detail: 'Usuário autenticado (injetado pelo AuthHybridMiddleware)' },
                { label: 'getUuid', kind: monaco.languages.CompletionItemKind.Method,
                  insertText: "->getUuid()->toString()", detail: 'UUID do usuário como string' },
                { label: 'PDO::FETCH_ASSOC', kind: monaco.languages.CompletionItemKind.Constant,
                  insertText: 'PDO::FETCH_ASSOC', detail: 'Retorna array associativo' },
                { label: 'migration-up', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "return [\n    'up' => function (PDO \\$pdo): void {\n        \\$driver = \\$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);\n        if (\\$driver === 'pgsql') {\n            \\$pdo->exec(\"CREATE TABLE IF NOT EXISTS ${1:tabela} (\n                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),\n                criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()\n            )\");\n        } else {\n            \\$pdo->exec(\"CREATE TABLE IF NOT EXISTS ${1:tabela} (\n                id CHAR(36) PRIMARY KEY,\n                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\");\n        }\n    },\n    'down' => function (PDO \\$pdo): void {\n        \\$pdo->exec(\"DROP TABLE IF EXISTS ${1:tabela}\");\n    },\n];",
                  insertTextRules: 4, detail: 'Template de migration completo (PostgreSQL + MySQL)' },
                { label: 'MiddlewareInterface', kind: monaco.languages.CompletionItemKind.Interface,
                  insertText: 'MiddlewareInterface', detail: 'Interface para middlewares do módulo' },
                { label: 'handle-middleware', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "public function handle(Request \\$request, callable \\$next): Response\n{\n    ${1:// lógica antes}\n    \\$response = \\$next(\\$request);\n    ${2:// lógica depois}\n    return \\$response;\n}",
                  insertTextRules: 4, detail: 'Método handle() da MiddlewareInterface' },
                { label: 'declare-strict', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "declare(strict_types=1);\n\n", insertTextRules: 4,
                  detail: 'Habilita strict_types' },
                { label: 'constructor-readonly', kind: monaco.languages.CompletionItemKind.Snippet,
                  insertText: "public function __construct(\n    private readonly ${1:Type} \\$${2:dependency}\n) {}",
                  insertTextRules: 4, detail: 'Constructor com property promotion (PHP 8.1+)' },
            ];

            // Adiciona classes do módulo atual como sugestões
            if (S.project) {
                var files = Object.keys(S.project.files || {});
                files.forEach(function (f) {
                    if (!f.endsWith('.php')) return;
                    var parts = f.split('/');
                    var className = parts[parts.length - 1].replace('.php', '');
                    var ns = 'Src\\Modules\\' + S.project.module_name + '\\' + parts.slice(0, -1).join('\\');
                    suggestions.push({
                        label: className, kind: monaco.languages.CompletionItemKind.Class,
                        insertText: className, detail: ns, range: range
                    });
                });
            }

            snippets.forEach(function (s) { suggestions.push(Object.assign({ range: range }, s)); });
            return { suggestions: suggestions };
        }
    });

    // ── 2. PHP Hover (documentação inline) ──
    monaco.languages.registerHoverProvider('php', {
        provideHover: function (model, position) {
            var word = model.getWordAtPosition(position);
            if (!word) return null;
            var docs = {
                'Response': { value: '**Response** — Classe de resposta HTTP\n\n`Response::json($data, $status)` — Retorna JSON\n\n`Response::html($html, $status)` — Retorna HTML' },
                'Request': { value: '**Request** — Objeto da requisição HTTP\n\n`$request->body` — Body da requisição (array)\n\n`$request->params` — Parâmetros de rota ({id})\n\n`$request->query` — Query string (?key=value)\n\n`$request->header(\'Nome\')` — Header HTTP\n\n`$request->attribute(\'auth_user\')` — Usuário autenticado' },
                'AuthHybridMiddleware': { value: '**AuthHybridMiddleware** — Autenticação obrigatória\n\nAceita cookie `auth_token` ou header `Authorization: Bearer {token}`.\n\nPopula `$request->attribute(\'auth_user\')` com o usuário logado.' },
                'AdminOnlyMiddleware': { value: '**AdminOnlyMiddleware** — Apenas admin_system\n\nBloqueia usuários com nível diferente de `admin_system`.' },
                'RateLimitMiddleware': { value: '**RateLimitMiddleware** — Limite de requisições\n\nParâmetros: `limit` (máx req), `window` (segundos), `key` (identificador único).' },
                'PDO': { value: '**PDO** — PHP Data Objects\n\nInjetado automaticamente pelo container.\n\nUse `$pdo->prepare($sql)->execute([$params])` para queries seguras.' },
            };
            if (docs[word.word]) {
                return { contents: [docs[word.word]] };
            }
            return null;
        }
    });

    // ── 3. Diagnósticos em tempo real ──
    S.diagnosticTimer = null;
    S.ignoredDiagnostics = new Set();
    S.currentDiagnostics = [];
    var diagDecorations = [];

    S.editor.onDidChangeModelContent(function () {
        clearTimeout(S.diagnosticTimer);
        S.diagnosticTimer = setTimeout(runDiagnostics, 1200);
    });

    async function runDiagnostics() {
        if (!S.project || !S.activeTab || !S.activeTab.endsWith('.php')) {
            clearDiagnostics();
            return;
        }
        var content = S.editor.getValue();
        if (!content.trim()) { clearDiagnostics(); return; }

        try {
            var data = await api('POST', '/api/ide/projects/' + S.project.id + '/lint', {
                path: S.activeTab, content: content
            });
            applyDiagnostics(data.diagnostics || []);
        } catch (e) { /* silencioso — lint é best-effort */ }
    }

    function applyDiagnostics(diagnostics) {
        S.currentDiagnostics = diagnostics;
        var model = S.editor.getModel();
        if (!model) return;

        // Filtra ignorados
        var active = diagnostics.filter(function (d) {
            return !S.ignoredDiagnostics.has(d.code + ':' + d.line);
        });

        // Monaco markers
        var markers = active.map(function (d) {
            return {
                severity: d.severity,
                startLineNumber: d.line, endLineNumber: d.line,
                startColumn: 1, endColumn: model.getLineLength(d.line) + 1,
                message: d.message,
                code: d.code,
                source: 'Vupi.us IDE',
            };
        });
        monaco.editor.setModelMarkers(model, 'vupi-php', markers);

        // Decorações (gutter icons)
        var newDecorations = active.map(function (d) {
            var cls = d.severity === 8 ? 'ide-gutter-error' : (d.severity === 4 ? 'ide-gutter-warn' : 'ide-gutter-info');
            return {
                range: new monaco.Range(d.line, 1, d.line, 1),
                options: {
                    isWholeLine: true,
                    glyphMarginClassName: cls,
                    glyphMarginHoverMessage: { value: '**' + d.code + '**: ' + d.message },
                    className: d.severity === 8 ? 'ide-line-error' : (d.severity === 4 ? 'ide-line-warn' : ''),
                }
            };
        });
        diagDecorations = S.editor.deltaDecorations(diagDecorations, newDecorations);

        // Code actions (lâmpada)
        updateCodeActions(active);

        // Status bar
        updateDiagStatusBar(active);

        // Painel de diagnósticos
        renderDiagPanel(active);
    }

    function clearDiagnostics() {
        var model = S.editor.getModel();
        if (model) monaco.editor.setModelMarkers(model, 'vupi-php', []);
        diagDecorations = S.editor.deltaDecorations(diagDecorations, []);
        updateDiagStatusBar([]);
        renderDiagPanel([]);
    }

    function updateCodeActions(diagnostics) {
        monaco.languages.registerCodeActionProvider('php', {
            provideCodeActions: function (model, range, context) {
                var actions = [];
                diagnostics.forEach(function (d) {
                    if (!d.fixable) return;
                    if (d.line < range.startLineNumber || d.line > range.endLineNumber) return;
                    actions.push({
                        title: '✓ Corrigir: ' + d.code,
                        kind: 'quickfix',
                        isPreferred: true,
                        command: {
                            id: 'vupi.fix',
                            title: 'Corrigir ' + d.code,
                            arguments: [d]
                        }
                    });
                    actions.push({
                        title: '⊘ Ignorar: ' + d.code,
                        kind: 'quickfix',
                        command: {
                            id: 'vupi.ignore',
                            title: 'Ignorar ' + d.code,
                            arguments: [d]
                        }
                    });
                });
                return { actions: actions, dispose: function () {} };
            }
        });

        S.editor.addCommand(monaco.KeyCode.F1, function () {});
        if (!S._vupiCmdsRegistered) {
            S._vupiCmdsRegistered = true;
            S.editor.addAction({
                id: 'vupi.fix', label: 'Corrigir problema',
                run: function (ed, d) { fixDiagnostic(d); }
            });
            S.editor.addAction({
                id: 'vupi.ignore', label: 'Ignorar problema',
                run: function (ed, d) { ignoreDiagnostic(d); }
            });
        }
    }

    function updateDiagStatusBar(diagnostics) {
        var btn = $('status-diag');
        var icon = $('status-diag-icon');
        var count = $('status-diag-count');
        if (!btn) return;

        var errors = diagnostics.filter(function (d) { return d.severity === 8; }).length;
        var warnings = diagnostics.filter(function (d) { return d.severity === 4; }).length;

        if (!diagnostics.length) {
            btn.style.display = 'none';
            return;
        }
        btn.style.display = 'inline-flex';
        if (errors > 0) {
            btn.className = 'ide-status-diag has-errors';
            icon.className = 'fa-solid fa-circle-xmark';
            count.textContent = errors + (warnings > 0 ? ' erros, ' + warnings + ' avisos' : ' erro' + (errors > 1 ? 's' : ''));
        } else {
            btn.className = 'ide-status-diag has-warnings';
            icon.className = 'fa-solid fa-triangle-exclamation';
            count.textContent = warnings + ' aviso' + (warnings > 1 ? 's' : '');
        }
    }

    function renderDiagPanel(diagnostics) {
        var list = $('ide-diag-list');
        if (!list) return;
        list.textContent = '';

        if (!diagnostics.length) {
            $('ide-diag-panel').style.display = 'none';
            return;
        }

        diagnostics.forEach(function (d) {
            var ignored = S.ignoredDiagnostics.has(d.code + ':' + d.line);
            var item = document.createElement('div');
            item.className = 'ide-diag-item' + (ignored ? ' ide-diag-ignored' : '');

            var ic = document.createElement('i');
            ic.className = 'ide-diag-icon ' + (d.severity === 8 ? 'err fa-solid fa-circle-xmark' : d.severity === 4 ? 'warn fa-solid fa-triangle-exclamation' : 'info fa-solid fa-circle-info');
            ic.setAttribute('aria-hidden', 'true');

            var body = document.createElement('div');
            body.className = 'ide-diag-body';
            var msg = document.createElement('div');
            msg.className = 'ide-diag-msg';
            msg.textContent = d.message;
            var meta = document.createElement('div');
            meta.className = 'ide-diag-meta';
            var codeSpan = document.createElement('span');
            codeSpan.className = 'ide-diag-code';
            codeSpan.textContent = d.code;
            var lineSpan = document.createElement('span');
            lineSpan.className = 'ide-diag-line';
            lineSpan.textContent = 'Linha ' + d.line;
            meta.appendChild(codeSpan);
            meta.appendChild(lineSpan);
            if (d.suggestion) {
                var sug = document.createElement('span');
                sug.style.cssText = 'color:#6e7681;font-style:italic;';
                sug.textContent = d.suggestion;
                meta.appendChild(sug);
            }
            body.appendChild(msg);
            body.appendChild(meta);

            var actions = document.createElement('div');
            actions.className = 'ide-diag-actions';

            if (d.fixable && !ignored) {
                var fixBtn = document.createElement('button');
                fixBtn.className = 'ide-diag-fix-btn';
                fixBtn.textContent = 'Corrigir';
                fixBtn.addEventListener('click', function (e) { e.stopPropagation(); fixDiagnostic(d); });
                actions.appendChild(fixBtn);
            }

            var ignBtn = document.createElement('button');
            ignBtn.className = 'ide-diag-ignore-btn';
            ignBtn.textContent = ignored ? 'Restaurar' : 'Ignorar';
            ignBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (ignored) S.ignoredDiagnostics.delete(d.code + ':' + d.line);
                else ignoreDiagnostic(d);
            });
            actions.appendChild(ignBtn);

            item.appendChild(ic);
            item.appendChild(body);
            item.appendChild(actions);

            // Clicar na linha vai para o erro no editor
            item.addEventListener('click', function () {
                S.editor.revealLineInCenter(d.line);
                S.editor.setPosition({ lineNumber: d.line, column: 1 });
                S.editor.focus();
            });

            list.appendChild(item);
        });
    }

    async function fixDiagnostic(d) {
        if (!S.project || !S.activeTab) return;
        try {
            var data = await api('POST', '/api/ide/projects/' + S.project.id + '/autofix', {});
            if (data.files && data.files[S.activeTab]) {
                var newContent = data.files[S.activeTab];
                S.editor.setValue(newContent);
                var tab = S.tabs.find(function (t) { return t.path === S.activeTab; });
                if (tab) { tab.content = newContent; tab.modified = true; renderTabs(); }
                S.project.files[S.activeTab] = newContent;
                toast('✓ Correção aplicada');
                setTimeout(runDiagnostics, 300);
            }
        } catch (e) { toast('Erro ao corrigir: ' + e.message); }
    }

    function ignoreDiagnostic(d) {
        S.ignoredDiagnostics.add(d.code + ':' + d.line);
        applyDiagnostics(S.currentDiagnostics);
        toast('Problema ignorado: ' + d.code);
    }

    // Status bar toggle
    var diagBtn = $('status-diag');
    if (diagBtn) {
        diagBtn.addEventListener('click', function () {
            var panel = $('ide-diag-panel');
            if (panel) panel.style.display = panel.style.display === 'none' ? '' : 'none';
        });
    }
    var diagClose = $('ide-diag-close');
    if (diagClose) {
        diagClose.addEventListener('click', function () {
            var panel = $('ide-diag-panel');
            if (panel) panel.style.display = 'none';
        });
    }

    // Roda diagnósticos ao trocar de arquivo
    var origOpenFile = openFile;
    window._diagOpenFilePatched = true;
    S._runDiagnostics = runDiagnostics;
}

// ── File Tree ─────────────────────────────────────────────────────────────
function renderFileTree() {
    const tree = $('ide-filetree');
    tree.textContent = '';
    if (!S.project) {
        var es = document.createElement('div'); es.className = 'ide-empty-state';
        var ep = document.createElement('p'); ep.textContent = 'Nenhum projeto'; es.appendChild(ep);
        tree.appendChild(es); return;
    }

    const files = Object.keys(S.project.files || {}).sort();
    if (!files.length) {
        var es2 = document.createElement('div'); es2.className = 'ide-empty-state';
        var ep2 = document.createElement('p'); ep2.textContent = 'Nenhum arquivo'; es2.appendChild(ep2);
        tree.appendChild(es2); return;
    }

    const folders = {};
    files.forEach(path => {
        const parts = path.split('/');
        const folder = parts.length > 1 ? parts.slice(0, -1).join('/') : '';
        if (!folders[folder]) folders[folder] = [];
        folders[folder].push(path);
    });

    // Root files
    (folders[''] || []).forEach(f => tree.appendChild(buildFileNode(f)));

    // Folders
    Object.keys(folders).filter(f => f !== '').sort().forEach(folder => {
        tree.appendChild(buildFolderNode(folder, folders[folder]));
    });

    // Click on empty space deselects folder
    tree.addEventListener('click', function (e) {
        if (e.target === tree || e.target.closest('.ide-empty-state')) {
            document.querySelectorAll('.ide-tree-folder-header.selected').forEach(function (el) { el.classList.remove('selected'); });
            S.selectedFolder = '';
        }
    });
}

function buildFolderNode(folderPath, filePaths) {
    var wrap = document.createElement('div');
    wrap.className = 'ide-tree-folder';

    var header = document.createElement('div');
    header.className = 'ide-tree-folder-header';
    header.setAttribute('role', 'button');
    header.setAttribute('tabindex', '0');

    var chevron = document.createElement('i');
    chevron.className = 'fa-solid fa-chevron-down ide-folder-chevron';
    chevron.setAttribute('aria-hidden', 'true');

    var folderIcon = document.createElement('i');
    folderIcon.className = 'fa-solid fa-folder ide-folder-icon';
    folderIcon.setAttribute('aria-hidden', 'true');

    var nameSpan = document.createElement('span');
    nameSpan.className = 'ide-folder-name';
    nameSpan.textContent = folderPath;

    var countBadge = document.createElement('span');
    countBadge.className = 'ide-folder-count';
    countBadge.textContent = filePaths.length;

    header.appendChild(chevron);
    header.appendChild(folderIcon);
    header.appendChild(nameSpan);
    header.appendChild(countBadge);

    var children = document.createElement('div');
    children.className = 'ide-tree-folder-children';
    filePaths.forEach(f => children.appendChild(buildFileNode(f)));

    header.addEventListener('click', function (e) {
        e.stopPropagation();
        // Toggle expand/collapse
        var open = children.style.display !== 'none';
        children.style.display = open ? 'none' : '';
        chevron.style.transform = open ? 'rotate(-90deg)' : '';
        folderIcon.className = open ? 'fa-solid fa-folder ide-folder-icon' : 'fa-solid fa-folder-open ide-folder-icon';
        // Select this folder
        document.querySelectorAll('.ide-tree-folder-header.selected').forEach(function (el) { el.classList.remove('selected'); });
        header.classList.add('selected');
        S.selectedFolder = folderPath;
    });
    header.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); header.click(); }
    });

    wrap.appendChild(header);
    wrap.appendChild(children);
    return wrap;
}

function buildFileNode(path) {
    var name = path.split('/').pop();
    var ext = name.split('.').pop().toLowerCase();
    var iconMap = { php:'fa-brands fa-php', js:'fa-brands fa-js', css:'fa-brands fa-css3-alt', html:'fa-brands fa-html5', json:'fa-solid fa-brackets-curly', md:'fa-solid fa-file-lines', sql:'fa-solid fa-database', sh:'fa-solid fa-terminal' };
    var colorMap = { php:'#818cf8', js:'#f9e2af', css:'#89dceb', html:'#fab387', json:'#a6e3a1', md:'#cdd6f4', sql:'#89b4fa', sh:'#a6e3a1' };
    var iconCls = iconMap[ext] || 'fa-solid fa-file-code';
    var color = colorMap[ext] || '#cdd6f4';
    var active = S.activeTab === path;

    var row = document.createElement('div');
    row.className = 'ide-tree-file' + (active ? ' active' : '');
    row.setAttribute('role', 'button');
    row.setAttribute('tabindex', '0');
    row.setAttribute('aria-label', 'Abrir ' + name);

    var ic = document.createElement('i');
    ic.className = iconCls + ' ide-file-icon';
    ic.style.color = color;
    ic.setAttribute('aria-hidden', 'true');

    var sp = document.createElement('span');
    sp.className = 'ide-file-name';
    sp.textContent = name;

    var del = document.createElement('button');
    del.className = 'ide-file-del';
    del.setAttribute('aria-label', 'Remover ' + name);
    del.title = 'Remover arquivo';
    var delIc = document.createElement('i');
    delIc.className = 'fa-solid fa-xmark';
    delIc.setAttribute('aria-hidden', 'true');
    del.appendChild(delIc);
    del.addEventListener('click', function (e) { e.stopPropagation(); confirmDeleteFile(path); });

    row.appendChild(ic);
    row.appendChild(sp);
    row.appendChild(del);
    row.addEventListener('click', function () { openFile(path); });
    row.addEventListener('keydown', function (e) { if (e.key === 'Enter') openFile(path); });

    return row;
}

// ── Tabs ──────────────────────────────────────────────────────────────────
function renderTabs() {
    const container = $('ide-tabs');
    container.textContent = '';
    if (!S.tabs.length) return;

    S.tabs.forEach(function (tab) {
        var name = tab.path.split('/').pop();
        var div = document.createElement('div');
        div.className = 'ide-tab' + (tab.path === S.activeTab ? ' active' : '') + (tab.modified ? ' modified' : '');
        div.setAttribute('role', 'tab');
        div.setAttribute('aria-selected', tab.path === S.activeTab ? 'true' : 'false');
        div.title = tab.path;

        var sp = document.createElement('span');
        sp.textContent = name;

        var closeBtn = document.createElement('button');
        closeBtn.className = 'ide-tab-close';
        closeBtn.setAttribute('aria-label', 'Fechar ' + name);
        var closeIc = document.createElement('i');
        closeIc.className = 'fa-solid fa-xmark';
        closeIc.setAttribute('aria-hidden', 'true');
        closeBtn.appendChild(closeIc);
        closeBtn.addEventListener('click', function (e) { e.stopPropagation(); closeTab(tab.path); });

        div.appendChild(sp);
        div.appendChild(closeBtn);
        div.addEventListener('click', function () { switchTab(tab.path); });

        container.appendChild(div);
    });
}

function openFile(path) {
    if (!S.project) return;
    const content = S.project.files[path] ?? '';
    if (!S.tabs.find(t => t.path === path)) S.tabs.push({ path, content, modified: false });
    S.activeTab = path;
    renderTabs();
    renderFileTree();
    loadEditor(path, content);
    $('status-file-name').textContent = path;
    // Roda diagnósticos ao abrir arquivo PHP
    if (path.endsWith('.php') && S._runDiagnostics) {
        clearTimeout(S.diagnosticTimer);
        S.diagnosticTimer = setTimeout(S._runDiagnostics, 800);
    }
}

function switchTab(path) {
    if (S.activeTab && S.editor) {
        const cur = S.tabs.find(t => t.path === S.activeTab);
        if (cur) cur.content = S.editor.getValue();
    }
    S.activeTab = path;
    const tab = S.tabs.find(t => t.path === path);
    if (tab) { renderTabs(); renderFileTree(); loadEditor(path, tab.content); $('status-file-name').textContent = path; }
}

async function closeTab(path) {
    const idx = S.tabs.findIndex(t => t.path === path);
    if (idx === -1) return;
    if (S.tabs[idx].modified) {
        var ok = await ideConfirm({
            title: 'Alterações não salvas',
            icon: 'fa-solid fa-triangle-exclamation',
            message: '"' + path.split('/').pop() + '" tem alterações não salvas.',
            detail: 'Se fechar, as alterações serão perdidas.',
            okText: 'Fechar mesmo assim',
            okIcon: 'fa-solid fa-xmark',
        });
        if (!ok) return;
    }
    S.tabs.splice(idx, 1);
    if (S.activeTab === path) {
        const next = S.tabs[idx] || S.tabs[idx - 1];
        if (next) switchTab(next.path);
        else { S.activeTab = null; showWelcome(); }
    }
    renderTabs(); renderFileTree();
}

function loadEditor(path, content) {
    if (!S.monacoReady) return;
    const ext = path.split('.').pop().toLowerCase();
    const langs = { php:'php', js:'javascript', ts:'typescript', css:'css', html:'html', json:'json', md:'markdown', sql:'sql', sh:'shell', yaml:'yaml', yml:'yaml', txt:'plaintext' };
    const lang = langs[ext] || 'plaintext';
    S.editor.setModel(monaco.editor.createModel(content, lang));
    $('monaco-editor').style.display = 'block';
    $('ide-welcome').style.display = 'none';
    $('status-lang').textContent = lang.toUpperCase();
}

function showWelcome() {
    $('ide-welcome').style.display = 'flex';
    $('monaco-editor').style.display = 'none';
    $('status-file-name').textContent = 'Nenhum arquivo';
    $('status-lang').textContent = '';
    $('status-cursor').textContent = '';
}

// ── Auto Save ─────────────────────────────────────────────────────────────
async function autoSave() {
    if (!S.project || !S.activeTab) return;
    const tab = S.tabs.find(t => t.path === S.activeTab);
    if (!tab || !tab.modified) return;
    try {
        await api('PUT', `/api/ide/projects/${S.project.id}/files`, { path: tab.path, content: tab.content });
        tab.modified = false;
        S.project.files[tab.path] = tab.content;
        renderTabs();
        const sv = $('status-saved');
        if (sv) { sv.style.display = 'flex'; clearTimeout(sv._t); sv._t = setTimeout(() => { sv.style.display = 'none'; }, 2000); }
    } catch (e) { console.warn('Auto-save:', e.message); }
}

async function saveAll() {
    if (!S.project) return;
    let n = 0;
    for (const tab of S.tabs) {
        if (tab.modified) {
            try {
                await api('PUT', `/api/ide/projects/${S.project.id}/files`, { path: tab.path, content: tab.content });
                S.project.files[tab.path] = tab.content;
                tab.modified = false;
                n++;
            } catch (e) { console.warn(e); }
        }
    }
    renderTabs();
    if (n) toast(`${n} arquivo(s) salvo(s)`);
    else toast('Tudo já salvo');
}

$('btn-save-all').addEventListener('click', saveAll);

// ── New File ──────────────────────────────────────────────────────────────
$('btn-new-file').addEventListener('click', openNewFileModal);
$('btn-welcome-file').addEventListener('click', openNewFileModal);

function openNewFileModal() {
    if (!S.project) return;
    var prefix = S.selectedFolder ? S.selectedFolder + '/' : '';
    $('input-file-path').value = prefix;
    var hint = $('new-file-hint');
    if (hint) hint.textContent = prefix ? 'Criando dentro de: ' + S.selectedFolder + '/' : 'Criando na raiz do módulo';
    showModal('modal-new-file');
    setTimeout(function () { var inp = $('input-file-path'); inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }, 100);
}

$('modal-nf-cancel').addEventListener('click', () => hideModal('modal-new-file'));
$('modal-nf-close').addEventListener('click', () => hideModal('modal-new-file'));

$('modal-nf-confirm').addEventListener('click', async () => {
    const path = $('input-file-path').value.trim();
    if (!path) return;
    const ext = path.split('.').pop().toLowerCase();
    const ns = S.project.module_name;
    const cls = path.split('/').pop().replace('.' + ext, '');
    const defaults = {
        php: `<?php\n\nnamespace Src\\Modules\\${ns};\n\nclass ${cls}\n{\n    //\n}\n`,
        js: `'use strict';\n\n`,
        css: `/* ${path} */\n`,
        json: `{\n}\n`,
        md: `# ${cls}\n`,
    };
    const content = defaults[ext] || '';
    try {
        await api('PUT', `/api/ide/projects/${S.project.id}/files`, { path, content });
        S.project.files[path] = content;
        hideModal('modal-new-file');
        renderFileTree();
        openFile(path);
    } catch (e) { toast('Erro: ' + e.message); }
});

// ── Delete File ───────────────────────────────────────────────────────────
function confirmDeleteFile(path) {
    $('delete-file-name').textContent = path.split('/').pop();
    $('modal-df-confirm').dataset.path = path;
    showModal('modal-delete-file');
}

$('modal-df-cancel').addEventListener('click', () => hideModal('modal-delete-file'));
$('modal-df-close').addEventListener('click', () => hideModal('modal-delete-file'));

$('modal-df-confirm').addEventListener('click', async function () {
    const path = this.dataset.path;
    try {
        await api('DELETE', `/api/ide/projects/${S.project.id}/files`, { path });
        delete S.project.files[path];
        const idx = S.tabs.findIndex(t => t.path === path);
        if (idx !== -1) {
            S.tabs.splice(idx, 1);
            if (S.activeTab === path) {
                const next = S.tabs[0];
                if (next) switchTab(next.path);
                else { S.activeTab = null; showWelcome(); }
            }
        }
        hideModal('modal-delete-file');
        renderFileTree(); renderTabs();
    } catch (e) { toast('Erro: ' + e.message); }
});

// ── New Folder ────────────────────────────────────────────────────────────
$('btn-new-folder').addEventListener('click', function () {
    if (!S.project) return;
    var prefix = S.selectedFolder ? S.selectedFolder + '/' : '';
    $('input-folder-path').value = '';
    var hint = $('new-folder-hint');
    if (hint) hint.textContent = prefix ? 'Criando dentro de: ' + S.selectedFolder + '/' : 'Criando na raiz do módulo';
    showModal('modal-new-folder');
    setTimeout(function () { $('input-folder-path').focus(); }, 100);
});

$('modal-nfld-cancel').addEventListener('click', function () { hideModal('modal-new-folder'); });
$('modal-nfld-close').addEventListener('click', function () { hideModal('modal-new-folder'); });

$('modal-nfld-confirm').addEventListener('click', async function () {
    var name = $('input-folder-path').value.trim();
    if (!name) return;
    name = name.replace(/\.\./g, '').replace(/\\/g, '/');
    // Use context prefix if set, otherwise use selectedFolder
    var prefix = S._ctxFolderPrefix || (S.selectedFolder ? S.selectedFolder + '/' : '');
    S._ctxFolderPrefix = null;
    var folderPath = prefix + name;
    try {
        var folders = Object.keys(S.project.files || {}).map(function (p) { var parts = p.split('/'); return parts.length > 1 ? parts.slice(0, -1).join('/') : ''; }).filter(Boolean);
        folders.push(folderPath);
        await api('PUT', '/api/ide/projects/' + S.project.id + '/folders', { folders: [...new Set(folders)] });
        hideModal('modal-new-folder');
        toast('Pasta criada: ' + folderPath);
        renderFileTree();
    } catch (e) { toast('Erro: ' + e.message); }
});

// ── Context Menu (File Tree) ──────────────────────────────────────────────
(function initContextMenu() {
    var ctxMenu = $('ide-ctx-menu');
    var ctxTarget = null; // path or folder being right-clicked

    // Hide on click outside
    document.addEventListener('click', function () { ctxMenu.style.display = 'none'; });
    document.addEventListener('contextmenu', function (e) {
        if (!e.target.closest('#ide-filetree')) ctxMenu.style.display = 'none';
    });

    // Show on right-click in file tree
    $('ide-filetree').addEventListener('contextmenu', function (e) {
        e.preventDefault();
        var fileRow = e.target.closest('.ide-tree-file');
        var folderHeader = e.target.closest('.ide-tree-folder-header');

        if (fileRow) {
            // Find the path from the file name
            var nameEl = fileRow.querySelector('.ide-file-name');
            ctxTarget = nameEl ? findPathByName(nameEl.textContent) : null;
        } else if (folderHeader) {
            var folderName = folderHeader.querySelector('.ide-folder-name');
            ctxTarget = folderName ? folderName.textContent : null;
        } else {
            ctxTarget = null; // root
        }

        ctxMenu.style.display = 'block';
        ctxMenu.style.left = Math.min(e.clientX, window.innerWidth - 220) + 'px';
        ctxMenu.style.top = Math.min(e.clientY, window.innerHeight - 200) + 'px';
    });

    function findPathByName(name) {
        var files = Object.keys(S.project ? S.project.files : {});
        for (var i = 0; i < files.length; i++) {
            if (files[i].split('/').pop() === name) return files[i];
        }
        return null;
    }

    // Handle context menu actions
    ctxMenu.addEventListener('click', async function (e) {
        var item = e.target.closest('[data-ctx]');
        if (!item) return;
        ctxMenu.style.display = 'none';
        var action = item.dataset.ctx;

        if (action === 'new-file') {
            var prefix = (ctxTarget && !ctxTarget.includes('.')) ? ctxTarget + '/' : (S.selectedFolder ? S.selectedFolder + '/' : '');
            $('input-file-path').value = prefix;
            var hint = $('new-file-hint');
            if (hint) hint.textContent = prefix ? 'Criando dentro de: ' + prefix : 'Criando na raiz do módulo';
            showModal('modal-new-file');
            setTimeout(function () { var inp = $('input-file-path'); inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }, 100);
        }
        else if (action === 'new-folder') {
            var folderPrefix = (ctxTarget && !ctxTarget.includes('.')) ? ctxTarget + '/' : (S.selectedFolder ? S.selectedFolder + '/' : '');
            $('input-folder-path').value = '';
            var fhint = $('new-folder-hint');
            if (fhint) fhint.textContent = folderPrefix ? 'Criando dentro de: ' + folderPrefix : 'Criando na raiz do módulo';
            S._ctxFolderPrefix = folderPrefix;
            showModal('modal-new-folder');
            setTimeout(function () { $('input-folder-path').focus(); }, 100);
        }
        else if (action === 'rename' && ctxTarget) {
            var oldName = ctxTarget.split('/').pop();
            var newName = await ideInput({ title: 'Renomear', icon: 'fa-solid fa-pen', label: 'Novo nome:', value: oldName, okText: 'Renomear', okIcon: 'fa-solid fa-pen' });
            if (!newName || newName === oldName) return;
            var newPath = ctxTarget.replace(/[^/]+$/, newName);
            var content = S.project.files[ctxTarget] || '';
            try {
                await api('PUT', '/api/ide/projects/' + S.project.id + '/files', { path: newPath, content: content });
                await api('DELETE', '/api/ide/projects/' + S.project.id + '/files', { path: ctxTarget });
                S.project.files[newPath] = content;
                delete S.project.files[ctxTarget];
                var tab = S.tabs.find(function (t) { return t.path === ctxTarget; });
                if (tab) { tab.path = newPath; if (S.activeTab === ctxTarget) S.activeTab = newPath; }
                renderFileTree(); renderTabs();
                toast('Renomeado para ' + newName);
            } catch (e) { toast('Erro: ' + e.message); }
        }
        else if (action === 'copy' && ctxTarget) {
            var copyName = await ideInput({ title: 'Copiar arquivo', icon: 'fa-solid fa-copy', label: 'Nome da cópia:', value: 'Copy_' + ctxTarget.split('/').pop(), okText: 'Copiar', okIcon: 'fa-solid fa-copy' });
            if (!copyName) return;
            var copyPath = ctxTarget.replace(/[^/]+$/, copyName);
            var copyContent = S.project.files[ctxTarget] || '';
            try {
                await api('PUT', '/api/ide/projects/' + S.project.id + '/files', { path: copyPath, content: copyContent });
                S.project.files[copyPath] = copyContent;
                renderFileTree();
                toast('Copiado');
            } catch (e) { toast('Erro: ' + e.message); }
        }
        else if (action === 'delete' && ctxTarget) {
            confirmDeleteFile(ctxTarget);
        }
    });
})();

// ── Code Generation (Editor Context Menu) ─────────────────────────────────
(function initCodeGen() {
    var codeMenu = $('ide-code-menu');

    document.addEventListener('click', function () { codeMenu.style.display = 'none'; });

    // Show on right-click in editor area
    $('ide-editor-container').addEventListener('contextmenu', function (e) {
        if (!S.activeTab || !S.activeTab.endsWith('.php') || !S.editor) return;
        // Only show if right-clicking inside the Monaco editor
        if (!e.target.closest('#monaco-editor') && !e.target.closest('#split-left') && !e.target.closest('#split-right')) return;
        e.preventDefault();
        codeMenu.style.display = 'block';
        codeMenu.style.left = Math.min(e.clientX, window.innerWidth - 280) + 'px';
        codeMenu.style.top = Math.min(e.clientY, window.innerHeight - 350) + 'px';
    });

    codeMenu.addEventListener('click', function (e) {
        var item = e.target.closest('[data-gen]');
        if (!item || !S.editor) return;
        codeMenu.style.display = 'none';
        var gen = item.dataset.gen;
        var code = S.editor.getValue();
        var pos = S.editor.getPosition();
        var ns = S.project ? 'Src\\Modules\\' + S.project.module_name : 'App';
        var className = extractClassName(code);
        var properties = extractProperties(code);
        var snippet = '';

        if (gen === 'constructor') {
            snippet = generateConstructor(properties);
        } else if (gen === 'getters-setters') {
            snippet = generateGettersSetters(properties);
        } else if (gen === 'toString') {
            snippet = generateToString(className, properties);
        } else if (gen === 'equals') {
            snippet = generateEquals(className, properties);
        } else if (gen === 'interface') {
            snippet = generateInterfaceImpl(className);
        } else if (gen === 'crud-methods') {
            snippet = generateCrudMethods(className);
        } else if (gen === 'validation') {
            snippet = generateValidation(properties);
        }

        if (snippet) {
            // Insert before the last closing brace
            var lines = code.split('\n');
            var insertLine = lines.length;
            for (var i = lines.length - 1; i >= 0; i--) {
                if (lines[i].trim() === '}') { insertLine = i + 1; break; }
            }
            S.editor.executeEdits('code-gen', [{
                range: new monaco.Range(insertLine, 1, insertLine, 1),
                text: '\n' + snippet + '\n'
            }]);
            S.editor.revealLineInCenter(insertLine + 2);
            toast('Código gerado');
        }
    });

    function extractClassName(code) {
        var m = code.match(/class\s+(\w+)/);
        return m ? m[1] : 'MyClass';
    }

    function extractProperties(code) {
        var props = [];
        var regex = /(?:private|protected|public)\s+(?:readonly\s+)?(?:(\?\w+|\w+)\s+)?\$(\w+)/g;
        var m;
        while ((m = regex.exec(code)) !== null) {
            props.push({ type: m[1] || 'mixed', name: m[2] });
        }
        return props;
    }

    function ucfirst(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

    function generateConstructor(props) {
        if (!props.length) return '    public function __construct()\n    {\n    }';
        var params = props.map(function (p) { return '        ' + (p.type !== 'mixed' ? p.type + ' ' : '') + '$' + p.name; }).join(',\n');
        var assigns = props.map(function (p) { return '        $this->' + p.name + ' = $' + p.name + ';'; }).join('\n');
        return '    public function __construct(\n' + params + '\n    ) {\n' + assigns + '\n    }';
    }

    function generateGettersSetters(props) {
        return props.map(function (p) {
            var type = p.type !== 'mixed' ? ': ' + p.type : '';
            var getter = '    public function get' + ucfirst(p.name) + '()' + type + '\n    {\n        return $this->' + p.name + ';\n    }';
            var setter = '    public function set' + ucfirst(p.name) + '(' + (p.type !== 'mixed' ? p.type + ' ' : '') + '$' + p.name + '): self\n    {\n        $this->' + p.name + ' = $' + p.name + ';\n        return $this;\n    }';
            return getter + '\n\n' + setter;
        }).join('\n\n');
    }

    function generateToString(cls, props) {
        var fields = props.map(function (p) { return "'" + p.name + "' => $this->" + p.name; }).join(', ');
        return '    public function __toString(): string\n    {\n        return \'' + cls + '(\' . json_encode([' + fields + ']) . \')\';\n    }';
    }

    function generateEquals(cls, props) {
        var checks = props.map(function (p) { return '$this->' + p.name + ' === $other->' + p.name; }).join('\n            && ');
        return '    public function equals(self $other): bool\n    {\n        return ' + (checks || 'true') + ';\n    }';
    }

    function generateInterfaceImpl(cls) {
        return '    // TODO: Implemente os métodos da interface\n    // Use Ctrl+. no VS Code para gerar automaticamente';
    }

    function generateCrudMethods(cls) {
        var lower = cls.charAt(0).toLowerCase() + cls.slice(1);
        return '    public function listar(): array\n    {\n        // TODO: implementar listagem\n        return [];\n    }\n\n' +
            '    public function criar(array $data): array\n    {\n        // TODO: implementar criação\n        return $data;\n    }\n\n' +
            '    public function buscar(string $id): ?array\n    {\n        // TODO: implementar busca\n        return null;\n    }\n\n' +
            '    public function atualizar(string $id, array $data): void\n    {\n        // TODO: implementar atualização\n    }\n\n' +
            '    public function deletar(string $id): void\n    {\n        // TODO: implementar exclusão\n    }';
    }

    function generateValidation(props) {
        if (!props.length) return '    // Nenhuma propriedade encontrada para validar';
        var checks = props.map(function (p) {
            if (p.type === 'string' || p.type === '?string') {
                return "        if (empty(\\$data['" + p.name + "'] ?? '')) {\n            throw new \\InvalidArgumentException('Campo \"" + p.name + "\" é obrigatório.');\n        }";
            }
            return "        // Validar: " + p.name + " (" + p.type + ")";
        }).join('\n');
        return '    public static function validate(array $data): void\n    {\n' + checks + '\n    }';
    }
})();

// ── Deploy Panel ──────────────────────────────────────────────────────────
async function renderDeployPanel() {
    const c = $('ide-deploy-content');
    c.textContent = '';

    if (!S.project) {
        c.appendChild(domEl('div', 'ide-empty-state', [domEl('p', null, ['Nenhum projeto'])]));
        return;
    }

    c.appendChild(domEl('div', 'ide-empty-state', [domIcon('fa-solid fa-spinner fa-spin')]));

    var status = null;
    try {
        var d = await api('GET', '/api/ide/projects/' + S.project.id + '/status');
        status = d.status;
    } catch (e) {
        status = { deployed: false, enabled: false, tables: [], pending_migrations: [], pending_seeders: [] };
    }

    S.moduleStatus = status;
    c.textContent = '';

    var deployed = status.deployed;
    var enabled  = status.enabled;
    var tables   = status.tables || [];
    var pendingM = status.pending_migrations || [];
    var pendingS = status.pending_seeders || [];
    var fc       = Object.keys(S.project.files || {}).length;

    // ── Seção: Módulo ──
    var sec1 = domEl('div', 'dep-section');
    sec1.appendChild(domEl('div', 'dep-section-title', [domIcon('fa-solid fa-cube'), ' Módulo']));

    var info = domEl('div', 'dep-info');
    info.appendChild(domEl('strong', null, [S.project.name]));
    var modSpan = domEl('span'); modSpan.appendChild(document.createTextNode('Módulo: '));
    modSpan.appendChild(domEl('code', null, [S.project.module_name]));
    info.appendChild(modSpan);
    info.appendChild(domEl('span', null, [fc + ' arquivo' + (fc !== 1 ? 's' : '')]));
    sec1.appendChild(info);

    if (!deployed) {
        sec1.appendChild(domBtn('ide-deploy-btn primary', 'fa-solid fa-rocket', 'Publicar em src/Modules/', 'deploy'));
        sec1.appendChild(domBtn('ide-deploy-btn dep-btn-ok', 'fa-solid fa-magnifying-glass-chart', 'Analisar código', 'analyze'));
    } else {
        var badge = domEl('div', 'dep-status-badge');
        badge.style.color = enabled ? '#a6e3a1' : '#f38ba8';
        badge.appendChild(domIcon('fa-solid ' + (enabled ? 'fa-circle-check' : 'fa-circle-xmark')));
        badge.appendChild(document.createTextNode(' ' + (enabled ? 'Ativo' : 'Inativo')));
        var loc = domEl('span', null, [' em src/Modules/' + S.project.module_name]);
        loc.style.cssText = 'color:#6c7086;font-size:.8rem;margin-left:4px;';
        badge.appendChild(loc);
        sec1.appendChild(badge);

        var row = domEl('div'); row.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;';
        row.appendChild(domBtn('ide-deploy-btn ' + (enabled ? 'dep-btn-warn' : 'dep-btn-ok'),
            'fa-solid ' + (enabled ? 'fa-power-off' : 'fa-play'),
            enabled ? 'Desativar' : 'Ativar', 'toggle', { enabled: String(!enabled) }));
        row.appendChild(domBtn('ide-deploy-btn dep-btn-danger', 'fa-solid fa-trash', 'Excluir Projeto', 'remove-module'));
        sec1.appendChild(row);

        var b1 = domBtn('ide-deploy-btn', 'fa-solid fa-arrows-rotate', 'Atualizar Arquivos', 'deploy');
        b1.style.marginTop = '4px'; sec1.appendChild(b1);
        var b2 = domBtn('ide-deploy-btn dep-btn-ok', 'fa-solid fa-magnifying-glass-chart', 'Analisar código', 'analyze');
        b2.style.marginTop = '4px'; sec1.appendChild(b2);
    }
    c.appendChild(sec1);

    // ── Seção: Banco de Dados ──
    if (deployed) {
        var sec2 = domEl('div', 'dep-section');
        sec2.appendChild(domEl('div', 'dep-section-title', [domIcon('fa-solid fa-database'), ' Banco de Dados']));

        if (pendingM.length > 0) {
            var pm = domEl('div', 'dep-pending', [domIcon('fa-solid fa-triangle-exclamation', '#f9e2af'), domEl('span', null, [pendingM.length + ' migration(s) pendente(s)'])]);
            sec2.appendChild(pm);
            sec2.appendChild(domBtn('ide-deploy-btn dep-btn-ok', 'fa-solid fa-play', 'Rodar Migrations', 'migrate'));
        } else {
            sec2.appendChild(domEl('div', 'dep-ok', [domIcon('fa-solid fa-check'), ' Migrations em dia']));
        }

        if (pendingS.length > 0) {
            var ps = domEl('div', 'dep-pending', [domIcon('fa-solid fa-triangle-exclamation', '#f9e2af'), domEl('span', null, [pendingS.length + ' seeder(s) pendente(s)'])]);
            ps.style.marginTop = '6px'; sec2.appendChild(ps);
            var sb = domBtn('ide-deploy-btn', 'fa-solid fa-seedling', 'Rodar Seeders', 'seed');
            sb.style.marginTop = '4px'; sec2.appendChild(sb);
        } else if (tables.length > 0) {
            var sok = domEl('div', 'dep-ok', [domIcon('fa-solid fa-check'), ' Seeders em dia']);
            sok.style.marginTop = '4px'; sec2.appendChild(sok);
        }

        if (tables.length > 0) {
            var tt = domEl('div', 'dep-tables-title', ['Tabelas do módulo']);
            tt.style.cssText = 'margin-top:12px;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6c7086;';
            sec2.appendChild(tt);
            var tw = domEl('div', 'dep-tables');
            tables.forEach(function (t) {
                tw.appendChild(domEl('div', 'dep-table-row', [domIcon('fa-solid fa-table', '#89b4fa'), domEl('span', null, [t])]));
            });
            sec2.appendChild(tw);
            var dtb = domBtn('ide-deploy-btn dep-btn-danger', 'fa-solid fa-trash', 'Remover Tabelas', 'drop-tables');
            dtb.style.marginTop = '8px'; sec2.appendChild(dtb);
        } else {
            var nt = domEl('div', null, ['Nenhuma tabela criada ainda.']);
            nt.style.cssText = 'font-size:.82rem;color:#6c7086;margin-top:8px;';
            sec2.appendChild(nt);
        }
        c.appendChild(sec2);
    }

    // ── Seção: Conectar App Externa ──
    var sec4 = domEl('div', 'dep-section');
    sec4.appendChild(domEl('div', 'dep-section-title', [domIcon('fa-solid fa-globe'), ' Conectar App Externa']));
    var corsInfo = domEl('div', 'dep-cors-info');
    corsInfo.appendChild(domEl('p', null, ['Para conectar uma aplicação frontend externa a este módulo:']));
    var corsSteps = domEl('ol', 'dep-cors-steps');
    corsSteps.appendChild(domEl('li', null, ['Solicite ao suporte da Vupi.us API a liberação no CORS da URL do frontend da sua aplicação.']));
    corsSteps.appendChild(domEl('li', null, ['O admin adicionará a URL via Dashboard > Configurações > CORS']));
    corsSteps.appendChild(domEl('li', null, ['Após aprovação, sua aplicação poderá fazer requisições à API da Vupi.us API e utilizar as rotas do seu(s) módulo(s).']));
    corsInfo.appendChild(corsSteps);
    sec4.appendChild(corsInfo);
    c.appendChild(sec4);

    // ── Seção: Navegação ──
    var sec3 = domEl('div', 'dep-section');
    sec3.appendChild(domBtn('ide-deploy-btn', 'fa-solid fa-floppy-disk', 'Salvar Tudo', 'save-all'));
    sec3.appendChild(domBtn('ide-deploy-btn', 'fa-solid fa-folder-open', 'Todos os Projetos', 'go-projects'));
    c.appendChild(sec3);
}

// ── Deploy panel event delegation ─────────────────────────────────────────
$('ide-deploy-content').addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.dataset.action;
    if (action === 'deploy')        doDeploy();
    else if (action === 'analyze')  doAnalyze();
    else if (action === 'toggle')   doToggleModule(btn.dataset.enabled === 'true');
    else if (action === 'remove-module') doRemoveModule();
    else if (action === 'migrate')  doMigrate();
    else if (action === 'seed')     doSeed();
    else if (action === 'drop-tables') doDropTables();
    else if (action === 'save-all') saveAll();
    else if (action === 'go-projects') window.location.href = '/dashboard/ide';
});

// ── Deploy Actions ────────────────────────────────────────────────────────
async function doDeploy() {
    if (!S.project) return;
    await saveAll();
    const btn = event?.target?.closest('button');
    var origText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Analisando...'); }
    try {
        const data = await api('POST', `/api/ide/projects/${S.project.id}/deploy`, { target: 'local' });
        if (data.error) { toast('Erro: ' + data.error); return; }
        if (data.analysis && (data.analysis.warning_count > 0 || data.analysis.info_count > 0)) {
            showAnalysisResult(data.analysis, () => {
                showDeploySuccess(data);
                renderDeployPanel();
            });
        } else {
            showDeploySuccess(data);
            await renderDeployPanel();
        }
    } catch (e) {
        if (e.data?.analysis) {
            showAnalysisResult(e.data.analysis, null);
            return;
        }
        toast('Erro: ' + e.message);
    } finally {
        if (btn) { btn.disabled = false; renderDeployPanel(); }
    }
}

async function doAnalyze() {
    if (!S.project) return;
    await saveAll();
    const btn = event?.target?.closest('button');
    if (btn) { btn.disabled = true; setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Analisando...'); }
    try {
        const data = await api('POST', `/api/ide/projects/${S.project.id}/analyze`);
        showAnalysisResult(data.analysis, null);
    } catch (e) {
        toast('Erro na análise: ' + e.message);
    } finally {
        if (btn) { btn.disabled = false; renderDeployPanel(); }
    }
}

function showAnalysisResult(analysis, onSuccess) {
    var title = document.getElementById('deploy-result-title');
    var body  = document.getElementById('deploy-result-body');
    if (!title || !body) return;

    var canDeploy = analysis.can_deploy;
    var errors    = (analysis.issues || []).filter(function (i) { return i.severity === 'error'; });
    var warnings  = (analysis.issues || []).filter(function (i) { return i.severity === 'warning'; });
    var infos     = (analysis.issues || []).filter(function (i) { return i.severity === 'info'; });

    if (canDeploy && onSuccess) {
        onSuccess();
        if (warnings.length === 0 && infos.length === 0) return;
    }

    if (canDeploy) {
        if (warnings.length) setTitleIcon(title, 'fa-solid fa-triangle-exclamation', '#f9e2af', 'Publicado com avisos');
        else setTitleIcon(title, 'fa-solid fa-check-circle', '#a6e3a1', 'Módulo aprovado');
    } else {
        setTitleIcon(title, 'fa-solid fa-circle-xmark', '#f38ba8', 'Publicação bloqueada');
    }

    body.textContent = '';

    // Summary
    var sumCls = canDeploy ? (warnings.length ? 'analysis-warn' : 'analysis-ok') : 'analysis-error';
    var sumIcon = canDeploy ? (warnings.length ? 'fa-triangle-exclamation' : 'fa-check') : 'fa-xmark';
    body.appendChild(domEl('div', 'analysis-summary ' + sumCls, [domIcon('fa-solid ' + sumIcon), domEl('span', null, [analysis.summary])]));

    // Counters
    if (analysis.total_issues > 0) {
        var counters = domEl('div', 'analysis-counters');
        if (errors.length) counters.appendChild(domEl('span', 'analysis-count analysis-count-error', [domIcon('fa-solid fa-circle-xmark'), ' ' + errors.length + ' erro' + (errors.length !== 1 ? 's' : '')]));
        if (warnings.length) counters.appendChild(domEl('span', 'analysis-count analysis-count-warn', [domIcon('fa-solid fa-triangle-exclamation'), ' ' + warnings.length + ' aviso' + (warnings.length !== 1 ? 's' : '')]));
        if (infos.length) counters.appendChild(domEl('span', 'analysis-count analysis-count-info', [domIcon('fa-solid fa-circle-info'), ' ' + infos.length + ' sugestão' + (infos.length !== 1 ? 'ões' : '')]));
        body.appendChild(counters);
    }

    // Issues
    if (analysis.issues && analysis.issues.length) {
        var issuesWrap = domEl('div', 'analysis-issues');
        analysis.issues.forEach(function (issue) {
            var iconCls = issue.severity === 'error' ? 'fa-circle-xmark' : issue.severity === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-info';
            var issueCls = 'analysis-issue analysis-issue-' + issue.severity;

            var header = domEl('div', 'analysis-issue-header', [domIcon('fa-solid ' + iconCls), domEl('span', 'analysis-issue-code', [issue.code])]);
            if (issue.file) {
                header.appendChild(domEl('span', 'analysis-loc', [issue.file + (issue.line ? ':' + issue.line : '')]));
            }

            var issueEl = domEl('div', issueCls, [
                header,
                domEl('div', 'analysis-issue-msg', [issue.message]),
                domEl('div', 'analysis-issue-sug', [domIcon('fa-solid fa-lightbulb'), ' ' + issue.suggestion])
            ]);
            issuesWrap.appendChild(issueEl);
        });
        body.appendChild(issuesWrap);
    }

    // Autofix button
    var fixableCodes = ['MISSING_UP','MISSING_DOWN','NO_DRIVER_CHECK','NON_IDEMPOTENT_MIGRATION',
        'MISSING_NAMESPACE','WRONG_NAMESPACE','UNPROTECTED_WRITE_ROUTE',
        'SHELL_EXECUTION','EVAL_USAGE','DIRECT_HEADER','DIE_EXIT','DIE_EXIT_IN_CONTROLLER',
        'INVALID_CONNECTION','DYNAMIC_INCLUDE','ENV_FILE_ACCESS','PATH_TRAVERSAL',
        'SENSITIVE_SERVER_ACCESS','POTENTIAL_SQL_INJECTION'];
    var fixableCount = (analysis.issues || []).filter(function (i) { return fixableCodes.indexOf(i.code) !== -1; }).length;

    S.pendingIssues = analysis.issues || [];

    if (fixableCount > 0) {
        var fixBtn = domBtn('ide-deploy-btn primary', 'fa-solid fa-wand-magic-sparkles',
            'Corrigir automaticamente (' + fixableCount + ' problema' + (fixableCount !== 1 ? 's' : '') + ')', 'autofix');
        fixBtn.id = 'btn-autofix-pending';
        fixBtn.style.marginTop = '10px';
        body.appendChild(fixBtn);
    }

    if (!canDeploy && errors[0] && errors[0].file) {
        var gotoBtn = domBtn('ide-deploy-btn dep-btn-ok', 'fa-solid fa-arrow-right',
            'Ir para ' + errors[0].file.split('/').pop(), 'goto-file', { file: errors[0].file });
        gotoBtn.style.marginTop = '8px';
        body.appendChild(gotoBtn);
    }

    showModal('modal-deploy-result');
}

async function doAutoFix() {
    if (!S.project) return;
    // NÃO chama saveAll() antes — evita sobrescrever arquivos com conteúdo antigo do editor
    const btn = document.getElementById('btn-autofix-pending');
    if (btn) { btn.disabled = true; setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Corrigindo...'); }
    try {
        // Sem issues no body — backend analisa e corrige tudo automaticamente
        const data = await api('POST', `/api/ide/projects/${S.project.id}/autofix`, {});
        if (data.files) {
            for (const [path, content] of Object.entries(data.files)) {
                S.project.files[path] = content;
                const tab = S.tabs.find(t => t.path === path);
                if (tab) {
                    tab.content  = content;
                    tab.modified = false;
                    if (S.activeTab === path) loadEditor(path, content);
                }
            }
            renderFileTree();
            renderTabs();
        }
        hideModal('modal-deploy-result');
        const applied = data.applied || [];
        const skipped = data.skipped || [];
        const n = applied.length;
        const s = skipped.length;
        let msg = n > 0
            ? `✓ ${n} ${n === 1 ? 'correção aplicada' : 'correções aplicadas'}.`
            : 'Nenhuma correção automática disponível.';
        if (s > 0) msg += ` ${s} ${s === 1 ? 'problema requer' : 'problemas requerem'} correção manual.`;
        toast(msg, 4000);
        if (data.analysis) {
            S.pendingIssues = data.analysis.issues || [];
            setTimeout(() => showAnalysisResult(data.analysis, null), 400);
        }
    } catch (e) {
        toast('Erro no autofix: ' + e.message);
        if (btn) { btn.disabled = false; setBtn(btn, 'fa-solid fa-wand-magic-sparkles', 'Corrigir automaticamente'); }
    }
}

function showDeploySuccess(data) {
    var title = document.getElementById('deploy-result-title');
    var body  = document.getElementById('deploy-result-body');
    if (!title || !body) return;

    setTitleIcon(title, 'fa-solid fa-check-circle', '#a6e3a1', 'Módulo publicado');
    body.textContent = '';

    body.appendChild(domEl('div', 'analysis-summary analysis-ok', [
        domIcon('fa-solid fa-check'),
        domEl('span', null, [data.files + ' arquivo(s) copiado(s) para ' + (data.path || '')])
    ]));

    var mig = data.migrations;
    if (mig) {
        if (mig.ran && mig.ran.length) {
            var migOk = domEl('div', 'dep-ok', [domIcon('fa-solid fa-database'), ' ' + mig.ran.length + ' migration(s) executada(s): ' + mig.ran.join(', ')]);
            migOk.style.marginTop = '8px'; body.appendChild(migOk);
        } else if (!mig.error) {
            var migDone = domEl('div', 'dep-ok', [domIcon('fa-solid fa-check'), ' Migrations já estavam em dia.']);
            migDone.style.marginTop = '8px'; body.appendChild(migDone);
        }
        if (mig.errors && mig.errors.length) {
            var migErr = domEl('div', 'dep-pending', [domIcon('fa-solid fa-triangle-exclamation'), ' Erros nas migrations: ' + mig.errors.join('; ')]);
            migErr.style.marginTop = '4px'; body.appendChild(migErr);
        }
    }

    var seed = data.seeders;
    if (seed && seed.ran && seed.ran.length) {
        var seedOk = domEl('div', 'dep-ok', [domIcon('fa-solid fa-seedling'), ' ' + seed.ran.length + ' seeder(s) executado(s): ' + seed.ran.join(', ')]);
        seedOk.style.marginTop = '4px'; body.appendChild(seedOk);
    }

    showModal('modal-deploy-result');
}

async function doMigrate() {
    if (!S.project) return;
    const btn = event?.target?.closest('button');
    if (btn) { btn.disabled = true; setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Rodando...'); }
    try {
        const data = await api('POST', `/api/ide/projects/${S.project.id}/migrate`);
        if (data.error) { toast('Erro: ' + data.error); return; }
        const msg = data.errors?.length
            ? `⚠ ${data.message} Erros: ${data.errors.join('; ')}`
            : `✓ ${data.message}`;
        toast(msg, 4000);
        await renderDeployPanel();
    } catch (e) {
        toast('Erro: ' + e.message);
    } finally {
        if (btn) { btn.disabled = false; }
    }
}

async function doSeed() {
    if (!S.project) return;
    const btn = event?.target?.closest('button');
    if (btn) { btn.disabled = true; setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Rodando...'); }
    try {
        const data = await api('POST', `/api/ide/projects/${S.project.id}/seed`);
        if (data.error) { toast('Erro: ' + data.error); return; }
        toast(`✓ ${data.message}`, 3000);
        await renderDeployPanel();
    } catch (e) {
        toast('Erro: ' + e.message);
    } finally {
        if (btn) { btn.disabled = false; }
    }
}

async function doToggleModule(enable) {
    if (!S.project) return;
    var ok = await ideConfirm({
        title: enable ? 'Ativar módulo' : 'Desativar módulo',
        icon: enable ? 'fa-solid fa-play' : 'fa-solid fa-power-off',
        message: (enable ? 'Ativar' : 'Desativar') + ' o módulo ' + S.project.module_name + '?',
        okText: enable ? 'Ativar' : 'Desativar',
        okIcon: enable ? 'fa-solid fa-play' : 'fa-solid fa-power-off',
    });
    if (!ok) return;
    try {
        await api('PATCH', `/api/ide/projects/${S.project.id}/module`, { enabled: enable });
        toast(`✓ Módulo ${enable ? 'ativado' : 'desativado'}`);
        await renderDeployPanel();
    } catch (e) { toast('Erro: ' + e.message); }
}

async function doRemoveModule() {
    if (!S.project) return;
    var moduleName = S.project.module_name;
    var tables = (S.moduleStatus?.tables || []).join(', ') || 'nenhuma';

    var ok = await ideConfirm({
        title: 'Excluir Projeto Permanentemente',
        icon: 'fa-solid fa-triangle-exclamation',
        message: 'Excluir completamente o projeto "' + S.project.name + '" e o módulo ' + moduleName + '?',
        detail: 'Serão apagados: todos os arquivos do projeto, a pasta src/Modules/' + moduleName + '/, tabelas do banco (' + tables + '), rotas e configurações. Esta ação não pode ser desfeita.',
        okText: 'Excluir tudo permanentemente',
        okIcon: 'fa-solid fa-trash',
        danger: true,
    });
    if (!ok) return;
    try {
        var data = await api('DELETE', '/api/ide/projects/' + S.project.id);
        var msg = 'Projeto excluído completamente.';
        if (data.tables_dropped && data.tables_dropped.length) {
            msg += ' ' + data.tables_dropped.length + ' tabela(s) removida(s).';
        }
        toast(msg, 4000);
        // Redireciona para a página de projetos
        setTimeout(function () { window.location.href = '/dashboard/ide'; }, 1500);
    } catch (e) { toast('Erro: ' + e.message); }
}

async function doDropTables() {
    if (!S.project) return;
    var tables = (S.moduleStatus?.tables || []).join(', ') || 'nenhuma';
    var ok = await ideConfirm({
        title: 'Remover tabelas',
        icon: 'fa-solid fa-database',
        message: 'Remover as tabelas do módulo ' + S.project.module_name + '?',
        detail: 'Tabelas: ' + tables + '. Esta ação não pode ser desfeita.',
        okText: 'Remover tabelas',
        okIcon: 'fa-solid fa-trash',
        danger: true,
    });
    if (!ok) return;
    try {
        const data = await api('DELETE', `/api/ide/projects/${S.project.id}/tables`);
        const msg = data.errors?.length
            ? `⚠ ${data.message}`
            : `✓ ${data.message}`;
        toast(msg, 4000);
        await renderDeployPanel();
    } catch (e) { toast('Erro: ' + e.message); }
}

$('btn-deploy-top').addEventListener('click', () => {
    showModal('modal-deploy');
});

$('modal-dep-cancel').addEventListener('click', () => hideModal('modal-deploy'));
$('modal-dep-close').addEventListener('click', () => hideModal('modal-deploy'));

$('modal-dep-confirm').addEventListener('click', async () => {
    if (!S.project) return;
    await saveAll();
    const body = {
        target:      'packagist',
        vendor:      $('input-vendor').value.trim() || 'vupi-modules',
        package:     $('input-package').value.trim() || S.project.module_name.toLowerCase(),
        version:     $('input-version').value.trim() || '1.0.0',
        description: $('input-description').value.trim()
    };
    const btn = $('modal-dep-confirm');
    btn.disabled = true;
    setBtn(btn, 'fa-solid fa-spinner fa-spin', 'Publicando...');
    try {
        const data = await api('POST', `/api/ide/projects/${S.project.id}/deploy`, body);
        hideModal('modal-deploy');
        showDeployResult(data);
    } catch (e) {
        toast('Erro: ' + e.message);
    } finally {
        btn.disabled = false;
        setBtn(btn, 'fa-brands fa-php', 'Publicar');
    }
});

$('modal-dr-ok').addEventListener('click', () => hideModal('modal-deploy-result'));
$('modal-dr-close').addEventListener('click', () => hideModal('modal-deploy-result'));

// Event delegation for deploy result modal buttons
$('deploy-result-body').addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.dataset.action;
    if (action === 'autofix') doAutoFix();
    else if (action === 'goto-file') { hideModal('modal-deploy-result'); openFile(btn.dataset.file); }
});

function showDeployResult(data) {
    if (data.target === 'packagist') {
        var title = $('deploy-result-title');
        var body  = $('deploy-result-body');
        setTitleIcon(title, 'fa-solid fa-info-circle', '#89dceb', 'Instruções para Packagist');
        body.textContent = '';

        var wrap = domEl('div');
        wrap.style.cssText = 'background:rgba(255,255,255,.04);border-radius:10px;padding:16px;font-size:.9rem;color:#94a3b8;line-height:1.7;';

        var pkgP = domEl('p', null, ['Pacote: ']);
        pkgP.style.marginBottom = '10px';
        var pkgCode = domEl('code', null, [data.package_name || '']);
        pkgCode.style.cssText = 'background:rgba(255,255,255,.08);padding:2px 7px;border-radius:4px;color:#818cf8;';
        pkgP.appendChild(pkgCode);
        wrap.appendChild(pkgP);

        var ol = document.createElement('ol');
        ol.style.cssText = 'margin:0 0 0 18px;';
        (data.instructions || []).forEach(function (step) {
            var li = document.createElement('li');
            li.style.marginBottom = '8px';
            li.textContent = step;
            ol.appendChild(li);
        });
        wrap.appendChild(ol);

        var note = domEl('p', null, ['O composer.json foi salvo no projeto IDE.']);
        note.style.cssText = 'margin-top:12px;font-size:.82rem;color:#6c7086;';
        wrap.appendChild(note);

        body.appendChild(wrap);
    }
    showModal('modal-deploy-result');
}

// ── Panel Toggle ──────────────────────────────────────────────────────────
function togglePanel(panelId, iconId, isLeft) {
    const panel = $(panelId);
    const icon = $(iconId);
    const isMobile = window.innerWidth <= 600;

    if (isMobile) {
        panel.classList.toggle('mobile-open');
        const overlay = $('ide-mobile-overlay');
        if (overlay) overlay.classList.toggle('show', panel.classList.contains('mobile-open'));
        return;
    }

    panel.classList.toggle('collapsed');
    const collapsed = panel.classList.contains('collapsed');
    if (icon) {
        if (isLeft) icon.className = collapsed ? 'fa-solid fa-chevron-right' : 'fa-solid fa-chevron-left';
        else icon.className = collapsed ? 'fa-solid fa-chevron-left' : 'fa-solid fa-chevron-right';
    }
    const btn = panel.querySelector('.ide-panel-toggle');
    if (btn) btn.setAttribute('aria-expanded', String(!collapsed));
}

$('toggle-files').addEventListener('click', () => togglePanel('panel-files', 'toggle-files-icon', true));
$('toggle-deploy').addEventListener('click', () => togglePanel('panel-deploy', 'toggle-deploy-icon', false));

// Mobile overlay
const mobileOverlay = document.createElement('div');
mobileOverlay.id = 'ide-mobile-overlay';
mobileOverlay.className = 'ide-mobile-overlay';
mobileOverlay.addEventListener('click', () => {
    $('panel-files')?.classList.remove('mobile-open');
    $('panel-deploy')?.classList.remove('mobile-open');
    mobileOverlay.classList.remove('show');
});
document.body.appendChild(mobileOverlay);

// ── Theme ─────────────────────────────────────────────────────────────────
$('btn-theme').addEventListener('click', () => {
    const dark = document.body.classList.toggle('dark');
    localStorage.setItem('dash-dark-mode', dark ? '1' : '0');
    $('theme-icon').className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    if (S.editor) monaco.editor.setTheme(dark ? 'vs-dark' : 'vs');
});

// ── Modal overlay close ───────────────────────────────────────────────────
document.querySelectorAll('.ide-modal-overlay').forEach(o => {
    o.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('show'); });
});

// ── Keyboard shortcuts ────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); autoSave(); toast('Salvo'); }
    if ((e.ctrlKey || e.metaKey) && e.key === 'w') { e.preventDefault(); if (S.activeTab) closeTab(S.activeTab); }
    if ((e.ctrlKey || e.metaKey) && e.key === 'b') { e.preventDefault(); togglePanel('panel-files', 'toggle-files-icon', true); }
});

// ── Responsive: collapse deploy on small screens ──────────────────────────
(function initResponsive() {
    if (window.innerWidth <= 1024) {
        const panel = $('panel-deploy');
        const icon = $('toggle-deploy-icon');
        if (panel) { panel.classList.add('collapsed'); if (icon) icon.className = 'fa-solid fa-chevron-left'; }
    }
})();

// ══════════════════════════════════════════════════════════════════════════
// Layout Persistence
// ══════════════════════════════════════════════════════════════════════════
var LAYOUT_KEY = 'ide-layout-v1';
function saveLayout() {
    var d = { filesW: $('panel-files').style.width, deployW: $('panel-deploy').style.width, termH: $('ide-terminal-panel').style.height, termOpen: $('ide-terminal-panel').style.display !== 'none' };
    try { localStorage.setItem(LAYOUT_KEY, JSON.stringify(d)); } catch(e) {}
}
function restoreLayout() {
    try { var d = JSON.parse(localStorage.getItem(LAYOUT_KEY) || 'null'); if (!d) return; if (d.filesW) $('panel-files').style.width = d.filesW; if (d.deployW) $('panel-deploy').style.width = d.deployW; if (d.termH) $('ide-terminal-panel').style.height = d.termH; if (d.termOpen) $('ide-terminal-panel').style.display = 'flex'; } catch(e) {}
}
restoreLayout();

// Terminal & Debug — Multi-tab
// ══════════════════════════════════════════════════════════════════════════

(function initTerminal() {
    var termPanel = $('ide-terminal-panel');
    var tabsBar = $('terminal-tabs-bar');
    var bodiesEl = $('terminal-bodies');
    var allTabs = [];
    var activeTermId = null;
    var termCounter = 0;

    function createTab(type, name) {
        var id = 'tt-' + (++termCounter);
        var t = { id: id, type: type, name: name || (type === 'debug' ? 'Debug' : 'Terminal ' + termCounter), cwd: '', hist: [], hIdx: -1 };
        var btn = document.createElement('button');
        btn.className = 'ide-terminal-tab';
        btn.dataset.tid = id;
        btn.appendChild(domIcon(type === 'debug' ? 'fa-solid fa-bug' : 'fa-solid fa-terminal'));
        btn.appendChild(document.createTextNode(' ' + t.name));
        var cb = document.createElement('button');
        cb.className = 'ide-terminal-tab-close';
        cb.appendChild(domIcon('fa-solid fa-xmark'));
        cb.addEventListener('click', function(e) { e.stopPropagation(); closeTab(id); });
        btn.appendChild(cb);
        btn.addEventListener('click', function() { activateTab(id); });
        tabsBar.appendChild(btn);
        t.btn = btn;
        var body = document.createElement('div');
        body.className = 'ide-terminal-body-item';
        body.id = id;
        if (type === 'terminal') {
            var out = document.createElement('div'); out.className = 'ide-terminal-output'; out.setAttribute('role','log');
            var row = document.createElement('div'); row.className = 'ide-terminal-input-row';
            var pr = document.createElement('span'); pr.className = 'ide-terminal-prompt'; pr.textContent = 'php>';
            var inp = document.createElement('input'); inp.type = 'text'; inp.className = 'ide-terminal-input'; inp.placeholder = "Código PHP ou 'run arquivo.php'..."; inp.autocomplete = 'off'; inp.spellcheck = false;
            row.appendChild(pr); row.appendChild(inp);
            body.appendChild(out); body.appendChild(row);
            t.out = out; t.inp = inp;
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); execCmd(t, this.value); this.value = ''; }
                else if (e.key === 'ArrowUp') { e.preventDefault(); if (t.hIdx < t.hist.length-1) { t.hIdx++; this.value = t.hist[t.hIdx]; } }
                else if (e.key === 'ArrowDown') { e.preventDefault(); if (t.hIdx > 0) { t.hIdx--; this.value = t.hist[t.hIdx]; } else { t.hIdx = -1; this.value = ''; } }
            });
            addLine(out, 'Vupi.us IDE Terminal — PHP interativo', 'term-help-title');
            addLine(out, 'Execute qualquer código PHP, "run arquivo.php" para executar arquivos, ou "help" para ver todos os comandos.', 'term-info');
        } else {
            var ctrl = document.createElement('div'); ctrl.className = 'ide-debug-controls';
            var bRun = document.createElement('button'); bRun.className = 'ide-debug-btn'; bRun.appendChild(domIcon('fa-solid fa-play')); bRun.appendChild(document.createTextNode(' Executar')); bRun.addEventListener('click', function() { doDebug(null, t); });
            var bStep = document.createElement('button'); bStep.className = 'ide-debug-btn'; bStep.appendChild(domIcon('fa-solid fa-forward-step')); bStep.appendChild(document.createTextNode(' Step')); bStep.addEventListener('click', function() { var l = S.editor ? (S.editor.getPosition()||{}).lineNumber : null; doDebug(l, t); });
            var info = document.createElement('span'); info.className = 'ide-debug-info'; info.textContent = 'Selecione .php para debugar';
            ctrl.appendChild(bRun); ctrl.appendChild(bStep); ctrl.appendChild(info);
            var dout = document.createElement('div'); dout.className = 'ide-debug-output'; dout.setAttribute('role','log');
            body.appendChild(ctrl); body.appendChild(dout);
            t.out = dout; t.info = info;
        }
        bodiesEl.appendChild(body); t.body = body; allTabs.push(t); activateTab(id); return t;
    }
    function activateTab(id) { activeTermId = id; allTabs.forEach(function(t) { t.btn.classList.toggle('active', t.id===id); t.body.classList.toggle('active', t.id===id); }); var t = getTab(id); if (t && t.inp) setTimeout(function() { t.inp.focus(); }, 30); }
    function closeTab(id) { var i = allTabs.findIndex(function(t){return t.id===id;}); if (i===-1) return; allTabs[i].btn.remove(); allTabs[i].body.remove(); allTabs.splice(i,1); if (!allTabs.length) { termPanel.style.display='none'; saveLayout(); } else if (activeTermId===id) activateTab(allTabs[Math.max(0,i-1)].id); }
    function getTab(id) { return allTabs.find(function(t){return t.id===id;}); }
    function getActive() { return getTab(activeTermId); }

    function addLine(c, text, cls) { var d = document.createElement('div'); d.className = 'term-line ' + (cls||'term-out'); d.textContent = text; c.appendChild(d); c.scrollTop = c.scrollHeight; }
    function addRef(c, file, line, msg) { var d = document.createElement('div'); d.className = 'term-line term-err'; if (file&&line) { var r = document.createElement('span'); r.className='term-file-ref'; r.textContent=file+':'+line; r.addEventListener('click',function(){var fs=Object.keys(S.project.files||{});for(var i=0;i<fs.length;i++){if(fs[i].endsWith(file)||fs[i]===file){openFile(fs[i]);if(S.editor&&line)setTimeout(function(){S.editor.revealLineInCenter(line);S.editor.setPosition({lineNumber:line,column:1});S.editor.focus();},100);break;}}}); d.appendChild(r); d.appendChild(document.createTextNode(' — '+msg)); } else d.textContent=msg; c.appendChild(d); c.scrollTop=c.scrollHeight; }
    function filterN(t) { if(!t)return''; return t.split('\n').filter(function(l){return l.trim()&&!/Module\s+".+"\s+is already loaded/i.test(l.trim());}).join('\n').trim(); }
    function showRes(tab, data) {
        var o = filterN(data.output), e = filterN(data.errors);
        if (o) { var d = document.createElement('div'); d.className = 'term-line term-result'; d.textContent = o; tab.out.appendChild(d); tab.out.scrollTop = tab.out.scrollHeight; }
        if (e) {
            var isSec = e.indexOf('[Segurança]') !== -1;
            if (data.file && data.line) addRef(tab.out, data.file, data.line, e);
            else addLine(tab.out, e, isSec ? 'term-security' : 'term-err');
        }
        if (data.exit_code === 0 && !o && !e) addLine(tab.out, '(sem saída)', 'term-info');
        if (data.duration_ms > 0) addLine(tab.out, (data.exit_code === 0 ? '✓' : '✗') + ' ' + data.duration_ms + 'ms', data.exit_code === 0 ? 'term-duration-ok' : 'term-duration-err');
    }

    async function execCmd(t, input) {
        var tr = input.trim(); if (!tr) return;
        t.hist.unshift(tr); if (t.hist.length > 50) t.hist.pop(); t.hIdx = -1;
        var p = tr.split(/\s+/), cmd = p[0].toLowerCase(), arg = p.slice(1).join(' ');
        if (cmd==='clear'||cmd==='cls'||cmd==='clean') { t.out.textContent=''; return; }
        if (cmd==='help'||cmd==='?') {
            addLine(t.out, 'php> help', 'term-cmd');
            addLine(t.out, '', '');
            addLine(t.out, '=== Vupi.us IDE Terminal — Ajuda Completa ===', 'term-help-title');
            addLine(t.out, '', '');
            addLine(t.out, '--- Comandos PHP ---', 'term-help-title');
            addLine(t.out, '  echo "texto";          Executa codigo PHP inline e exibe o resultado.', 'term-help');
            addLine(t.out, '                         Exemplo: echo date("Y-m-d");', 'term-help');
            addLine(t.out, '  $var = valor;          Executa qualquer expressao PHP valida.', 'term-help');
            addLine(t.out, '                         Exemplo: $x = 2 + 2; echo $x;', 'term-help');
            addLine(t.out, '  run <arquivo>          Executa um arquivo .php do modulo.', 'term-help');
            addLine(t.out, '                         O arquivo deve estar dentro do modulo atual.', 'term-help');
            addLine(t.out, '                         Exemplo: run Controllers/TaskController.php', 'term-help');
            addLine(t.out, '', '');
            addLine(t.out, '  Exemplos de codigo PHP valido:', 'term-help');
            addLine(t.out, '    echo strtoupper("hello world");', 'term-help');
            addLine(t.out, '    $arr = [1,2,3]; echo array_sum($arr);', 'term-help');
            addLine(t.out, '    $json = json_encode(["ok"=>true]); echo $json;', 'term-help');
            addLine(t.out, '    $c = file_get_contents("Routes/web.php"); echo strlen($c)." bytes";', 'term-help');
            addLine(t.out, '    include "Services/MeuService.php"; $s = new MeuService(); $s->run();', 'term-help');
            addLine(t.out, '', '');
            addLine(t.out, '--- Navegacao de Arquivos ---', 'term-help-title');
            addLine(t.out, '  ls [pasta]             Lista arquivos e pastas do diretorio atual ou', 'term-help');
            addLine(t.out, '                         do caminho informado. Mostra icone, nome e tamanho.', 'term-help');
            addLine(t.out, '                         Exemplo: ls Controllers', 'term-help');
            addLine(t.out, '  cat <arquivo>          Exibe o conteudo de um arquivo do modulo.', 'term-help');
            addLine(t.out, '                         Limitado a 50KB por seguranca.', 'term-help');
            addLine(t.out, '                         Exemplo: cat Routes/web.php', 'term-help');
            addLine(t.out, '  cd <pasta>             Navega para uma pasta dentro do modulo.', 'term-help');
            addLine(t.out, '                         Restrito ao modulo — nao permite sair dele.', 'term-help');
            addLine(t.out, '  cd ..                  Volta para a pasta anterior.', 'term-help');
            addLine(t.out, '  cd ~ ou cd /           Volta para a raiz do modulo.', 'term-help');
            addLine(t.out, '  pwd                    Mostra o diretorio atual completo.', 'term-help');
            addLine(t.out, '                         Exemplo: src/Modules/Task/Controllers', 'term-help');
            addLine(t.out, '', '');
            addLine(t.out, '--- Utilitarios ---', 'term-help-title');
            addLine(t.out, '  clear / cls / clean    Limpa toda a tela do terminal.', 'term-help');
            addLine(t.out, '  history                Lista os ultimos comandos executados nesta aba.', 'term-help');
            addLine(t.out, '                         Use seta cima/baixo para navegar no historico.', 'term-help');
            addLine(t.out, '  help / ?               Exibe esta ajuda detalhada.', 'term-help');
            addLine(t.out, '', '');
            addLine(t.out, '--- Atalhos de Teclado ---', 'term-help-title');
            addLine(t.out, '  F5                     Salva e executa o arquivo .php aberto no editor.', 'term-help');
            addLine(t.out, '  F6                     Salva e executa debug do arquivo .php aberto.', 'term-help');
            addLine(t.out, '  Ctrl + `               Abre ou fecha o painel do terminal.', 'term-help');
            addLine(t.out, '  Ctrl + T               Abre o API Route Tester.', 'term-help');
            addLine(t.out, '  Ctrl + S               Salva todos os arquivos abertos.', 'term-help');
            addLine(t.out, '  Ctrl + \\               Divide o editor em dois paineis.', 'term-help');
            addLine(t.out, '  Enter                  Executa o comando digitado.', 'term-help');
            addLine(t.out, '  Seta Cima/Baixo        Navega pelo historico de comandos.', 'term-help');
            addLine(t.out, '', '');
            addLine(t.out, '--- Sandbox de Seguranca ---', 'term-help-title');
            addLine(t.out, '  Codigo PHP roda em processo isolado — nao afeta o servidor.', 'term-info');
            addLine(t.out, '  file_get_contents, fopen, include, require: PERMITIDOS (restrito ao modulo).', 'term-info');
            addLine(t.out, '  exec, system, shell_exec, passthru: BLOQUEADOS (escape de sandbox).', 'term-info');
            addLine(t.out, '  curl, fsockopen, stream_socket_client: BLOQUEADOS (acesso externo).', 'term-info');
            addLine(t.out, '  eval, assert, create_function: BLOQUEADOS (codigo dinamico arbitrario).', 'term-info');
            addLine(t.out, '  Filesystem restrito ao diretorio do modulo via open_basedir.', 'term-info');
            addLine(t.out, '  Timeout de 30 segundos por execucao. Output maximo: 512KB.', 'term-info');
            addLine(t.out, '', '');
            return;
        }
        if (cmd==='history') { addLine(t.out,'php> history','term-cmd'); for(var i=t.hist.length-1;i>=1;i--)addLine(t.out,'  '+(t.hist.length-i)+'. '+t.hist[i],'term-help'); return; }
        if (cmd==='pwd') { addLine(t.out,'$ pwd','term-cmd'); addLine(t.out,'src/Modules/'+(S.project?S.project.module_name:'?')+'/'+(t.cwd||'')); return; }
        if (cmd==='cd') { addLine(t.out,'$ cd '+(arg||'~'),'term-cmd'); if(!arg||arg==='~'||arg==='/') t.cwd=''; else if(arg==='..'){var s=t.cwd.split('/').filter(Boolean);s.pop();t.cwd=s.join('/');} else if(arg.indexOf('..')!==-1||arg.startsWith('/')){addLine(t.out,'Acesso negado.','term-err');return;} else t.cwd=t.cwd?t.cwd+'/'+arg:arg; addLine(t.out,'src/Modules/'+(S.project?S.project.module_name:'')+'/'+(t.cwd||''),'term-info'); return; }
        if (!S.project) { addLine(t.out,'Nenhum projeto carregado.','term-err'); return; }
        if (cmd==='ls'||cmd==='dir') { addLine(t.out,'$ ls '+(arg||'.'),'term-cmd'); try{var d=await api('POST','/api/ide/projects/'+S.project.id+'/terminal',{command:'ls',path:arg?(t.cwd?t.cwd+'/'+arg:arg):t.cwd});if(d.error){addLine(t.out,d.error,'term-err');return;}(d.files||[]).forEach(function(f){addLine(t.out,'  '+(f.type==='dir'?'📁':'📄')+' '+f.name,f.type==='dir'?'term-folder':'term-file');});}catch(e){addLine(t.out,'Erro: '+e.message,'term-err');} return; }
        if (cmd==='cat'||cmd==='type') { if(!arg){addLine(t.out,'Uso: cat <arquivo>','term-err');return;} addLine(t.out,'$ cat '+arg,'term-cmd'); try{var c=await api('POST','/api/ide/projects/'+S.project.id+'/terminal',{command:'cat',path:t.cwd?t.cwd+'/'+arg:arg});if(c.error)addLine(t.out,c.error,'term-err');else addLine(t.out,c.content);}catch(e){addLine(t.out,'Erro: '+e.message,'term-err');} return; }
        addLine(t.out,'php> '+tr,'term-cmd');
        var rm=tr.match(/^run\s+(.+)$/i);
        try{var data=await api('POST','/api/ide/projects/'+S.project.id+'/run',rm?{file:rm[1]}:{code:tr});showRes(t,data);}catch(e){addLine(t.out,'Erro: '+e.message,'term-err');}
    }

    async function doDebug(breakLine, t) {
        if (!S.project||!S.activeTab) { toast('Abra um .php primeiro'); return; }
        if (!S.activeTab.endsWith('.php')) { toast('Apenas .php'); return; }
        await saveAll(); termPanel.style.display='flex'; activateTab(t.id); t.out.textContent='';
        if (t.info) t.info.textContent='Debugando '+S.activeTab+'...';
        var b={file:S.activeTab}; if(breakLine!=null)b.break_line=breakLine;
        try { var data=await api('POST','/api/ide/projects/'+S.project.id+'/debug',b); addLine(t.out,'── '+S.activeTab+' ──','dbg-section'); if(data.output&&data.output.trim())addLine(t.out,data.output.trim()); if(data.type==='syntax_error'||data.type==='runtime_error'||data.type==='error'){addLine(t.out,'── Erro ──','dbg-section');if(data.file&&data.line)addRef(t.out,data.file,data.line,(data.errors||'').trim());else addLine(t.out,(data.errors||'').trim(),'term-err');} if(data.type==='success')addLine(t.out,'✓ Sem erros ('+data.duration_ms+'ms)','term-success'); if(data.debug&&t.info)t.info.textContent=data.debug.file+' — '+data.debug.total_lines+' linhas'+(data.debug.error_line?' — Erro L'+data.debug.error_line:''); } catch(e){addLine(t.out,'Erro: '+e.message,'term-err');}
        saveLayout();
    }

    // Create initial tabs
    var t1 = createTab('terminal','Terminal 1');
    var tDbg = createTab('debug','Debug');
    activateTab(t1.id);

    // Buttons
    $('btn-toggle-terminal').addEventListener('click', function() { var v=termPanel.style.display!=='none'; termPanel.style.display=v?'none':'flex'; if(!v){var t=getActive();if(t&&t.inp)setTimeout(function(){t.inp.focus();},50);} saveLayout(); });
    $('btn-close-terminal').addEventListener('click', function() { termPanel.style.display='none'; saveLayout(); });
    $('btn-clear-terminal').addEventListener('click', function() { var t=getActive(); if(t)t.out.textContent=''; });
    $('btn-new-terminal').addEventListener('click', function() { termPanel.style.display='flex'; createTab('terminal'); saveLayout(); });

    $('btn-run-file').addEventListener('click', async function() {
        if(!S.project||!S.activeTab){toast('Abra um .php primeiro');return;} if(!S.activeTab.endsWith('.php')){toast('Apenas .php');return;}
        await saveAll(); termPanel.style.display='flex';
        var t=allTabs.find(function(x){return x.type==='terminal';})||createTab('terminal'); activateTab(t.id);
        addLine(t.out,'▶ '+S.activeTab+'...','term-info');
        try{var data=await api('POST','/api/ide/projects/'+S.project.id+'/run',{file:S.activeTab});showRes(t,data);}catch(e){addLine(t.out,'Erro: '+e.message,'term-err');}
        saveLayout();
    });
    $('btn-debug-file').addEventListener('click', function() { termPanel.style.display='flex'; activateTab(tDbg.id); doDebug(null,tDbg); });

    document.addEventListener('keydown', function(e) {
        if(e.key==='F5'){e.preventDefault();$('btn-run-file').click();}
        if(e.key==='F6'){e.preventDefault();$('btn-debug-file').click();}
        if(e.key==='`'&&(e.ctrlKey||e.metaKey)){e.preventDefault();$('btn-toggle-terminal').click();}
    });
})();

// Split Editor
S.splitOpen = false; S.splitEditor = null; S.splitFile = null;
$('btn-split-editor').addEventListener('click', function() {
    if(!S.monacoReady) return;
    S.splitOpen = !S.splitOpen;
    var sc=$('ide-split-container'), me=$('monaco-editor'), ec=$('ide-editor-container');
    if(S.splitOpen) {
        if(!S.activeTab){toast('Abra um arquivo primeiro');S.splitOpen=false;return;}
        me.style.display='none'; sc.style.display='flex';
        $('split-left').textContent=''; $('split-left').appendChild(me); me.style.display='block'; me.style.width='100%'; me.style.height='100%';
        var rp=$('split-right'); rp.textContent=''; var rd=document.createElement('div'); rd.style.cssText='width:100%;height:100%;'; rp.appendChild(rd);
        var dk=document.body.classList.contains('dark');
        S.splitEditor=monaco.editor.create(rd,{value:S.editor?S.editor.getValue():'',language:S.editor?S.editor.getModel().getLanguageId():'php',theme:dk?'vs-dark':'vs',fontSize:15,fontFamily:"'JetBrains Mono',Consolas,monospace",minimap:{enabled:false},readOnly:false,automaticLayout:true,scrollBeyondLastLine:false});
        S.splitFile=S.activeTab;
        // Split resize
        var sh=$('split-handle'),sl=$('split-left'),sr=$('split-right');
        sh.addEventListener('mousedown',function(e){e.preventDefault();var sx=e.clientX,sw=sl.getBoundingClientRect().width,tw=sc.getBoundingClientRect().width;document.body.classList.add('ide-resizing');function mv(ev){var d=ev.clientX-sx,nw=Math.max(100,Math.min(tw-100,sw+d));sl.style.width=nw+'px';sr.style.width=(tw-nw-5)+'px';if(S.editor)S.editor.layout();if(S.splitEditor)S.splitEditor.layout();}function up(){document.body.classList.remove('ide-resizing');document.removeEventListener('mousemove',mv);document.removeEventListener('mouseup',up);}document.addEventListener('mousemove',mv);document.addEventListener('mouseup',up);});
        if(S.editor)S.editor.layout();
    } else {
        sc.style.display='none'; ec.insertBefore(me,sc); me.style.display=S.activeTab?'block':'none'; me.style.width='100%'; me.style.height='100%';
        if(S.splitEditor){S.splitEditor.dispose();S.splitEditor=null;} S.splitFile=null; if(S.editor)S.editor.layout();
    }
    saveLayout();
});
document.addEventListener('keydown',function(e){if(e.key==='\\'&&(e.ctrlKey||e.metaKey)){e.preventDefault();$('btn-split-editor').click();}});

// ══════════════════════════════════════════════════════════════════════════
// API Route Tester — Completo
// ══════════════════════════════════════════════════════════════════════════
(function initApiTester() {
    var methodSel = $('api-method'), urlInput = $('api-url'), sendBtn = $('api-send');
    var bodyInput = $('api-body-input');
    var resStatus = $('api-res-status'), resTime = $('api-res-time'), resSize = $('api-res-size');
    var resBody = $('api-res-body'), resHeadersOut = $('api-res-headers-out'), resCookiesOut = $('api-res-cookies-out');
    var routesList = $('api-routes-list');

    // Open/close
    $('btn-api-tester').addEventListener('click', function () { showModal('modal-api-tester'); loadModuleRoutes(); addDefaultHeaders(); renderSavedList(); });
    $('api-tester-close').addEventListener('click', function () { hideModal('modal-api-tester'); });

    // ── Saved Requests ──
    var SAVED_KEY = 'ide-api-saved';
    var activeReqId = null;

    function getSavedRequests() {
        try {
            var pid = S.project ? S.project.id : 'global';
            var all = JSON.parse(localStorage.getItem(SAVED_KEY) || '{}');
            return all[pid] || [];
        } catch (e) { return []; }
    }

    function setSavedRequests(list) {
        try {
            var pid = S.project ? S.project.id : 'global';
            var all = JSON.parse(localStorage.getItem(SAVED_KEY) || '{}');
            all[pid] = list;
            localStorage.setItem(SAVED_KEY, JSON.stringify(all));
        } catch (e) {}
    }

    function captureCurrentRequest() {
        return {
            id: activeReqId || ('req-' + Date.now()),
            method: methodSel.value,
            url: urlInput.value,
            headers: collectKv('api-headers-list'),
            query: collectKv('api-query-list'),
            authType: (document.querySelector('input[name="api-auth"]:checked') || {}).value || 'none',
            authToken: ($('api-auth-token') || {}).value || '',
            authUser: ($('api-auth-user') || {}).value || '',
            authPass: ($('api-auth-pass') || {}).value || '',
            bodyType: (document.querySelector('input[name="api-body-type"]:checked') || {}).value || 'json',
            body: bodyInput.value,
            formData: collectKv('api-form-list'),
            name: urlInput.value || 'Sem nome',
            savedAt: new Date().toISOString(),
        };
    }

    function loadRequest(req) {
        activeReqId = req.id;
        methodSel.value = req.method || 'GET';
        urlInput.value = req.url || '';

        // Headers
        $('api-headers-list').textContent = '';
        var h = req.headers || {};
        Object.keys(h).forEach(function (k) { addKvRow('api-headers-list', k, h[k]); });
        if (!Object.keys(h).length) addDefaultHeaders();

        // Query
        $('api-query-list').textContent = '';
        var q = req.query || {};
        Object.keys(q).forEach(function (k) { addKvRow('api-query-list', k, q[k]); });

        // Auth
        var authRadio = document.querySelector('input[name="api-auth"][value="' + (req.authType || 'none') + '"]');
        if (authRadio) { authRadio.checked = true; authRadio.dispatchEvent(new Event('change')); }
        if ($('api-auth-token')) $('api-auth-token').value = req.authToken || '';
        if ($('api-auth-user')) $('api-auth-user').value = req.authUser || '';
        if ($('api-auth-pass')) $('api-auth-pass').value = req.authPass || '';

        // Body
        var bodyRadio = document.querySelector('input[name="api-body-type"][value="' + (req.bodyType || 'json') + '"]');
        if (bodyRadio) { bodyRadio.checked = true; bodyRadio.dispatchEvent(new Event('change')); }
        bodyInput.value = req.body || '';

        // Form data
        $('api-form-list').textContent = '';
        var fd = req.formData || {};
        Object.keys(fd).forEach(function (k) { addKvRow('api-form-list', k, fd[k]); });

        renderSavedList();
    }

    function renderSavedList() {
        var list = $('api-saved-list');
        list.textContent = '';
        var saved = getSavedRequests();

        if (!saved.length) {
            var empty = document.createElement('div');
            empty.className = 'ide-api-saved-empty';
            empty.textContent = 'Nenhuma requisição salva';
            list.appendChild(empty);
            return;
        }

        saved.forEach(function (req) {
            var item = document.createElement('button');
            item.className = 'ide-api-saved-item' + (activeReqId === req.id ? ' active' : '');

            var badge = document.createElement('span');
            badge.className = 'ide-api-saved-method method-' + (req.method || 'get').toLowerCase();
            badge.textContent = (req.method || 'GET').substring(0, 3);

            var name = document.createElement('span');
            name.className = 'ide-api-saved-name';
            name.textContent = req.url || req.name || 'Sem nome';
            name.title = (req.method || 'GET') + ' ' + (req.url || '');

            var del = document.createElement('button');
            del.className = 'ide-api-saved-del';
            del.title = 'Excluir';
            del.appendChild(domIcon('fa-solid fa-xmark'));
            del.addEventListener('click', function (e) {
                e.stopPropagation();
                var s = getSavedRequests().filter(function (r) { return r.id !== req.id; });
                setSavedRequests(s);
                if (activeReqId === req.id) activeReqId = null;
                renderSavedList();
                toast('Requisição excluída');
            });

            item.appendChild(badge);
            item.appendChild(name);
            item.appendChild(del);
            item.addEventListener('click', function () { loadRequest(req); });
            list.appendChild(item);
        });
    }

    // Save button
    $('api-save-req').addEventListener('click', function () {
        if (!urlInput.value.trim()) { toast('Informe a URL antes de salvar'); return; }
        var req = captureCurrentRequest();
        var saved = getSavedRequests();
        var idx = saved.findIndex(function (r) { return r.id === req.id; });
        if (idx >= 0) {
            saved[idx] = req; // update
        } else {
            req.id = 'req-' + Date.now();
            activeReqId = req.id;
            saved.push(req);
        }
        setSavedRequests(saved);
        renderSavedList();
        toast('Requisição salva');
    });

    // ── Generic KV row builder ──
    function addKvRow(listId, key, val) {
        var list = $(listId); if (!list) return;
        var row = document.createElement('div'); row.className = 'ide-api-kv-row';
        var k = document.createElement('input'); k.type = 'text'; k.placeholder = 'Chave'; k.className = 'api-kv-key'; k.value = key || '';
        var v = document.createElement('input'); v.type = 'text'; v.placeholder = 'Valor'; v.className = 'api-kv-val'; v.value = val || '';
        var del = document.createElement('button'); del.className = 'ide-api-kv-del'; del.setAttribute('aria-label', 'Remover');
        del.appendChild(domIcon('fa-solid fa-xmark'));
        del.addEventListener('click', function () { row.remove(); });
        row.appendChild(k); row.appendChild(v); row.appendChild(del);
        list.appendChild(row); return k;
    }

    function collectKv(listId) {
        var pairs = {}; var list = $(listId); if (!list) return pairs;
        list.querySelectorAll('.ide-api-kv-row').forEach(function (row) {
            var k = row.querySelector('.api-kv-key').value.trim();
            var v = row.querySelector('.api-kv-val').value.trim();
            if (k) pairs[k] = v;
        });
        return pairs;
    }

    // Default headers
    function addDefaultHeaders() {
        var list = $('api-headers-list');
        if (list.children.length === 0) {
            addKvRow('api-headers-list', 'Content-Type', 'application/json');
            addKvRow('api-headers-list', 'Accept', 'application/json');
        }
    }

    // Add row buttons
    $('api-add-header').addEventListener('click', function () { var k = addKvRow('api-headers-list', '', ''); if (k) k.focus(); });
    $('api-add-query').addEventListener('click', function () { var k = addKvRow('api-query-list', '', ''); if (k) k.focus(); });
    $('api-add-form').addEventListener('click', function () { var k = addKvRow('api-form-list', '', ''); if (k) k.focus(); });

    // Delete row delegation
    ['api-headers-list', 'api-query-list', 'api-form-list'].forEach(function (id) {
        var el = $(id); if (!el) return;
        el.addEventListener('click', function (e) { var d = e.target.closest('.ide-api-kv-del'); if (d) d.closest('.ide-api-kv-row').remove(); });
    });

    // ── Request tabs ──
    var reqTabIds = { query: 'api-tab-query', headers: 'api-tab-headers', auth: 'api-tab-auth', body: 'api-tab-body', routes: 'api-tab-routes' };
    $('api-req-tabs').addEventListener('click', function (e) {
        var tab = e.target.closest('[data-req-tab]'); if (!tab) return;
        var name = tab.dataset.reqTab;
        $('api-req-tabs').querySelectorAll('.ide-api-tab').forEach(function (t) { t.classList.toggle('active', t === tab); });
        Object.keys(reqTabIds).forEach(function (k) { var el = $(reqTabIds[k]); if (el) el.style.display = k === name ? '' : 'none'; });
    });

    // ── Response tabs ──
    var resTabIds = { 'res-body': 'api-tab-res-body', 'res-headers': 'api-tab-res-headers', 'res-cookies': 'api-tab-res-cookies' };
    $('api-res-tabs').addEventListener('click', function (e) {
        var tab = e.target.closest('[data-res-tab]'); if (!tab) return;
        var name = tab.dataset.resTab;
        $('api-res-tabs').querySelectorAll('.ide-api-tab').forEach(function (t) { t.classList.toggle('active', t === tab); });
        Object.keys(resTabIds).forEach(function (k) { var el = $(resTabIds[k]); if (el) el.style.display = k === name ? '' : 'none'; });
    });

    // ── Auth type toggle ──
    document.querySelectorAll('input[name="api-auth"]').forEach(function (r) {
        r.addEventListener('change', function () {
            $('api-auth-bearer').style.display = this.value === 'bearer' ? '' : 'none';
            $('api-auth-basic').style.display = this.value === 'basic' ? '' : 'none';
        });
    });

    // ── Body type toggle ──
    document.querySelectorAll('input[name="api-body-type"]').forEach(function (r) {
        r.addEventListener('change', function () {
            var v = this.value;
            $('api-body-json').style.display = (v === 'json' || v === 'text' || v === 'xml') ? '' : 'none';
            $('api-body-form').style.display = (v === 'form' || v === 'form-encoded') ? '' : 'none';
            if (v === 'json') bodyInput.placeholder = '{"key": "value"}';
            else if (v === 'xml') bodyInput.placeholder = '<root><item>valor</item></root>';
            else if (v === 'text') bodyInput.placeholder = 'Texto puro...';
        });
    });

    // ── Load module routes ──
    function loadModuleRoutes() {
        routesList.textContent = '';
        if (!S.project) { routesList.appendChild(domEl('div', 'term-info', ['Nenhum projeto carregado.'])); return; }
        var routesFile = null;
        var files = S.project.files || {};
        for (var path in files) { if (path.endsWith('Routes/web.php') || path.endsWith('Routes/api.php')) { routesFile = files[path]; break; } }
        if (!routesFile) { routesList.appendChild(domEl('div', 'term-info', ['Nenhum arquivo de rotas encontrado.'])); return; }
        var regex = /\$router->(get|post|put|patch|delete)\s*\(\s*['"]([^'"]+)['"]/gi;
        var m, found = [];
        while ((m = regex.exec(routesFile)) !== null) found.push({ method: m[1].toUpperCase(), uri: m[2] });
        if (!found.length) { routesList.appendChild(domEl('div', 'term-info', ['Nenhuma rota encontrada.'])); return; }
        found.forEach(function (r) {
            var btn = document.createElement('button'); btn.className = 'ide-api-route';
            var badge = document.createElement('span'); badge.className = 'ide-api-route-method method-' + r.method.toLowerCase(); badge.textContent = r.method;
            var uri = document.createElement('span'); uri.className = 'ide-api-route-uri'; uri.textContent = r.uri;
            var copyBtn = document.createElement('button'); copyBtn.className = 'ide-api-route-copy'; copyBtn.title = 'Copiar URL';
            copyBtn.appendChild(domIcon('fa-solid fa-copy'));
            copyBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (navigator.clipboard) navigator.clipboard.writeText(r.uri);
                urlInput.value = r.uri;
                toast('URL copiada');
            });
            btn.appendChild(badge); btn.appendChild(uri); btn.appendChild(copyBtn);
            btn.addEventListener('click', function () {
                methodSel.value = r.method; urlInput.value = r.uri;
                // Switch to headers tab
                $('api-req-tabs').querySelector('[data-req-tab="headers"]').click();
                urlInput.focus();
            });
            routesList.appendChild(btn);
        });
    }

    // ── Send request ──
    sendBtn.addEventListener('click', async function () {
        var method = methodSel.value;
        var url = urlInput.value.trim();
        if (!url) { toast('Informe a URL'); return; }
        if (!url.startsWith('/') && !url.startsWith('http')) url = '/' + url;

        // Query params
        var queryParams = collectKv('api-query-list');
        var qs = Object.keys(queryParams).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(queryParams[k]); }).join('&');
        if (qs) url += (url.includes('?') ? '&' : '?') + qs;

        // Headers
        var headers = collectKv('api-headers-list');

        // Auth
        var authType = document.querySelector('input[name="api-auth"]:checked');
        if (authType) {
            if (authType.value === 'bearer') {
                var token = $('api-auth-token').value.trim();
                if (token) headers['Authorization'] = 'Bearer ' + token;
            } else if (authType.value === 'basic') {
                var user = $('api-auth-user').value.trim();
                var pass = $('api-auth-pass').value;
                if (user) headers['Authorization'] = 'Basic ' + btoa(user + ':' + pass);
            }
        }

        // Body
        var body = null;
        if (method !== 'GET' && method !== 'HEAD' && method !== 'DELETE') {
            var bodyType = document.querySelector('input[name="api-body-type"]:checked');
            var bt = bodyType ? bodyType.value : 'json';
            if (bt === 'json' || bt === 'text' || bt === 'xml') {
                body = bodyInput.value;
                if (bt === 'json' && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
                if (bt === 'xml' && !headers['Content-Type']) headers['Content-Type'] = 'application/xml';
                if (bt === 'text' && !headers['Content-Type']) headers['Content-Type'] = 'text/plain';
            } else if (bt === 'form' || bt === 'form-encoded') {
                var formData = collectKv('api-form-list');
                if (bt === 'form-encoded') {
                    body = Object.keys(formData).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(formData[k]); }).join('&');
                    if (!headers['Content-Type']) headers['Content-Type'] = 'application/x-www-form-urlencoded';
                } else {
                    var fd = new FormData();
                    Object.keys(formData).forEach(function (k) { fd.append(k, formData[k]); });
                    body = fd;
                    delete headers['Content-Type']; // browser sets multipart boundary
                }
            }
        }

        sendBtn.disabled = true;
        resStatus.textContent = 'Enviando...'; resStatus.className = 'ide-api-res-status res-status-wait';
        resTime.textContent = ''; resSize.textContent = '';
        resBody.textContent = ''; resHeadersOut.textContent = ''; resCookiesOut.textContent = '';

        var start = performance.now();
        try {
            var opts = { method: method, credentials: 'same-origin', headers: headers };
            if (body !== null) opts.body = body;
            var res = await fetch(url, opts);
            var elapsed = Math.round(performance.now() - start);
            var text = await res.text();
            var size = new Blob([text]).size;

            // Status
            var cls = res.ok ? 'res-status-ok' : (res.status >= 400 && res.status < 500 ? 'res-status-warn' : 'res-status-err');
            resStatus.textContent = res.status + ' ' + res.statusText;
            resStatus.className = 'ide-api-res-status ' + cls;
            resTime.textContent = elapsed + 'ms';
            resSize.textContent = fmtBytes(size);

            // Body
            try { resBody.textContent = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { resBody.textContent = text; }

            // Headers
            var hLines = [];
            res.headers.forEach(function (val, key) { hLines.push(key + ': ' + val); });
            resHeadersOut.textContent = hLines.join('\n') || '(sem headers)';

            // Cookies
            var cookieHeader = res.headers.get('set-cookie');
            resCookiesOut.textContent = cookieHeader || '(nenhum cookie na resposta — cookies HttpOnly não são visíveis ao JS)';

        } catch (e) {
            resStatus.textContent = 'Erro'; resStatus.className = 'ide-api-res-status res-status-err';
            resTime.textContent = Math.round(performance.now() - start) + 'ms';
            resBody.textContent = 'Erro na requisição: ' + e.message;
        } finally { sendBtn.disabled = false; }
    });

    function fmtBytes(b) { if (b < 1024) return b + ' B'; if (b < 1048576) return (b / 1024).toFixed(1) + ' KB'; return (b / 1048576).toFixed(1) + ' MB'; }

    urlInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); sendBtn.click(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 't' && (e.ctrlKey || e.metaKey) && !e.shiftKey) { e.preventDefault(); $('btn-api-tester').click(); } });
})();

// ── Logout & Session Check ────────────────────────────────────────────────
(function () {
    var logoutBtn = $('btn-logout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
            fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin' })
                .finally(function () { window.location.replace('/ide/login'); });
        });
    }

    // Verifica token a cada 60s — redireciona se expirou
    setInterval(function () {
        fetch('/api/auth/me', { method: 'GET', credentials: 'same-origin' })
            .then(function (res) {
                if (res.status === 401) {
                    window.location.replace('/ide/login');
                }
            })
            .catch(function () {});
    }, 60000);
})();

// ── Panel Resize (drag handles) ───────────────────────────────────────────
(function initResize() {
    // Horizontal resize: files panel
    setupHResize('resize-files', 'panel-files', null, 140, 600, false);
    // Horizontal resize: deploy panel
    setupHResize('resize-deploy', 'panel-deploy', null, 140, 500, true);
    // Vertical resize: terminal panel
    setupVResize('resize-terminal', 'ide-terminal-panel', 80, window.innerHeight * 0.7);

    function setupHResize(handleId, panelId, editorId, minW, maxW, isRight) {
        var handle = $(handleId);
        var panel  = $(panelId);
        if (!handle || !panel) return;

        var startX, startW;

        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            startX = e.clientX;
            startW = panel.getBoundingClientRect().width;
            handle.classList.add('dragging');
            document.body.classList.add('ide-resizing');

            function onMove(ev) {
                var diff = isRight ? (startX - ev.clientX) : (ev.clientX - startX);
                var newW = Math.max(minW, Math.min(maxW, startW + diff));
                panel.style.width = newW + 'px';
                if (S.editor) S.editor.layout();
            }

            function onUp() {
                handle.classList.remove('dragging');
                document.body.classList.remove('ide-resizing');
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                saveLayout();
            }

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    }

    function setupVResize(handleId, panelId, minH, maxH) {
        var handle = $(handleId);
        var panel  = $(panelId);
        if (!handle || !panel) return;

        var startY, startH;

        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            startY = e.clientY;
            startH = panel.getBoundingClientRect().height;
            handle.classList.add('dragging');
            document.body.classList.add('ide-resizing-v');

            function onMove(ev) {
                var diff = startY - ev.clientY;
                var newH = Math.max(minH, Math.min(maxH, startH + diff));
                panel.style.height = newH + 'px';
                if (S.editor) S.editor.layout();
            }

            function onUp() {
                handle.classList.remove('dragging');
                document.body.classList.remove('ide-resizing-v');
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                saveLayout();
            }

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    }
})();
