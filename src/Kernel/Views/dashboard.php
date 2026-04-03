<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo ?? 'Dashboard', ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        html, body { margin: 0; padding: 0; }
        html.will-dark, html.will-dark body, html.will-dark .dash-body {
            background: #0b0d18 !important;
            color: #f1f5f9 !important;
        }
    </style>
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        if (localStorage.getItem('dash-dark-mode') === '1') {
            document.documentElement.classList.add('will-dark', 'dash-no-transition');
        } else {
            document.documentElement.classList.add('dash-no-transition');
        }
        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('dash-dark-mode') === '1') {
                document.body.classList.add('dark');
                var icon = document.getElementById('theme-icon');
                if (icon) icon.className = 'fa-solid fa-sun';
            }
            document.documentElement.classList.remove('will-dark');
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    document.documentElement.classList.remove('dash-no-transition');
                });
            });
        });
    </script>
    <link rel="stylesheet" href="/style.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="dash-body">

<!-- ── TOPBAR ─────────────────────────────────────────────────────── -->
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
                <a href="#metrics"  class="dash-dd-item"><i class="fa-solid fa-gauge-high"></i> Métricas</a>
                <a href="#modules"  class="dash-dd-item"><i class="fa-solid fa-layer-group"></i> Módulos</a>
                <a href="#routes"   class="dash-dd-item"><i class="fa-solid fa-route"></i> Rotas</a>
                <a href="#features" class="dash-dd-item"><i class="fa-solid fa-toggle-on"></i> Funcionalidades</a>
            </div>
        </div>
        <div class="dash-dropdown">
            <button class="dash-dropdown-btn" data-dropdown="config">
                <i class="fa-solid fa-gear"></i> Configuração
                <i class="fa-solid fa-chevron-down dash-dd-arrow"></i>
            </button>
            <div class="dash-dropdown-menu" id="dd-config">
                <a href="#capabilities"        class="dash-dd-item"><i class="fa-solid fa-plug"></i> Capacidades</a>
                <a href="/modules/marketplace" class="dash-dd-item"><i class="fa-solid fa-store"></i> Marketplace</a>
                <a href="#email-actions"       class="dash-dd-item"><i class="fa-solid fa-envelope"></i> E-mail</a>
            </div>
        </div>
        <div class="dash-dropdown">
            <button class="dash-dropdown-btn" data-dropdown="conta">
                <i class="fa-solid fa-circle-user"></i> Conta
                <i class="fa-solid fa-chevron-down dash-dd-arrow"></i>
            </button>
            <div class="dash-dropdown-menu" id="dd-conta">
                <a href="/dashboard/usuarios"   class="dash-dd-item"><i class="fa-solid fa-users"></i> Usuários</a>
                <a href="#" id="open-meu-perfil"    class="dash-dd-item"><i class="fa-solid fa-circle-user"></i> Meu perfil</a>
                <a href="#" id="open-criar-usuario" class="dash-dd-item"><i class="fa-solid fa-user-plus"></i> Novo usuário</a>
                <div class="dash-dd-divider"></div>
                <button type="button" id="logout-btn" class="dash-dd-item dash-dd-danger"><i class="fa-solid fa-right-from-bracket"></i> Sair</button>
            </div>
        </div>
    </nav>

    <div class="dash-topbar-right">
        <button class="dash-topbar-icon-btn" id="open-email-modal" title="Enviar e-mail" aria-label="Enviar e-mail">
            <i class="fa-solid fa-paper-plane"></i>
        </button>
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

