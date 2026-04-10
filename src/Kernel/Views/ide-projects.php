<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($titulo ?? 'Projetos', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/ide-projects.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/css/ide-projects.css') ?: time() ?>">
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        (function(){
            var dark = localStorage.getItem('idep-dark-mode') === '1';
            if (dark) document.documentElement.classList.add('idep-pre-dark');
            document.addEventListener('DOMContentLoaded', function(){
                if (dark) document.body.classList.add('idep-dark');
                document.documentElement.classList.remove('idep-pre-dark');
            });
        })();
    </script>
</head>
<body class="idep-body">

<!-- ── NAVBAR DA IDE ──────────────────────────────────────────────────── -->
<header class="idep-topbar" id="idep-topbar">
    <div class="idep-topbar-inner">
        <a href="/" class="idep-brand" aria-label="Vupi.us IDE — voltar ao início">
            <?php if (!empty($logo_url)): ?>
                <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="idep-brand-img" />
            <?php else: ?>
                <img src="/favicon.ico" alt="Vupi.us" class="idep-brand-img" />
            <?php endif; ?>
            <span class="idep-brand-name">Vupi.us <span class="idep-brand-accent">IDE</span></span>
        </a>

        <nav class="idep-nav" aria-label="Navegação da IDE">
            <a href="/" class="idep-nav-link">
                <i class="fa-solid fa-house"></i>
                <span>Início</span>
            </a>
            <a href="/doc" class="idep-nav-link">
                <i class="fa-solid fa-book-open"></i>
                <span>Docs</span>
            </a>
            <a href="/dashboard/ide" class="idep-nav-link idep-nav-active">
                <i class="fa-solid fa-code"></i>
                <span>Projetos</span>
            </a>
        </nav>

        <div class="idep-topbar-actions">
            <button class="idep-theme-btn" id="idep-theme-toggle" aria-label="Alternar tema" title="Alternar dark/light">
                <i class="fa-solid fa-moon" id="idep-theme-icon"></i>
            </button>
            <button class="idep-logout-btn" id="idep-logout" aria-label="Sair" title="Sair da IDE">
                <i class="fa-solid fa-right-from-bracket"></i>
            </button>
        </div>

        <button class="idep-hamburger" id="idep-hamburger" aria-label="Abrir menu" aria-expanded="false" aria-controls="idep-mobile-menu">
            <span class="idep-hamburger-bar"></span>
            <span class="idep-hamburger-bar"></span>
            <span class="idep-hamburger-bar"></span>
        </button>
    </div>

    <!-- Menu mobile -->
    <div class="idep-mobile-menu" id="idep-mobile-menu" aria-hidden="true">
        <a href="/" class="idep-mobile-link">
            <i class="fa-solid fa-house"></i>
            <span>Início</span>
        </a>
        <a href="/doc" class="idep-mobile-link">
            <i class="fa-solid fa-book-open"></i>
            <span>Docs</span>
        </a>
        <a href="/dashboard/ide" class="idep-mobile-link idep-mobile-active">
            <i class="fa-solid fa-code"></i>
            <span>Projetos</span>
        </a>
        <div class="idep-mobile-divider"></div>
        <button class="idep-theme-btn idep-theme-btn-mobile" id="idep-theme-toggle-mobile" aria-label="Alternar tema">
            <i class="fa-solid fa-moon" id="idep-theme-icon-mobile"></i>
            <span id="idep-theme-label-mobile">Modo escuro</span>
        </button>
        <button class="idep-mobile-link idep-mobile-logout" id="idep-logout-mobile" aria-label="Sair">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Sair</span>
        </button>
    </div>
</header>

<main class="idep-main">

    <div class="idep-hero">
        <div class="idep-hero-text">
            <h1><i class="fa-solid fa-code" style="color:#6366f1;"></i> Module Builder</h1>
            <p>Crie, edite e publique módulos para o framework Vupi.us API</p>
        </div>
        <button class="idep-btn-new" id="btn-new-project" aria-label="Criar novo projeto">
            <i class="fa-solid fa-plus"></i>
            <span>Novo Projeto</span>
        </button>
    </div>

    <div class="idep-loading" id="idep-loading">
        <i class="fa-solid fa-spinner fa-spin fa-2x"></i>
        <p>Carregando projetos...</p>
    </div>

    <div class="idep-empty" id="idep-empty" style="display:none;">
        <div class="idep-empty-icon" aria-hidden="true">
            <i class="fa-solid fa-folder-open"></i>
        </div>
        <h2>Nenhum projeto ainda</h2>
        <p>Crie seu primeiro módulo para começar a desenvolver</p>
        <button class="idep-btn-new" id="btn-first-project">
            <i class="fa-solid fa-plus"></i>
            <span>Criar Primeiro Projeto</span>
        </button>
    </div>

    <div class="idep-grid" id="idep-grid" style="display:none;"></div>

</main>

<!-- MODAL: Novo Projeto -->
<div class="idep-modal-overlay" id="modal-new-project" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-np-title">
    <div class="idep-modal idep-modal-lg">
        <div class="idep-modal-header">
            <div class="idep-modal-header-icon" aria-hidden="true">
                <i class="fa-solid fa-cube"></i>
            </div>
            <div class="idep-modal-header-text">
                <h2 id="modal-np-title">Novo Módulo</h2>
                <p class="idep-modal-subtitle">Crie um novo módulo para o framework Vupi.us API</p>
            </div>
            <button class="idep-modal-close" id="modal-np-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="idep-modal-body">
            <div class="idep-form-row">
                <div class="idep-form-group">
                    <label for="inp-project-name">
                        <i class="fa-solid fa-tag"></i> Nome do projeto
                    </label>
                    <input type="text" id="inp-project-name" placeholder="Ex: Módulo Financeiro" autocomplete="off" aria-required="true" maxlength="100">
                    <span class="idep-form-hint">Nome amigável para identificar o projeto</span>
                </div>
                <div class="idep-form-group">
                    <label for="inp-module-name">
                        <i class="fa-solid fa-code"></i> Nome do módulo <small>(PascalCase)</small>
                    </label>
                    <input type="text" id="inp-module-name" placeholder="Ex: Financeiro" autocomplete="off" aria-required="true" maxlength="64">
                    <span class="idep-form-hint">Apenas letras e números, sem espaços</span>
                    <span class="idep-module-check" id="module-name-check" style="display:none;" aria-live="polite"></span>
                </div>
            </div>

            <div class="idep-form-group">
                <label for="inp-project-desc">
                    <i class="fa-solid fa-align-left"></i> Descrição do projeto
                </label>
                <textarea id="inp-project-desc" placeholder="Descreva brevemente o que este módulo faz..." rows="3" maxlength="500"></textarea>
                <span class="idep-form-hint">Opcional — ajuda a lembrar o propósito do módulo</span>
            </div>

            <div class="idep-module-preview" id="idep-module-preview" style="display:none;" aria-live="polite">
                <i class="fa-solid fa-folder-tree"></i>
                <span>O módulo será criado em <code>src/Modules/<strong id="preview-module-name"></strong>/</code></span>
            </div>

            <label class="idep-checkbox-label">
                <input type="checkbox" id="inp-scaffold" checked>
                <span>Gerar estrutura padrão automaticamente (Controllers, Services, Routes, Migrations)</span>
            </label>

            <div class="idep-modal-error" id="modal-np-error" style="display:none;" role="alert"></div>
        </div>
        <div class="idep-modal-footer">
            <button class="idep-btn-secondary" id="modal-np-cancel">
                <i class="fa-solid fa-xmark"></i> Cancelar
            </button>
            <button class="idep-btn-primary" id="modal-np-confirm">
                <i class="fa-solid fa-rocket"></i> Criar Projeto
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Confirmar exclusão -->
<div class="idep-modal-overlay" id="modal-delete" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-del-title">
    <div class="idep-modal">
        <div class="idep-modal-header">
            <div class="idep-modal-header-icon" style="background:linear-gradient(135deg,#ef4444,#f87171);" aria-hidden="true">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="idep-modal-header-text">
                <h2 id="modal-del-title">Excluir Projeto Permanentemente</h2>
                <p class="idep-modal-subtitle">Esta ação é irreversível</p>
            </div>
            <button class="idep-modal-close" id="modal-del-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="idep-modal-body">
            <div class="idep-del-warning" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <span>Tem certeza que deseja excluir <strong id="del-project-name"></strong>?</span>
            </div>
            <p class="idep-del-detail">Ao confirmar, os seguintes itens serão apagados permanentemente:</p>
            <ul class="idep-del-list">
                <li><i class="fa-solid fa-folder-minus" aria-hidden="true"></i> Todos os arquivos do projeto na IDE</li>
                <li><i class="fa-solid fa-server" aria-hidden="true"></i> Pasta do módulo em <code>src/Modules/</code></li>
                <li><i class="fa-solid fa-database" aria-hidden="true"></i> Tabelas do banco de dados criadas pelo módulo</li>
                <li><i class="fa-solid fa-route" aria-hidden="true"></i> Rotas e configurações do módulo</li>
            </ul>
            <p class="idep-del-confirm-text">Esta ação <strong>não pode ser desfeita</strong>.</p>
        </div>
        <div class="idep-modal-footer">
            <button class="idep-btn-secondary" id="modal-del-cancel">
                <i class="fa-solid fa-xmark"></i> Cancelar
            </button>
            <button class="idep-btn-danger" id="modal-del-confirm">
                <i class="fa-solid fa-trash"></i> Excluir tudo permanentemente
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Limite de projetos -->
<div class="idep-modal-overlay" id="modal-limit" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-limit-title">
    <div class="idep-modal">
        <div class="idep-modal-header">
            <div class="idep-modal-header-icon" id="modal-limit-icon" aria-hidden="true">
                <i class="fa-solid fa-lock"></i>
            </div>
            <div class="idep-modal-header-text">
                <h2 id="modal-limit-title">Limite de projetos</h2>
                <p class="idep-modal-subtitle" id="modal-limit-subtitle"></p>
            </div>
            <button class="idep-modal-close" id="modal-limit-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="idep-modal-body">
            <div class="idep-limit-alert" id="modal-limit-alert" role="alert">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span id="modal-limit-msg"></span>
            </div>
            <div class="idep-limit-stats" id="modal-limit-stats"></div>
            <p class="idep-limit-help" id="modal-limit-help"></p>
        </div>
        <div class="idep-modal-footer">
            <button class="idep-btn-primary" id="modal-limit-ok">
                <i class="fa-solid fa-check"></i> Entendi
            </button>
        </div>
    </div>
</div>

<div id="idep-toast" role="status" aria-live="polite"></div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
    'use strict';

    var DARK_KEY = 'idep-dark-mode';

    function applyTheme(dark) {
        document.body.classList.toggle('idep-dark', dark);
        ['idep-theme-icon', 'idep-theme-icon-mobile'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        });
        var lbl = document.getElementById('idep-theme-label-mobile');
        if (lbl) lbl.textContent = dark ? 'Modo claro' : 'Modo escuro';
    }

    applyTheme(localStorage.getItem(DARK_KEY) === '1');

    ['idep-theme-toggle', 'idep-theme-toggle-mobile'].forEach(function (id) {
        var btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', function () {
            var nowDark = document.body.classList.toggle('idep-dark');
            localStorage.setItem(DARK_KEY, nowDark ? '1' : '0');
            applyTheme(nowDark);
        });
    });

    // Hamburger mobile
    var hamburger  = document.getElementById('idep-hamburger');
    var mobileMenu = document.getElementById('idep-mobile-menu');
    if (hamburger && mobileMenu) {
        hamburger.addEventListener('click', function () {
            var isOpen = mobileMenu.getAttribute('aria-hidden') === 'false';
            mobileMenu.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
            hamburger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            hamburger.classList.toggle('open', !isOpen);
        });
    }

    // Logout
    function doLogout() {
        fetch('/api/auth/logout', { method: 'POST', credentials: 'same-origin' })
            .finally(function () { window.location.replace('/ide/login'); });
    }
    ['idep-logout', 'idep-logout-mobile'].forEach(function (id) {
        var btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', doLogout);
    });

    // Token expirado — verifica periodicamente
    function checkAuth() {
        fetch('/api/auth/me', { method: 'GET', credentials: 'same-origin' })
            .then(function (res) {
                if (res.status === 401) {
                    window.location.replace('/ide/login');
                }
            })
            .catch(function () {});
    }
    setInterval(checkAuth, 60000);
})();
</script>
<script src="/assets/js/ide-projects.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/ide-projects.js') ?: time() ?>"></script>
</body>
</html>
