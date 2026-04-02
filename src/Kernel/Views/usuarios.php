<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários — Sweflow</title>
    <link rel="stylesheet" href="/style.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/usuarios.css?v=2">
    <style>
        html, body { margin: 0; padding: 0; }
        html.will-dark, html.will-dark body, html.will-dark .dash-body { background: #0b0d18 !important; color: #f1f5f9 !important; }
    </style>
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        if (localStorage.getItem('dash-dark-mode') === '1') {
            document.documentElement.classList.add('will-dark', 'dash-no-transition');
        } else {
            document.documentElement.classList.add('dash-no-transition');
        }
        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('dash-dark-mode') === '1') document.body.classList.add('dark');
            document.documentElement.classList.remove('will-dark');
            requestAnimationFrame(function() { requestAnimationFrame(function() { document.documentElement.classList.remove('dash-no-transition'); }); });
        });
    </script>
</head>
<body class="dash-body">

<!-- TOPBAR -->
<header class="dash-topbar" id="dash-topbar">
    <div class="dash-topbar-left">
        <button class="dash-sidebar-toggle" id="sidebar-toggle" aria-label="Menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <a href="/" class="dash-brand">
            <?php if (!empty($logo_url)): ?>
                <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="dash-brand-img" />
            <?php else: ?>
                <img src="/favicon.ico" alt="Sweflow" class="dash-brand-img" />
            <?php endif; ?>
            <span class="dash-brand-name">Sweflow <span class="dash-brand-accent">API</span></span>
        </a>
    </div>

    <nav class="dash-topbar-nav" aria-label="Navegação principal">
        <div class="dash-dropdown">
            <button class="dash-dropdown-btn" data-dropdown="monitor">
                <i class="fa-solid fa-chart-line"></i> Monitoramento
                <i class="fa-solid fa-chevron-down dash-dd-arrow"></i>
            </button>
            <div class="dash-dropdown-menu" id="dd-monitor">
                <a href="/dashboard#metrics"  class="dash-dd-item"><i class="fa-solid fa-gauge-high"></i> Métricas</a>
                <a href="/dashboard#modules"  class="dash-dd-item"><i class="fa-solid fa-layer-group"></i> Módulos</a>
                <a href="/dashboard#routes"   class="dash-dd-item"><i class="fa-solid fa-route"></i> Rotas</a>
                <a href="/dashboard#features" class="dash-dd-item"><i class="fa-solid fa-toggle-on"></i> Funcionalidades</a>
            </div>
        </div>
        <div class="dash-dropdown">
            <button class="dash-dropdown-btn" data-dropdown="config">
                <i class="fa-solid fa-gear"></i> Configuração
                <i class="fa-solid fa-chevron-down dash-dd-arrow"></i>
            </button>
            <div class="dash-dropdown-menu" id="dd-config">
                <a href="/dashboard#capabilities"  class="dash-dd-item"><i class="fa-solid fa-plug"></i> Capacidades</a>
                <a href="/modules/marketplace"     class="dash-dd-item"><i class="fa-solid fa-store"></i> Marketplace</a>
                <a href="/dashboard#email-actions" class="dash-dd-item"><i class="fa-solid fa-envelope"></i> E-mail</a>
            </div>
        </div>
        <div class="dash-dropdown">
            <button class="dash-dropdown-btn" data-dropdown="conta">
                <i class="fa-solid fa-circle-user"></i> Conta
                <i class="fa-solid fa-chevron-down dash-dd-arrow"></i>
            </button>
            <div class="dash-dropdown-menu" id="dd-conta">
                <a href="/dashboard/usuarios" class="dash-dd-item"><i class="fa-solid fa-users"></i> Usuários</a>
                <div class="dash-dd-divider"></div>
                <a href="#" id="logout-btn" class="dash-dd-item dash-dd-danger"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
            </div>
        </div>
    </nav>

    <div class="dash-topbar-right">
        <button class="dash-theme-toggle" id="theme-toggle" aria-label="Alternar tema" title="Alternar dark/light mode">
            <div class="dash-theme-toggle-thumb" id="theme-thumb">
                <i class="fa-solid fa-moon" id="theme-icon"></i>
            </div>
        </button>
        <div class="dash-avatar-wrap" id="topbar-avatar-wrap">
            <div class="dash-avatar" id="topbar-avatar" title="Meu perfil" role="button" tabindex="0" aria-label="Meu perfil">
                <i class="fa-solid fa-circle-user"></i>
            </div>
            <span class="dash-avatar-status"></span>
        </div>
    </div>
</header>