<!-- ── LAYOUT ─────────────────────────────────────────────────────── -->
<div class="dash-layout">

    <!-- SIDEBAR -->
    <aside class="dash-sidebar" id="dash-sidebar">
        <div class="dash-sidebar-inner">
            <nav class="dash-sidenav">
                <div class="dash-sidenav-section">
                    <span class="dash-sidenav-label">Monitoramento</span>
                    <a href="#metrics"  class="dash-sidenav-link"><i class="fa-solid fa-gauge-high"></i> Métricas</a>
                    <a href="#modules"  class="dash-sidenav-link"><i class="fa-solid fa-layer-group"></i> Módulos</a>
                    <a href="#routes"   class="dash-sidenav-link"><i class="fa-solid fa-route"></i> Rotas</a>
                    <a href="#features" class="dash-sidenav-link"><i class="fa-solid fa-toggle-on"></i> Funcionalidades</a>
                </div>
                <div class="dash-sidenav-section">
                    <span class="dash-sidenav-label">Configuração</span>
                    <a href="#capabilities"        class="dash-sidenav-link"><i class="fa-solid fa-plug"></i> Capacidades</a>
                    <a href="/modules/marketplace" class="dash-sidenav-link"><i class="fa-solid fa-store"></i> Marketplace</a>
                    <a href="#email-actions"        class="dash-sidenav-link"><i class="fa-solid fa-envelope"></i> E-mail</a>
                </div>
                <div class="dash-sidenav-section">
                    <span class="dash-sidenav-label">Conta</span>
                    <a href="/dashboard/usuarios"   class="dash-sidenav-link"><i class="fa-solid fa-users"></i> Usuários</a>
                    <a href="#" id="sb-meu-perfil"  class="dash-sidenav-link"><i class="fa-solid fa-circle-user"></i> Meu perfil</a>
                    <a href="#" id="sb-criar-user"  class="dash-sidenav-link"><i class="fa-solid fa-user-plus"></i> Novo usuário</a>
                </div>
                <div class="dash-sidenav-section">
                    <a href="/" class="dash-sidenav-link"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a>
                    <button type="button" id="sb-logout" class="dash-sidenav-link dash-sidenav-danger"><i class="fa-solid fa-right-from-bracket"></i> Sair</button>
                </div>
            </nav>
        </div>
    </aside>
    <div class="dash-sidebar-backdrop" id="sidebar-backdrop"></div>

    <!-- MAIN -->
    <main class="dash-main">

        <!-- Hero -->
        <section class="dash-hero" id="metrics">
            <div class="dash-hero-text">
                <div class="dash-hero-brand">
                    <img src="/favicon.ico" alt="Sweflow API" class="dash-hero-logo" />
                    <span class="dash-hero-brand-name">Sweflow <span style="color:#818cf8">API</span></span>
                </div>
                <h1 class="dash-hero-title">Olá, <span id="hero-username">...</span> 👋</h1>
                <p class="dash-hero-sub">Monitoramento em tempo real do núcleo da API.</p>
            </div>
        </section>

        <!-- Metric cards -->
        <div class="dash-cards" id="dash-metric-cards">
            <div class="dash-card dash-metric-card">
                <div class="dash-metric-icon db"><i class="fa-solid fa-database"></i></div>
                <div class="dash-metric-body">
                    <span class="dash-metric-label">Banco de dados</span>
                    <span class="dash-metric-value" id="db-connection">--</span>
                    <span class="dash-metric-meta" id="db-meta">Verificando...</span>
                </div>
            </div>
            <div class="dash-card dash-metric-card">
                <div class="dash-metric-icon server"><i class="fa-solid fa-server"></i></div>
                <div class="dash-metric-body">
                    <span class="dash-metric-label">Servidor</span>
                    <span class="dash-metric-value" id="server-status">--</span>
                    <span class="dash-metric-meta" id="server-meta">Verificando...</span>
                </div>
            </div>
            <div class="dash-card dash-metric-card">
                <div class="dash-metric-icon users"><i class="fa-solid fa-users"></i></div>
                <div class="dash-metric-body">
                    <span class="dash-metric-label">Usuários</span>
                    <span class="dash-metric-value" id="users-total">--</span>
                    <span class="dash-metric-meta">Cadastrados</span>
                </div>
            </div>
        </div>

        <!-- E-mail actions -->
        <section class="dash-card dash-section" id="email-actions">
            <div class="dash-section-header">
                <div>
                    <h2 class="dash-section-title"><i class="fa-solid fa-envelope"></i> Disparo de e-mail <span id="email-module-state" class="dash-badge">Carregando...</span></h2>
                    <p class="dash-section-sub">Envie comunicações de confirmação, recuperação de senha ou mensagens customizadas.</p>
                </div>
                <div class="dash-section-actions">
                    <button class="dash-btn-ghost" id="open-email-history"><i class="fa-solid fa-clock-rotate-left"></i> Histórico</button>
                    <button class="dash-btn-primary" id="open-email-modal2"><i class="fa-solid fa-paper-plane"></i> Enviar e-mail</button>
                </div>
            </div>
        </section>

        <!-- Funcionalidades -->
        <section class="dash-card dash-section" id="features">
            <div class="dash-section-header">
                <h2 class="dash-section-title"><i class="fa-solid fa-toggle-on"></i> Funcionalidades</h2>
            </div>
            <div class="toggle-grid" id="modules-toggle-list"><span class="dash-loading">Carregando...</span></div>
            <div class="toggle-card" id="auth-verify-card" style="margin-top:12px;">
                <div class="toggle-info">
                    <span class="toggle-name">Login só após e-mail verificado</span>
                    <span class="toggle-tag" id="auth-verify-tag">Carregando...</span>
                    <small style="color:#64748b;">Exige <code>verificado_email = true</code> para novos logins.</small>
                </div>
                <label class="switch">
                    <input type="checkbox" id="require-email-verification" />
                    <span class="slider"></span>
                </label>
            </div>
        </section>

        <!-- Módulos -->
        <section class="dash-card dash-section" id="modules">
            <div class="dash-section-header">
                <h2 class="dash-section-title"><i class="fa-solid fa-layer-group"></i> Módulos registrados</h2>
            </div>
            <div id="modules-list" class="module-grid"><span class="dash-loading">Carregando...</span></div>
        </section>

        <!-- Rotas -->
        <section class="dash-card dash-section" id="routes">
            <div class="dash-section-header">
                <h2 class="dash-section-title"><i class="fa-solid fa-route"></i> Rotas dos módulos</h2>
            </div>
            <div id="routes-list" style="overflow-x:auto;"><span class="dash-loading">Carregando...</span></div>
        </section>

        <!-- Capacidades -->
        <section class="dash-card dash-section" id="capabilities">
            <div class="dash-section-header">
                <h2 class="dash-section-title"><i class="fa-solid fa-plug"></i> Capacidades</h2>
            </div>
            <div id="capabilities-list"><span class="dash-loading">Carregando...</span></div>
        </section>

    </main>
