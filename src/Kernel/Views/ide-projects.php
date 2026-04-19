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
            <button class="idep-avatar-btn" id="idep-profile-btn" aria-label="Meu perfil" title="Meu perfil">
                <?php if (!empty($avatar_usuario)): ?>
                    <img id="idep-avatar-img" src="<?= htmlspecialchars($avatar_usuario, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
                    <i class="fa-solid fa-circle-user" id="idep-avatar-icon" style="display:none;"></i>
                <?php else: ?>
                    <img id="idep-avatar-img" src="" alt="" style="display:none;">
                    <i class="fa-solid fa-circle-user" id="idep-avatar-icon"></i>
                <?php endif; ?>
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
        <div class="idep-hero-left">
            <!-- Robô flutuante -->
            <div class="idep-robot" aria-hidden="true">
                <div class="idep-robot-head">
                    <div class="idep-robot-eyes">
                        <span class="idep-robot-eye"></span>
                        <span class="idep-robot-eye"></span>
                    </div>
                    <div class="idep-robot-mouth"></div>
                    <div class="idep-robot-antenna"></div>
                </div>
                <div class="idep-robot-body">
                    <div class="idep-robot-chest">
                        <i class="fa-solid fa-code"></i>
                    </div>
                    <div class="idep-robot-arms">
                        <span class="idep-robot-arm idep-robot-arm-l"></span>
                        <span class="idep-robot-arm idep-robot-arm-r"></span>
                    </div>
                </div>
                <div class="idep-robot-legs">
                    <span class="idep-robot-leg"></span>
                    <span class="idep-robot-leg"></span>
                </div>
                <div class="idep-robot-shadow"></div>
            </div>
        </div>
        <div class="idep-hero-text">
            <p class="idep-hero-greeting">Olá, <span id="idep-hero-name"><?= htmlspecialchars(($nome_usuario ?? '') ?: 'desenvolvedor', ENT_QUOTES, 'UTF-8') ?></span> 👋</p>
            <h1><i class="fa-solid fa-code" style="color:#6366f1;"></i> Module Builder</h1>
            <p class="idep-hero-sub">Crie, edite e publique módulos para o framework Vupi.us API</p>
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

