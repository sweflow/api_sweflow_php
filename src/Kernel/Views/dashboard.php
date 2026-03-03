<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo ?? 'Dashboard da API', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" style="width:32px;height:32px;border-radius:6px;object-fit:contain;vertical-align:middle;margin-right:8px;" />
                <?php else: ?>
                    <i class="fa-solid fa-gauge-high"></i>
                <?php endif; ?>
                Dashboard
            </div>
            <nav>
                <ul>
                    <li><a href="/"><i class="fa-solid fa-arrow-left"></i> Voltar</a></li>
                    <li><a href="#metrics"><i class="fa-solid fa-chart-line"></i> Métricas</a></li>
                    <li><a href="#modules"><i class="fa-solid fa-layer-group"></i> Módulos</a></li>
                    <li><a href="#routes"><i class="fa-solid fa-route"></i> Rotas</a></li>
                    <li><a href="#" id="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Sair</a></li>
                </ul>
            </nav>
        </aside>
        <main class="content">
            <section class="hero" id="metrics">
                <h1><i class="fa-solid fa-gauge-high"></i> <?= htmlspecialchars($titulo ?? 'Dashboard da API', ENT_QUOTES, 'UTF-8'); ?></h1>
                <p><?= htmlspecialchars($descricao ?? 'Monitoramento em tempo real do núcleo da API.', ENT_QUOTES, 'UTF-8'); ?></p>
            </section>

            <section class="card-grid">
                <div class="card metric">
                    <div class="metric-title"><i class="fa-solid fa-database"></i> Banco de dados</div>
                    <div class="metric-value" id="db-connection">--</div>
                    <div class="metric-meta" id="db-meta">Carregando...</div>
                </div>
                <div class="card metric">
                    <div class="metric-title"><i class="fa-solid fa-server"></i> Status do servidor</div>
                    <div class="metric-value" id="server-status">--</div>
                    <div class="metric-meta" id="server-meta">Carregando...</div>
                </div>
                <div class="card metric">
                    <div class="metric-title"><i class="fa-solid fa-users"></i> Usuários cadastrados</div>
                    <div class="metric-value" id="users-total">--</div>
                    <div class="metric-meta">Atualização em tempo real</div>
                </div>
            </section>

            <section class="card" id="features">
                <h2><i class="fa-solid fa-toggle-on"></i> Funcionalidades (módulos)</h2>
                <div class="toggle-grid" id="modules-toggle-list">Carregando...</div>
            </section>

            <section class="card" id="modules">
                <h2><i class="fa-solid fa-layer-group"></i> Módulos registrados</h2>
                <ul class="modules-list" id="modules-list">
                    <li>Carregando...</li>
                </ul>
            </section>

            <section class="card" id="routes">
                <h2><i class="fa-solid fa-route"></i> Rotas dos módulos</h2>
                <div id="routes-list">Carregando...</div>
            </section>
        </main>
    </div>

    <div class="modal-overlay" id="disable-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-power-off"></i> Desabilitar módulo</h2>
                <button class="modal-close" id="disable-close" aria-label="Fechar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <p id="disable-modal-text">Tem certeza que deseja desabilitar este módulo?</p>
            <div class="pill" style="margin: 12px 0;">
                <i class="fa-solid fa-layer-group"></i>
                <span id="disable-modal-name">--</span>
            </div>
            <div class="form-actions" style="justify-content: flex-end;">
                <button class="btn ghost" id="disable-cancel">Cancelar</button>
                <button class="btn primary" id="disable-confirm">Desabilitar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="protected-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-lock"></i> Módulo essencial</h2>
                <button class="modal-close" id="protected-modal-close" aria-label="Fechar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <p>O módulo <strong id="protected-modal-name">--</strong> é essencial para o sistema e não pode ser desabilitado.</p>
            <div class="form-actions" style="justify-content: flex-end;">
                <button class="btn primary" id="protected-modal-ok">Entendi</button>
            </div>
        </div>
    </div>

    <script src="/assets/dashboard.js"></script>
</body>
</html>