</div><!-- /.dash-layout -->

<!-- ══════════════════════════════════════════════════════════
     MODAIS (mantidas do original, apenas classes atualizadas)
     ══════════════════════════════════════════════════════════ -->

<!-- Detalhe da rota -->
<div class="modal-overlay" id="route-detail-modal" aria-hidden="true">
    <div class="modal dash-modal rd-modal" role="dialog" aria-modal="true" aria-labelledby="rd-modal-title">
        <div class="modal-header">
            <h2 id="rd-modal-title"><i class="fa-solid fa-route"></i> Detalhes da rota</h2>
            <button class="modal-close" id="route-detail-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="route-detail-body" style="overflow-y:auto;max-height:72vh;padding-top:4px;"></div>
    </div>
</div>

<!-- Desabilitar módulo -->
<div class="modal-overlay" id="disable-modal">
    <div class="modal dash-modal">
        <div class="modal-header"><h2><i class="fa-solid fa-power-off"></i> Desabilitar módulo</h2>
            <button class="modal-close" id="disable-close"><i class="fa-solid fa-xmark"></i></button></div>
        <p id="disable-modal-text">Tem certeza que deseja desabilitar este módulo?</p>
        <div class="pill" style="margin:12px 0;"><i class="fa-solid fa-layer-group"></i> <span id="disable-modal-name">--</span></div>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="disable-cancel">Cancelar</button>
            <button class="btn primary" id="disable-confirm">Desabilitar</button>
        </div>
    </div>
</div>

<!-- Módulo protegido -->
<div class="modal-overlay" id="protected-modal">
    <div class="modal dash-modal">
        <div class="modal-header"><h2><i class="fa-solid fa-lock"></i> Módulo essencial</h2>
            <button class="modal-close" id="protected-modal-close"><i class="fa-solid fa-xmark"></i></button></div>
        <p>O módulo <strong id="protected-modal-name">--</strong> é essencial e não pode ser desabilitado.</p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn primary" id="protected-modal-ok">Entendi</button>
        </div>
    </div>
</div>

<!-- E-mail desabilitado -->
<div class="modal-overlay" id="email-disabled-modal">
    <div class="modal dash-modal">
        <div class="modal-header"><h2><i class="fa-solid fa-ban"></i> Módulo desabilitado</h2>
            <button class="modal-close" id="email-disabled-close"><i class="fa-solid fa-xmark"></i></button></div>
        <p>O módulo de E-mail está desabilitado. Habilite em "Funcionalidades" para usar os envios.</p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn primary" id="email-disabled-ok">Entendi</button>
        </div>
    </div>
</div>

