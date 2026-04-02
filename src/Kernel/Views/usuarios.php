<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuarios - Sweflow</title>
    <link rel="stylesheet" href="/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/usuarios.css?v=<?= time() ?>">
</head>
<body>
<div class="container">
    <aside class="sidebar">
        <div class="logo">
            <?php if (!empty($logo_url)): ?>
                <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" />
            <?php else: ?>
                <i class="fa-solid fa-gauge-high"></i>
            <?php endif; ?>
            Dashboard
        </div>
        <nav>
            <ul>
                <li class="nav-section-label">Navegacao</li>
                <li><a href="/dashboard"><i class="fa-solid fa-arrow-left"></i> Voltar ao Dashboard</a></li>
                <li><a href="/"><i class="fa-solid fa-house"></i> Inicio</a></li>
                <li class="nav-divider"></li>
                <li class="nav-section-label">Usuarios</li>
                <li><a href="/dashboard/usuarios" aria-current="page" class="nav-active"><i class="fa-solid fa-users"></i> Gerenciar usuarios</a></li>
                <li class="nav-divider"></li>
                <li class="nav-section-label">Configuracao</li>
                <li><a href="/modules/marketplace"><i class="fa-solid fa-store"></i> Marketplace</a></li>
                <li class="nav-divider"></li>
                <li><a href="#" id="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Sair</a></li>
            </ul>
        </nav>
    </aside>

    <main class="content">
        <div class="u-page-header">
            <div>
                <h1 class="u-page-title"><i class="fa-solid fa-users"></i> Gerenciar Usuarios</h1>
                <p class="u-page-sub">Visualize, edite e gerencie todos os usuarios da plataforma.</p>
            </div>
            <div id="u-header-stats" class="u-header-stats"></div>
        </div>

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
        <div class="u-toolbar card">
            <div class="u-search-box">
                <i class="fa-solid fa-magnifying-glass u-search-icon"></i>
                <input type="search" id="search-input" class="u-search-input"
                       placeholder="Buscar por username ou e-mail..." autocomplete="off" />
            </div>
            <select id="filter-nivel" class="u-select">
                <option value="">Todos os niveis</option>
                <option value="usuario">Usuario</option>
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
        <div class="card u-table-card">
            <div class="u-table-wrap">
                <table class="u-table" id="usuarios-table">
                    <thead>
                        <tr>
                            <th class="u-th-cb"><input type="checkbox" id="select-all-th" class="u-cb" /></th>
                            <th>Usuario</th>
                            <th class="u-hide-sm">E-mail</th>
                            <th>Nivel</th>
                            <th>Status</th>
                            <th class="u-hide-md">Cadastro</th>
                            <th class="u-th-actions">Acoes</th>
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
</body>
</html>