<div class="dash-layout">
    <!-- SIDEBAR -->
    <aside class="dash-sidebar" id="dash-sidebar">
        <div class="dash-sidebar-inner">
            <nav class="dash-sidenav">
                <div class="dash-sidenav-section">
                    <span class="dash-sidenav-label">Conta</span>
                    <a href="/dashboard/usuarios"  class="dash-sidenav-link dash-sidenav-active"><i class="fa-solid fa-users"></i> Usuários</a>
                </div>
                <div class="dash-sidenav-section">
                    <a href="/dashboard" class="dash-sidenav-link"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                    <a href="/" class="dash-sidenav-link"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a>
                    <a href="#" id="sb-logout" class="dash-sidenav-link dash-sidenav-danger"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
                </div>
            </nav>
        </div>
    </aside>
    <div class="dash-sidebar-backdrop" id="sidebar-backdrop"></div>

    <main class="dash-main">

        <!-- Hero -->
        <section class="dash-hero">
            <div class="dash-hero-text">
                <h1 class="dash-hero-title"><i class="fa-solid fa-users"></i> Gerenciar Usuários</h1>
                <p class="dash-hero-sub">Visualize, edite e gerencie todos os usuários da plataforma.</p>
            </div>
            <div id="u-header-stats" class="dash-hero-actions"></div>
        </section>

        <!-- Bulk bar -->
        <div class="u-bulk-bar" id="bulk-bar">
            <i class="fa-solid fa-check-square"></i>
            <span id="bulk-count">0 selecionados</span>
            <button class="u-btn u-btn-danger" id="bulk-delete-btn">
                <i class="fa-solid fa-trash"></i> Excluir selecionados
            </button>
            <button class="u-btn u-btn-ghost" id="bulk-cancel-btn">
                <i class="fa-solid fa-xmark"></i> Cancelar
            </button>
        </div>

        <!-- Toolbar -->
        <div class="dash-card u-toolbar">
            <div class="u-search-box">
                <i class="fa-solid fa-magnifying-glass u-search-icon"></i>
                <input type="search" id="search-input" class="u-search-input"
                       placeholder="Buscar por username ou e-mail..." autocomplete="off" />
            </div>
            <select id="filter-nivel" class="u-select">
                <option value="">Todos os níveis</option>
                <option value="usuario">Usuário</option>
                <option value="moderador">Moderador</option>
                <option value="admin">Admin</option>
                <option value="admin_system">Admin Sistema</option>
            </select>
            <label class="u-select-all-label">
                <input type="checkbox" id="select-all-cb" class="u-cb" />
                Selecionar todos
            </label>
        </div>

        <!-- Table card -->
        <div class="dash-card u-table-card">
            <div class="u-table-wrap">
                <table class="u-table" id="usuarios-table">
                    <thead>
                        <tr>
                            <th class="u-th-cb"><input type="checkbox" id="select-all-th" class="u-cb" /></th>
                            <th>Usuário</th>
                            <th class="u-hide-sm">E-mail</th>
                            <th>Nível</th>
                            <th>Status</th>
                            <th class="u-hide-md">Cadastro</th>
                            <th class="u-th-actions">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="usuarios-tbody">
                        <tr><td colspan="7" class="u-loading-cell">
                            <i class="fa-solid fa-circle-notch fa-spin"></i> Carregando...
                        </td></tr>
                    </tbody>
                </table>
            </div>
            <div class="u-table-footer">
                <div class="u-pagination" id="pagination"></div>
                <span class="u-page-info" id="page-info"></span>
            </div>
        </div>

    </main>
</div>

<!-- Modal: Detalhe -->
<div class="modal-overlay" id="detail-modal" aria-hidden="true">
    <div class="modal u-detail-modal" role="dialog" aria-modal="true" aria-labelledby="detail-title">
        <div class="modal-header">
            <h2 id="detail-title"><i class="fa-solid fa-circle-user"></i> Detalhes do usuario</h2>
            <button class="modal-close" id="detail-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="detail-content">
            <div class="u-detail-hero">
                <div id="detail-avatar-wrap" class="u-detail-avatar-ph"><i class="fa-solid fa-user"></i></div>
                <div class="u-detail-hero-info">
                    <div class="u-detail-name" id="detail-nome">--</div>
                    <div class="u-detail-username" id="detail-username-label">--</div>
                    <div id="detail-nivel-badge"></div>
                </div>
            </div>
            <div id="detail-capa-wrap" style="display:none;">
                <img class="u-detail-capa" id="detail-capa" src="" alt="Capa de perfil" />
            </div>
            <div class="u-detail-grid" id="detail-grid"></div>
            <div id="detail-bio-wrap" hidden class="u-detail-bio-wrap">
                <span class="u-field-label">Biografia</span>
                <p id="detail-bio" class="u-detail-bio-text"></p>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Nivel -->