<!-- Erro genérico -->
<div class="modal-overlay" id="error-modal">
    <div class="modal dash-modal">
        <div class="modal-header"><h2 id="error-modal-title">Erro</h2>
            <button class="modal-close" id="error-modal-close"><i class="fa-solid fa-xmark"></i></button></div>
        <p id="error-modal-message"></p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn primary" id="error-modal-ok">OK</button>
        </div>
    </div>
</div>

<!-- E-mail composer -->
<div class="modal-overlay" id="email-modal">
    <div class="modal email-modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-envelope"></i> Enviar e-mail personalizado</h2>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="btn ghost" id="email-preview-btn" type="button"><i class="fa-solid fa-eye"></i> Pré-visualizar</button>
                <button class="btn ghost" id="email-fullscreen-btn" type="button"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
                <button class="modal-close" id="email-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <form id="email-form" class="email-form" autocomplete="off">
            <div class="input-group"><label for="email-to">Para</label>
                <input type="text" id="email-to" placeholder="email@exemplo.com ou vários separados por vírgula" /></div>
            <div class="input-group"><label for="email-subject">Assunto</label>
                <input type="text" id="email-subject" placeholder="Assunto do e-mail" required /></div>
            <div class="input-group"><label for="email-logo">Logo (URL opcional)</label>
                <input type="url" id="email-logo" placeholder="https://.../logo.png" /></div>
            <div class="email-toolbar" id="email-toolbar">
                <button type="button" data-cmd="bold"><i class="fa-solid fa-bold"></i></button>
                <button type="button" data-cmd="italic"><i class="fa-solid fa-italic"></i></button>
                <button type="button" data-cmd="underline"><i class="fa-solid fa-underline"></i></button>
                <button type="button" data-cmd="strikeThrough"><i class="fa-solid fa-strikethrough"></i></button>
                <button type="button" data-cmd="insertOrderedList"><i class="fa-solid fa-list-ol"></i></button>
                <button type="button" data-cmd="insertUnorderedList"><i class="fa-solid fa-list-ul"></i></button>
                <button type="button" data-cmd="formatBlock" data-value="blockquote"><i class="fa-solid fa-quote-left"></i></button>
                <button type="button" data-cmd="formatBlock" data-value="pre"><i class="fa-solid fa-code"></i></button>
                <button type="button" data-cmd="align-left"><i class="fa-solid fa-align-left"></i></button>
                <button type="button" data-cmd="align-center"><i class="fa-solid fa-align-center"></i></button>
                <button type="button" data-cmd="align-right"><i class="fa-solid fa-align-right"></i></button>
                <button type="button" data-cmd="createLink"><i class="fa-solid fa-link"></i></button>
                <button type="button" data-cmd="insertImage"><i class="fa-solid fa-image"></i></button>
                <select id="email-font-size" aria-label="Tamanho da fonte">
                    <option value="">Tam.</option>
                    <option value="12">12px</option><option value="14">14px</option>
                    <option value="18">18px</option><option value="22">22px</option>
                    <option value="28">28px</option><option value="36">36px</option>
                </select>
                <label class="color-picker">Cor <input type="color" id="email-font-color" /></label>
                <label class="color-picker">Fundo <input type="color" id="email-bg-color" /></label>
            </div>
            <div class="email-editor" id="email-editor" contenteditable="true" aria-label="Editor de e-mail"></div>
            <div class="email-preview" id="email-preview" hidden></div>
            <div id="email-feedback" class="login-feedback" aria-live="polite"></div>
        </form>
        <div class="email-modal-footer">
            <button type="button" class="btn ghost" id="email-cancel">Cancelar</button>
            <button type="button" class="btn ghost" id="email-draft"><i class="fa-solid fa-floppy-disk"></i> Rascunho</button>
            <button type="button" class="btn primary" id="email-send" onclick="document.getElementById('email-form').requestSubmit()"><i class="fa-solid fa-paper-plane"></i> Enviar</button>
        </div>
    </div>
</div>

<!-- Link modal -->
<div class="modal-overlay" id="link-modal">
    <div class="modal dash-modal">
        <div class="modal-header"><h2><i class="fa-solid fa-link"></i> Inserir link</h2>
            <button class="modal-close" id="link-close"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="input-group"><label for="link-url">URL</label>
            <input type="url" id="link-url" placeholder="https://exemplo.com" /></div>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="link-cancel">Cancelar</button>
            <button class="btn primary" id="link-confirm">Inserir</button>
        </div>
    </div>
</div>