<!-- MODAL: Perfil do usuário -->
<div class="idep-modal-overlay" id="idep-modal-profile" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="idep-profile-title">
    <div class="idep-modal idep-modal-profile">
        <div class="idep-modal-header">
            <div class="idep-modal-header-icon" aria-hidden="true"><i class="fa-solid fa-circle-user"></i></div>
            <div class="idep-modal-header-text">
                <h2 id="idep-profile-title">Meu Perfil</h2>
                <p class="idep-modal-subtitle">Informações da sua conta</p>
            </div>
            <button class="idep-modal-close" id="idep-profile-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="idep-modal-body">
            <!-- Avatar + nome + nível -->
            <div class="idep-profile-avatar-wrap">
                <div class="idep-profile-avatar">
                    <img id="idep-profile-avatar-img" src="" alt="" style="display:none;width:96px;height:96px;border-radius:50%;object-fit:cover;">
                    <i class="fa-solid fa-circle-user" id="idep-profile-avatar-icon" style="font-size:96px;color:#6366f1;" aria-hidden="true"></i>
                </div>
                <p class="idep-profile-avatar-name" id="idep-pv-nome-header">—</p>
                <span class="idep-profile-avatar-role" id="idep-pv-nivel-header">—</span>
            </div>
            <!-- View mode — grid de cards -->
            <div id="idep-profile-view">
                <div class="idep-profile-grid">
                    <div class="idep-profile-card">
                        <span class="idep-profile-label"><i class="fa-solid fa-user" aria-hidden="true"></i> Nome completo</span>
                        <span id="idep-pv-nome" class="idep-profile-value">—</span>
                    </div>
                    <div class="idep-profile-card">
                        <span class="idep-profile-label"><i class="fa-solid fa-at" aria-hidden="true"></i> Username</span>
                        <span id="idep-pv-username" class="idep-profile-value">—</span>
                    </div>
                    <div class="idep-profile-card">
                        <span class="idep-profile-label"><i class="fa-solid fa-envelope" aria-hidden="true"></i> E-mail</span>
                        <span id="idep-pv-email" class="idep-profile-value">—</span>
                    </div>
                    <div class="idep-profile-card">
                        <span class="idep-profile-label"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Nível de acesso</span>
                        <span id="idep-pv-nivel" class="idep-profile-value">—</span>
                    </div>
                </div>
            </div>
            <!-- Edit mode -->
            <div id="idep-profile-edit" style="display:none;">
                <div class="idep-profile-input-group">
                    <label for="idep-edit-nome"><i class="fa-solid fa-user"></i> Nome completo</label>
                    <input type="text" id="idep-edit-nome" class="idep-profile-input" autocomplete="name" maxlength="120" placeholder="Seu nome completo">
                </div>
                <div class="idep-profile-input-group">
                    <label for="idep-edit-avatar"><i class="fa-solid fa-image"></i> URL do avatar</label>
                    <input type="url" id="idep-edit-avatar" class="idep-profile-input" autocomplete="off" maxlength="500" placeholder="https://exemplo.com/foto.jpg">
                    <span class="idep-profile-input-hint"><i class="fa-solid fa-circle-info"></i> Cole a URL de uma imagem pública</span>
                </div>
                <div id="idep-profile-feedback" style="display:none;margin-top:4px;font-size:.9rem;padding:10px 14px;border-radius:8px;"></div>
            </div>
        </div>
        <div class="idep-modal-footer" id="idep-profile-footer-view">
            <button class="idep-btn-secondary" id="idep-btn-change-pwd"><i class="fa-solid fa-key"></i> Alterar senha</button>
            <button class="idep-btn-primary"   id="idep-btn-edit-profile"><i class="fa-solid fa-pen"></i> Editar dados</button>
        </div>
        <div class="idep-modal-footer" id="idep-profile-footer-edit" style="display:none;">
            <button class="idep-btn-secondary" id="idep-btn-edit-cancel">Cancelar</button>
            <button class="idep-btn-primary"   id="idep-btn-edit-save"><i class="fa-solid fa-floppy-disk"></i> Salvar alterações</button>
        </div>
    </div>
</div>

<!-- MODAL: Alterar senha -->
<div class="idep-modal-overlay" id="idep-modal-pwd" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="idep-pwd-title">
    <div class="idep-modal">
        <div class="idep-modal-header">
            <div class="idep-modal-header-icon" style="background:linear-gradient(135deg,#6366f1,#818cf8);" aria-hidden="true"><i class="fa-solid fa-key"></i></div>
            <div class="idep-modal-header-text">
                <h2 id="idep-pwd-title">Alterar senha</h2>
                <p class="idep-modal-subtitle">Crie uma senha forte para sua conta</p>
            </div>
            <button class="idep-modal-close" id="idep-pwd-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="idep-modal-body">
            <div class="idep-pwd-group">
                <label for="idep-pwd-current"><i class="fa-solid fa-lock"></i> Senha atual</label>
                <div class="idep-pwd-wrap">
                    <input type="password" id="idep-pwd-current" autocomplete="current-password" placeholder="Digite sua senha atual">
                    <button type="button" class="idep-pwd-eye" data-target="idep-pwd-current" aria-label="Mostrar/ocultar senha"><i class="fa-solid fa-eye"></i></button>
                </div>
            </div>
            <div class="idep-pwd-group">
                <label for="idep-pwd-new"><i class="fa-solid fa-key"></i> Nova senha</label>
                <div class="idep-pwd-wrap">
                    <input type="password" id="idep-pwd-new" autocomplete="new-password" placeholder="Digite a nova senha">
                    <button type="button" class="idep-pwd-eye" data-target="idep-pwd-new" aria-label="Mostrar/ocultar senha"><i class="fa-solid fa-eye"></i></button>
                </div>
            </div>
            <div class="idep-pwd-group">
                <label for="idep-pwd-confirm"><i class="fa-solid fa-check-double"></i> Confirmar nova senha</label>
                <div class="idep-pwd-wrap">
                    <input type="password" id="idep-pwd-confirm" autocomplete="new-password" placeholder="Repita a nova senha">
                    <button type="button" class="idep-pwd-eye" data-target="idep-pwd-confirm" aria-label="Mostrar/ocultar senha"><i class="fa-solid fa-eye"></i></button>
                </div>
            </div>
            <ul class="idep-pwd-checklist" aria-live="polite">
                <li id="idep-chk-len"   class="idep-chk"><i class="fa-solid fa-circle" aria-hidden="true"></i> Mínimo 8 caracteres</li>
                <li id="idep-chk-upper" class="idep-chk"><i class="fa-solid fa-circle" aria-hidden="true"></i> Uma letra maiúscula</li>
                <li id="idep-chk-lower" class="idep-chk"><i class="fa-solid fa-circle" aria-hidden="true"></i> Uma letra minúscula</li>
                <li id="idep-chk-num"   class="idep-chk"><i class="fa-solid fa-circle" aria-hidden="true"></i> Um número</li>
                <li id="idep-chk-spec"  class="idep-chk"><i class="fa-solid fa-circle" aria-hidden="true"></i> Um caractere especial</li>
                <li id="idep-chk-match" class="idep-chk"><i class="fa-solid fa-circle" aria-hidden="true"></i> Senhas coincidem</li>
            </ul>
            <div id="idep-pwd-feedback" style="display:none;margin-top:4px;font-size:.9rem;padding:10px 14px;border-radius:8px;"></div>
        </div>
        <div class="idep-modal-footer">
            <button class="idep-btn-secondary" id="idep-pwd-cancel">Cancelar</button>
            <button class="idep-btn-primary"   id="idep-btn-pwd-save" disabled><i class="fa-solid fa-key"></i> Alterar senha</button>
        </div>
    </div>