<div class="modal-overlay" id="nivel-modal" aria-hidden="true">
    <div class="modal" style="width:min(420px,95vw);" role="dialog" aria-modal="true">
        <div class="modal-header">
            <h2><i class="fa-solid fa-user-shield"></i> Alterar nivel de acesso</h2>
            <button class="modal-close" id="nivel-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p class="u-modal-sub">Usuario: <strong id="nivel-username">--</strong></p>
        <div class="input-group">
            <label for="nivel-select-input">Novo nivel de acesso</label>
            <select id="nivel-select-input" class="u-select" style="width:100%;padding:12px;">
                <option value="usuario">Usuario</option>
                <option value="moderador">Moderador</option>
                <option value="admin">Admin</option>
                <option value="admin_system">Admin Sistema</option>
            </select>
        </div>
        <div class="form-actions" style="margin-top:16px;justify-content:flex-end;">
            <button class="btn ghost" id="nivel-cancel">Cancelar</button>
            <button class="btn primary" id="nivel-confirm"><i class="fa-solid fa-check"></i> Salvar</button>
        </div>
        <div id="nivel-feedback" class="login-feedback" style="margin-top:8px;"></div>
    </div>
</div>

<!-- Modal: Excluir unico -->
<div class="modal-overlay" id="delete-modal" aria-hidden="true">
    <div class="modal" style="width:min(460px,95vw);" role="dialog" aria-modal="true">
        <div class="modal-header">
            <h2 class="u-danger-title"><i class="fa-solid fa-triangle-exclamation"></i> Excluir usuario</h2>
            <button class="modal-close" id="delete-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="u-danger-box">
            <i class="fa-solid fa-skull-crossbones"></i>
            <p>Esta acao e <strong>irreversivel</strong>. Todos os dados do usuario serao permanentemente excluidos, incluindo publicacoes, seguidores, notificacoes e historico.</p>
        </div>
        <p class="u-confirm-label">Para confirmar, digite o username <strong id="delete-username-label" class="u-danger-text"></strong> abaixo:</p>
        <input type="text" class="u-confirm-input" id="delete-confirm-input" placeholder="Digite o username para confirmar" autocomplete="off" />
        <div class="form-actions" style="margin-top:16px;justify-content:flex-end;">
            <button class="btn ghost" id="delete-cancel">Cancelar</button>
            <button class="u-btn u-btn-danger" id="delete-confirm-btn" disabled>
                <i class="fa-solid fa-trash"></i> Excluir permanentemente
            </button>
        </div>
        <div id="delete-feedback" class="login-feedback" style="margin-top:8px;"></div>
    </div>
</div>

<!-- Modal: Bulk delete -->
<div class="modal-overlay" id="bulk-delete-modal" aria-hidden="true">
    <div class="modal" style="width:min(480px,95vw);" role="dialog" aria-modal="true">
        <div class="modal-header">
            <h2 class="u-danger-title"><i class="fa-solid fa-triangle-exclamation"></i> Excluir multiplos usuarios</h2>
            <button class="modal-close" id="bulk-delete-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="u-danger-box">
            <i class="fa-solid fa-skull-crossbones"></i>
            <p>Voce esta prestes a excluir <strong id="bulk-delete-count">0</strong> usuarios. Esta acao e <strong>irreversivel</strong>. Todos os dados relacionados serao permanentemente removidos.</p>
        </div>
        <p class="u-confirm-label">Digite <strong class="u-danger-text">CONFIRMAR</strong> para prosseguir:</p>
        <input type="text" class="u-confirm-input" id="bulk-confirm-input" placeholder="Digite CONFIRMAR" autocomplete="off" />
        <div class="form-actions" style="margin-top:16px;justify-content:flex-end;">
            <button class="btn ghost" id="bulk-delete-cancel">Cancelar</button>
            <button class="u-btn u-btn-danger" id="bulk-delete-confirm-btn" disabled>
                <i class="fa-solid fa-trash"></i> Excluir todos
            </button>
        </div>
        <div id="bulk-delete-feedback" class="login-feedback" style="margin-top:8px;"></div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="u-toast" role="alert" aria-live="polite">
    <i id="toast-icon" class="fa-solid fa-circle-check"></i>
    <span id="toast-msg"></span>
</div>

<script src="/assets/usuarios.js?v=<?= time() ?>"></script>
<script src="/assets/nav-init.js"></script>
</body>
</html>