<!-- Image modal -->
<div class="modal-overlay" id="image-modal">
    <div class="modal dash-modal">
        <div class="modal-header"><h2><i class="fa-solid fa-image"></i> Inserir imagem</h2>
            <button class="modal-close" id="image-close"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="input-group"><label for="image-url">URL da imagem</label>
            <input type="url" id="image-url" placeholder="https://exemplo.com/imagem.png" /></div>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="image-cancel">Cancelar</button>
            <button class="btn primary" id="image-confirm">Inserir</button>
        </div>
    </div>
</div>

<!-- Histórico de e-mails -->
<div class="modal-overlay" id="email-history-modal">
    <div class="modal email-history-modal" style="max-width:820px;width:95vw;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,rgba(79,70,229,0.2),rgba(124,58,237,0.15));display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-clock-rotate-left" style="color:#818cf8;font-size:1.2rem;"></i>
                </div>
                <div>
                    <h2 style="margin:0;font-size:1.25rem;font-weight:800;">Histórico de e-mails</h2>
                    <p style="margin:0;font-size:0.85rem;color:var(--text-muted,#64748b);">Registros de todos os disparos realizados</p>
                </div>
            </div>
            <button class="modal-close" id="email-history-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="padding:16px 0 8px;">
            <div style="position:relative;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;font-size:0.9rem;pointer-events:none;"></i>
                <input type="search" id="email-history-search"
                       placeholder="Buscar por assunto, destinatário ou status..."
                       style="width:100%;padding:11px 14px 11px 40px;border:1.5px solid var(--border-input,rgba(255,255,255,0.1));border-radius:12px;font-size:0.95rem;box-sizing:border-box;background:var(--bg-input,rgba(255,255,255,0.05));color:var(--text-primary,#f1f5f9);font-family:inherit;outline:none;transition:border-color .15s;" />
            </div>
        </div>
        <div id="email-history-list" style="max-height:58vh;overflow-y:auto;padding-right:2px;"></div>
    </div>
</div>

<!-- Detalhe e-mail -->
<div class="modal-overlay" id="email-detail-modal">
    <div class="modal email-modal" style="max-width:780px;width:95vw;">
        <div class="modal-header"><h2><i class="fa-solid fa-envelope-open-text"></i> Detalhes do e-mail</h2>
            <button class="modal-close" id="email-detail-close"><i class="fa-solid fa-xmark"></i></button></div>
        <div id="email-detail-body" style="overflow-y:auto;max-height:65vh;"></div>
        <div class="form-actions" style="justify-content:flex-end;margin-top:16px;gap:8px;">
            <button class="btn ghost" id="email-detail-edit"><i class="fa-solid fa-pen"></i> Editar e reenviar</button>
            <button class="btn ghost" id="email-detail-resend"><i class="fa-solid fa-rotate-right"></i> Reenviar</button>
            <button class="btn" style="background:#e74c3c;color:#fff;" id="email-detail-delete"><i class="fa-solid fa-trash"></i> Excluir</button>
        </div>
    </div>
</div>

<!-- Confirmar exclusão -->
<div class="modal-overlay" id="email-delete-modal">
    <div class="modal dash-modal">
        <div class="modal-header"><h2><i class="fa-solid fa-trash"></i> Excluir registro</h2>
            <button class="modal-close" id="email-delete-close"><i class="fa-solid fa-xmark"></i></button></div>
        <p>Tem certeza que deseja excluir este registro? Esta ação não pode ser desfeita.</p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="email-delete-cancel">Cancelar</button>
            <button class="btn" style="background:#e74c3c;color:#fff;" id="email-delete-confirm">Excluir</button>
        </div>
    </div>
</div>

<!-- Meu Perfil -->
<div class="modal-overlay" id="meu-perfil-modal">
    <div class="modal perfil-modal" style="max-width:680px;width:96vw;">
        <div class="modal-header perfil-modal-header">
            <h2><i class="fa-solid fa-circle-user"></i> Meu perfil</h2>
            <button class="modal-close" id="meu-perfil-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="meu-perfil-body" style="display:flex;flex-direction:column;gap:20px;"><p style="color:#888;text-align:center;padding:24px;">Carregando...</p></div>
        <div class="perfil-modal-actions">
            <button class="btn ghost perfil-action-btn" id="meu-perfil-alterar-senha"><i class="fa-solid fa-key"></i> Alterar senha</button>
            <button class="btn primary perfil-action-btn" id="meu-perfil-editar"><i class="fa-solid fa-pen"></i> Editar dados</button>
        </div>
    </div>