</div>

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

    // ── Profile & Change Password ─────────────────────────────────────────
    var currentUser = null;

    function el(id) { return document.getElementById(id); }

    function showModal(id)  { var m = el(id); if (m) { m.removeAttribute('aria-hidden'); m.classList.add('show'); } }
    function hideModal(id)  { var m = el(id); if (m) { m.setAttribute('aria-hidden','true'); m.classList.remove('show'); } }

    function sanitizeAvatarUrl(url) {
        if (!url || typeof url !== 'string') return '';
        try {
            var p = new URL(url, window.location.href);
            if (p.protocol !== 'https:' && p.protocol !== 'http:') return '';
            return encodeURI(decodeURI(url));
        } catch { return ''; }
    }

    function updateTopbarAvatar(url) {
        var img  = el('idep-avatar-img');
        var icon = el('idep-avatar-icon');
        if (!img || !icon) return;
        var safeUrl = sanitizeAvatarUrl(url);
        if (safeUrl && img.src && img.src.endsWith(safeUrl) && img.style.display !== 'none') return;
        if (safeUrl) { img.src = safeUrl; img.style.display = 'block'; icon.style.display = 'none'; }
        else     { img.style.display = 'none'; icon.style.display = ''; }
    }

    function loadCurrentUser() {
        fetch('/api/perfil', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.usuario) return;
                currentUser = data.usuario;

                // Atualiza avatar apenas se mudou (evita flash)
                updateTopbarAvatar(currentUser.url_avatar);

                // Atualiza nome apenas se tiver nome completo melhor que o username
                var heroName = el('idep-hero-name');
                if (heroName && currentUser.nome_completo) {
                    var primeiroNome = currentUser.nome_completo.trim().split(' ')[0];
                    if (primeiroNome && primeiroNome !== heroName.textContent) {
                        heroName.textContent = primeiroNome;
                    }
                }
            }).catch(function () {});
    }

    function setProfileEditMode(on) {
        el('idep-profile-view').style.display         = on ? 'none' : '';
        el('idep-profile-edit').style.display         = on ? '' : 'none';
        el('idep-profile-footer-view').style.display  = on ? 'none' : '';
        el('idep-profile-footer-edit').style.display  = on ? '' : 'none';
        el('idep-profile-feedback').style.display     = 'none';
        if (on && currentUser) {
            el('idep-edit-nome').value   = currentUser.nome_completo || '';
            el('idep-edit-avatar').value = currentUser.url_avatar    || '';
            setTimeout(function () { el('idep-edit-nome').focus(); }, 80);
        }
    }

    function showFeedback(id, msg, ok) {
        var f = el(id);
        if (!f) return;
        f.textContent = msg;
        f.style.display = 'block';
        f.style.background = ok ? 'rgba(74,222,128,.12)' : 'rgba(248,113,113,.12)';
        f.style.color = ok ? '#4ade80' : '#f87171';
        f.style.border = '1px solid ' + (ok ? 'rgba(74,222,128,.3)' : 'rgba(248,113,113,.3)');
    }

    function openProfileModal() {
        if (!currentUser) return;
        el('idep-pv-nome').textContent          = currentUser.nome_completo || '—';
        el('idep-pv-username').textContent      = currentUser.username      || '—';
        el('idep-pv-email').textContent         = currentUser.email         || '—';
        el('idep-pv-nivel').textContent         = currentUser.nivel_acesso  || '—';
        el('idep-pv-nome-header').textContent   = currentUser.nome_completo || '—';
        el('idep-pv-nivel-header').textContent  = currentUser.nivel_acesso  || '—';
        var avatar = currentUser.url_avatar || '';
        var pImg = el('idep-profile-avatar-img'), pIcon = el('idep-profile-avatar-icon');
        if (pImg && pIcon) {
            var safeAvatar = sanitizeAvatarUrl(avatar);
            if (safeAvatar) { pImg.src = safeAvatar; pImg.style.display = 'block'; pIcon.style.display = 'none'; }
            else        { pImg.style.display = 'none'; pIcon.style.display = ''; }
        }
        setProfileEditMode(false);
        showModal('idep-modal-profile');
    }

    async function saveProfile() {
        var nome   = (el('idep-edit-nome')?.value   || '').trim();
        var avatar = (el('idep-edit-avatar')?.value || '').trim();
        var btn    = el('idep-btn-edit-save');
        if (!nome) { showFeedback('idep-profile-feedback', 'Nome completo é obrigatório.', false); return; }
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando...';
        try {
            var res  = await fetch('/api/perfil', { method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nome_completo: nome, url_avatar: avatar || null }) });
            var data = await res.json();
            if (!res.ok) throw new Error(data.message || data.error || 'Erro ao salvar.');
            currentUser.nome_completo = nome;
            currentUser.url_avatar    = avatar || null;
            updateTopbarAvatar(avatar || null);
            el('idep-pv-nome').textContent        = nome;
            el('idep-pv-nome-header').textContent = nome;
            if (el('idep-profile-avatar-img') && avatar) {
                var safeAv = sanitizeAvatarUrl(avatar);
                if (safeAv) el('idep-profile-avatar-img').src = safeAv;
                el('idep-profile-avatar-img').style.display = 'block';
                el('idep-profile-avatar-icon').style.display = 'none';
            } else if (!avatar) {
                el('idep-profile-avatar-img').style.display = 'none';
                el('idep-profile-avatar-icon').style.display = '';
            }
            showFeedback('idep-profile-feedback', 'Perfil atualizado com sucesso!', true);
            setTimeout(function () { setProfileEditMode(false); }, 1200);
        } catch (e) {
            showFeedback('idep-profile-feedback', e.message, false);
        } finally {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Salvar';
        }
    }

    // ── Password checklist ────────────────────────────────────────────────
    var pwdRules = {
        'idep-chk-len':   function (v) { return v.length >= 8; },
        'idep-chk-upper': function (v) { return /[A-Z]/.test(v); },
        'idep-chk-lower': function (v) { return /[a-z]/.test(v); },
        'idep-chk-num':   function (v) { return /[0-9]/.test(v); },
        'idep-chk-spec':  function (v) { return /[^A-Za-z0-9]/.test(v); },
    };

    function validatePwd() {
        var newVal  = el('idep-pwd-new')?.value     || '';
        var confVal = el('idep-pwd-confirm')?.value || '';
        var allOk   = true;
        Object.keys(pwdRules).forEach(function (id) {
            var ok = pwdRules[id](newVal);
            var li = el(id);
            if (li) { li.classList.toggle('idep-chk-ok', ok); li.classList.toggle('idep-chk-fail', newVal.length > 0 && !ok); }
            if (!ok) allOk = false;
        });
        var matchOk = newVal.length > 0 && newVal === confVal;
        var matchLi = el('idep-chk-match');
        if (matchLi) {
            matchLi.classList.toggle('idep-chk-ok',   matchOk);
            matchLi.classList.toggle('idep-chk-fail', confVal.length > 0 && !matchOk);
        }
        if (!matchOk) allOk = false;
        var saveBtn = el('idep-btn-pwd-save');
        if (saveBtn) saveBtn.disabled = !(allOk && (el('idep-pwd-current')?.value || '').trim());
        return allOk;
    }

    function resetPwdModal() {
        ['idep-pwd-current','idep-pwd-new','idep-pwd-confirm'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        ['idep-chk-len','idep-chk-upper','idep-chk-lower','idep-chk-num','idep-chk-spec','idep-chk-match'].forEach(function (id) {
            var li = el(id); if (li) { li.classList.remove('idep-chk-ok','idep-chk-fail'); }
        });
        el('idep-pwd-feedback').style.display = 'none';
        el('idep-btn-pwd-save').disabled = true;
    }

    async function savePwd() {
        var current = el('idep-pwd-current')?.value || '';
        var newPwd  = el('idep-pwd-new')?.value     || '';
        var btn     = el('idep-btn-pwd-save');
        if (!validatePwd() || !current.trim()) return;
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Alterando...';
        el('idep-pwd-feedback').style.display = 'none';
        try {
            var res  = await fetch('/api/perfil/senha', { method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ senha_atual: current, nova_senha: newPwd }) });
            var data = await res.json();
            if (!res.ok) throw new Error(data.message || data.error || 'Erro ao alterar senha.');
            showFeedback('idep-pwd-feedback', 'Senha alterada com sucesso!', true);
            setTimeout(function () { hideModal('idep-modal-pwd'); }, 1500);
        } catch (e) {
            showFeedback('idep-pwd-feedback', e.message, false);
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-key"></i> Alterar senha';
        }
    }

    // ── Event wiring ──────────────────────────────────────────────────────
    el('idep-profile-btn')?.addEventListener('click', openProfileModal);
    el('idep-profile-close')?.addEventListener('click', function () { hideModal('idep-modal-profile'); });
    el('idep-btn-edit-profile')?.addEventListener('click', function () { setProfileEditMode(true); });
    el('idep-btn-edit-cancel')?.addEventListener('click', function () { setProfileEditMode(false); });
    el('idep-btn-edit-save')?.addEventListener('click', saveProfile);
    el('idep-btn-change-pwd')?.addEventListener('click', function () { hideModal('idep-modal-profile'); resetPwdModal(); showModal('idep-modal-pwd'); setTimeout(function(){ el('idep-pwd-current').focus(); }, 80); });
    el('idep-pwd-close')?.addEventListener('click',   function () { hideModal('idep-modal-pwd'); });
    el('idep-pwd-cancel')?.addEventListener('click',  function () { hideModal('idep-modal-pwd'); });
    el('idep-btn-pwd-save')?.addEventListener('click', savePwd);

    ['idep-pwd-new','idep-pwd-confirm','idep-pwd-current'].forEach(function (id) {
        el(id)?.addEventListener('input', validatePwd);
    });

    // Password eye toggles
    document.querySelectorAll('.idep-pwd-eye').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            // Whitelist de IDs permitidos
            var allowed = ['idep-pwd-current', 'idep-pwd-new', 'idep-pwd-confirm'];
            if (!targetId || !allowed.includes(targetId)) return;
            var inp = document.getElementById(targetId);
            if (!inp) return;
            var show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            var ic = btn.querySelector('i');
            if (ic) ic.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        });
    });

    loadCurrentUser();
})();
</script>
<script src="/assets/js/ide-projects.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/ide-projects.js') ?: time() ?>"></script>
</body>
</html>