</div>

<!-- Editar Perfil -->
<div class="modal-overlay" id="editar-perfil-modal">
    <div class="modal perfil-modal" style="max-width:680px;width:96vw;">
        <div class="modal-header perfil-modal-header">
            <h2><i class="fa-solid fa-pen"></i> Editar dados</h2>
            <button class="modal-close" id="editar-perfil-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="editar-perfil-form" autocomplete="off" style="display:flex;flex-direction:column;gap:14px;overflow-y:auto;max-height:72vh;padding-right:2px;">
            <div class="input-group"><label for="ep-nome">Nome completo</label><input type="text" id="ep-nome" placeholder="Seu nome completo" /></div>
            <div class="input-group"><label for="ep-username">Username</label><input type="text" id="ep-username" placeholder="seu.username" autocomplete="off" /><small id="ep-username-feedback" class="hint"></small></div>
            <div class="input-group"><label for="ep-email">E-mail</label><input type="email" id="ep-email" placeholder="email@exemplo.com" /><small id="ep-email-feedback" class="hint"></small><small class="hint">Para alterar o e-mail, informe sua senha atual abaixo.</small></div>
            <div class="input-group" id="ep-senha-email-group" style="display:none;"><label for="ep-senha-email">Senha atual</label><input type="password" id="ep-senha-email" placeholder="Senha atual" autocomplete="current-password" /></div>
            <div class="input-group"><label for="ep-avatar">URL do avatar</label><input type="url" id="ep-avatar" placeholder="https://..." /><div id="ep-avatar-preview" style="margin-top:6px;display:none;"><img id="ep-avatar-img" src="" alt="Preview" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #e0e0e0;" /></div></div>
            <div class="input-group"><label for="ep-capa">URL da capa</label><input type="url" id="ep-capa" placeholder="https://..." /><div id="ep-capa-preview" style="margin-top:6px;display:none;"><img id="ep-capa-img" src="" alt="Preview capa" style="width:100%;max-height:80px;object-fit:cover;border-radius:8px;border:2px solid #e0e0e0;" /></div></div>
            <div class="input-group"><label for="ep-bio">Biografia</label><textarea id="ep-bio" rows="3" placeholder="Fale um pouco sobre você..." style="padding:10px;border:1px solid #d5daf2;border-radius:10px;font-size:1rem;resize:vertical;"></textarea></div>
            <div id="ep-feedback" class="login-feedback" aria-live="polite"></div>
        </form>
        <div class="perfil-modal-actions">
            <button type="button" class="btn ghost perfil-action-btn" id="editar-perfil-cancel">Cancelar</button>
            <button type="submit" form="editar-perfil-form" class="btn primary perfil-action-btn" id="editar-perfil-save"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
        </div>
    </div>
</div>

<!-- Alterar Senha -->
<div class="modal-overlay" id="alterar-senha-modal">
    <div class="modal" style="max-width:460px;width:95vw;">
        <div class="modal-header"><h2><i class="fa-solid fa-key"></i> Alterar senha</h2>
            <button class="modal-close" id="alterar-senha-close"><i class="fa-solid fa-xmark"></i></button></div>
        <form id="alterar-senha-form" autocomplete="off" style="display:flex;flex-direction:column;gap:14px;">
            <div class="input-group"><label for="as-atual">Senha atual</label><input type="password" id="as-atual" placeholder="Senha atual" autocomplete="current-password" /></div>
            <div class="input-group"><label for="as-nova">Nova senha</label><input type="password" id="as-nova" placeholder="Nova senha" autocomplete="new-password" /></div>
            <div class="input-group"><label for="as-confirmar">Confirmar nova senha</label><input type="password" id="as-confirmar" placeholder="Confirmar nova senha" autocomplete="new-password" /></div>
            <div id="as-regras" class="senha-regras">
                <div class="regra" id="as-r-len"><i class="fa-solid fa-circle-xmark"></i> Mínimo 8 caracteres</div>
                <div class="regra" id="as-r-upper"><i class="fa-solid fa-circle-xmark"></i> Uma letra maiúscula</div>
                <div class="regra" id="as-r-lower"><i class="fa-solid fa-circle-xmark"></i> Uma letra minúscula</div>
                <div class="regra" id="as-r-num"><i class="fa-solid fa-circle-xmark"></i> Um número</div>
                <div class="regra" id="as-r-special"><i class="fa-solid fa-circle-xmark"></i> Um caractere especial</div>
                <div class="regra" id="as-r-match"><i class="fa-solid fa-circle-xmark"></i> Senhas coincidem</div>
            </div>
            <div id="as-feedback" class="login-feedback" aria-live="polite"></div>
            <div class="form-actions" style="justify-content:flex-end;">
                <button type="button" class="btn ghost" id="alterar-senha-cancel">Cancelar</button>
                <button type="submit" class="btn primary" id="alterar-senha-save" disabled><i class="fa-solid fa-key"></i> Alterar senha</button>
            </div>
        </form>
    </div>
</div>

<!-- Criar Usuário -->
<div class="modal-overlay" id="criar-usuario-modal">
    <div class="modal dash-modal cu-modal" style="max-width:680px;width:96vw;max-height:92vh;overflow-y:auto;">
        <div class="modal-header">
            <h2><i class="fa-solid fa-user-plus"></i> Novo usuário</h2>
            <button class="modal-close" id="criar-usuario-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="criar-usuario-form" autocomplete="off" class="cu-form">

            <!-- Row 1: nome + username -->
            <div class="cu-row">
                <div class="cu-field">
                    <label class="cu-label" for="cu-nome">Nome completo <span class="cu-req">*</span></label>
                    <div class="cu-input-wrap">
                        <span class="cu-icon"><i class="fa-solid fa-id-card"></i></span>
                        <input type="text" id="cu-nome" class="cu-input" placeholder="Nome completo" required />
                    </div>
                </div>
                <div class="cu-field">
                    <label class="cu-label" for="cu-username">Username <span class="cu-req">*</span></label>
                    <div class="cu-input-wrap">
                        <span class="cu-icon"><i class="fa-solid fa-at"></i></span>
                        <input type="text" id="cu-username" class="cu-input" placeholder="usuario.exemplo" autocomplete="off" required />
                    </div>
                    <small id="cu-username-feedback" class="cu-hint"></small>
                </div>
            </div>

            <!-- Row 2: email + nivel -->
            <div class="cu-row">
                <div class="cu-field">
                    <label class="cu-label" for="cu-email">E-mail <span class="cu-req">*</span></label>
                    <div class="cu-input-wrap">
                        <span class="cu-icon"><i class="fa-solid fa-envelope"></i></span>
                        <input type="email" id="cu-email" class="cu-input" placeholder="email@exemplo.com" required />
                    </div>
                    <small id="cu-email-feedback" class="cu-hint"></small>
                </div>
                <div class="cu-field">
                    <label class="cu-label" for="cu-nivel">Nível de acesso</label>
                    <div class="cu-input-wrap">
                        <span class="cu-icon"><i class="fa-solid fa-shield-halved"></i></span>
                        <select id="cu-nivel" class="cu-input cu-select">
                            <option value="usuario">Usuário</option>
                            <option value="moderador">Moderador</option>
                            <option value="admin">Admin</option>
                            <option value="admin_system">Admin System</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Row 3: senha + confirmar -->
            <div class="cu-row">
                <div class="cu-field">
                    <label class="cu-label" for="cu-senha">Senha <span class="cu-req">*</span></label>
                    <div class="cu-input-wrap">
                        <span class="cu-icon"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" id="cu-senha" class="cu-input" placeholder="Senha segura" autocomplete="new-password" required />
                    </div>
                </div>
                <div class="cu-field">
                    <label class="cu-label" for="cu-confirmar">Confirmar senha <span class="cu-req">*</span></label>
                    <div class="cu-input-wrap">
                        <span class="cu-icon"><i class="fa-solid fa-lock-open"></i></span>
                        <input type="password" id="cu-confirmar" class="cu-input" placeholder="Confirmar senha" autocomplete="new-password" required />
                    </div>
                </div>
            </div>

            <!-- Regras de senha -->
            <div id="cu-regras" class="cu-rules">
                <div class="cu-rule" id="cu-r-len"><i class="fa-solid fa-circle-xmark"></i> Mínimo 8 caracteres</div>
                <div class="cu-rule" id="cu-r-upper"><i class="fa-solid fa-circle-xmark"></i> Uma letra maiúscula</div>
                <div class="cu-rule" id="cu-r-lower"><i class="fa-solid fa-circle-xmark"></i> Uma letra minúscula</div>
                <div class="cu-rule" id="cu-r-num"><i class="fa-solid fa-circle-xmark"></i> Um número</div>
                <div class="cu-rule" id="cu-r-special"><i class="fa-solid fa-circle-xmark"></i> Um caractere especial</div>
                <div class="cu-rule" id="cu-r-match"><i class="fa-solid fa-circle-xmark"></i> Senhas coincidem</div>
            </div>

            <div id="cu-feedback" class="lm-feedback" aria-live="polite"></div>

            <div class="cu-actions">
                <button type="button" class="dash-btn-ghost" id="criar-usuario-cancel">Cancelar</button>
                <button type="submit" class="dash-btn-primary" id="criar-usuario-save" disabled>
                    <i class="fa-solid fa-user-plus"></i> Criar usuário
                </button>
            </div>
        </form>
    </div>
</div>

<script src="/assets/nav-init.js?v=<?= time() ?>"></script>
<script src="/assets/dashboard.js?v=<?= time() ?>"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
// Conecta botões extras ao dashboard.js (open-email-modal-hero, open-email-modal2, sidebar links)
document.addEventListener('DOMContentLoaded', function () {
    // Fecha modal de rota
    const rdClose = document.getElementById('route-detail-close');
    if (rdClose) {
        rdClose.addEventListener('click', () => {
            const ov = document.getElementById('route-detail-modal');
            if (ov) { ov.classList.remove('show'); ov.setAttribute('aria-hidden', 'true'); }
        });
    }
    document.getElementById('route-detail-modal')?.addEventListener('click', function(e) {
        if (e.target === this) { this.classList.remove('show'); this.setAttribute('aria-hidden', 'true'); }
    });

    // Dark mode — gerenciado pelo nav-init.js (evita listener duplicado)

    // Sidebar toggle — gerenciado pelo nav-init.js (evita listener duplicado)

    // Dropdowns topbar
    document.querySelectorAll('.dash-dropdown-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const id   = 'dd-' + this.dataset.dropdown;
            const menu = document.getElementById(id);
            if (!menu) return;
            const isOpen = menu.classList.contains('open');
            document.querySelectorAll('.dash-dropdown-menu.open').forEach(m => m.classList.remove('open'));
            document.querySelectorAll('.dash-dropdown-btn.active').forEach(b => b.classList.remove('active'));
            if (!isOpen) { menu.classList.add('open'); this.classList.add('active'); }
        });
    });
    document.addEventListener('click', () => {
        document.querySelectorAll('.dash-dropdown-menu.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.dash-dropdown-btn.active').forEach(b => b.classList.remove('active'));
    });

    // Smooth scroll links
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        const href = a.getAttribute('href');
        if (!href || href === '#') return;
        a.addEventListener('click', e => {
            const t = document.querySelector(href);
            if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });

    // Botões extras de e-mail apontam para o mesmo modal
    ['open-email-modal-hero', 'open-email-modal2'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.addEventListener('click', () => document.getElementById('open-email-modal')?.click());
    });

    // Sidebar links de perfil/criar usuário/logout
    const sbPerfil = document.getElementById('sb-meu-perfil');
    const sbCriar  = document.getElementById('sb-criar-user');
    const sbLogout = document.getElementById('sb-logout');
    if (sbPerfil) sbPerfil.addEventListener('click', e => { e.preventDefault(); document.getElementById('open-meu-perfil')?.click(); });
    if (sbCriar)  sbCriar.addEventListener('click',  e => { e.preventDefault(); document.getElementById('open-criar-usuario')?.click(); });
    if (sbLogout) sbLogout.addEventListener('click',  e => { e.preventDefault(); document.getElementById('logout-btn')?.click(); });

    // Avatar topbar → perfil + carrega foto
    const avatar = document.getElementById('topbar-avatar');
    if (avatar) {
        avatar.addEventListener('click', () => document.getElementById('open-meu-perfil')?.click());
        // Tenta carregar foto do usuário logado
        fetch('/api/auth/me', { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                const user = data?.user ?? data;
                if (user?.avatar_url) {
                    avatar.innerHTML = `<img src="${user.avatar_url}" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />`;
                } else if (user?.nome || user?.username) {
                    const initials = ((user.nome || user.username || '?')[0]).toUpperCase();
                    avatar.innerHTML = `<span style="font-size:1.1rem;font-weight:800;">${initials}</span>`;
                }
            })
            .catch(() => {});
    }
});
</script>
</body>
</html>
