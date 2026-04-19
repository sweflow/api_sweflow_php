<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($titulo ?? 'Dashboard', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <style>
        html, body { margin: 0; padding: 0; background: #f8fafc; }
        /* Pré-aplica variáveis dark antes do CSS externo carregar — elimina flash */
        html.will-dark .dash-body {
            --bg-page:        #0b0d18;
            --bg-topbar:      rgba(11,13,24,0.92);
            --bg-sidebar:     #0d0f1a;
            --bg-card:        #161929;
            --bg-dropdown:    #12141f;
            --bg-input:       rgba(255,255,255,0.04);
            --bg-hover:       rgba(255,255,255,0.05);
            --bg-hero:        linear-gradient(135deg,rgba(79,70,229,0.12) 0%,rgba(124,58,237,0.08) 100%);
            --text-primary:   #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted:     #475569;
            --text-nav:       #cbd5e1;
            --text-nav-hover: #f1f5f9;
            --border-color:   rgba(255,255,255,0.07);
            --border-topbar:  rgba(255,255,255,0.06);
            --border-sidebar: rgba(255,255,255,0.05);
            --border-card:    rgba(255,255,255,0.06);
            --border-input:   rgba(255,255,255,0.09);
            background: #0b0d18 !important;
            color: #f1f5f9 !important;
        }
        html.will-dark body,
        html.will-dark .dash-topbar,
        html.will-dark .dash-sidebar,
        html.will-dark .dash-main,
        html.will-dark .dash-layout { background: #0b0d18 !important; }
        html.will-dark .dash-card   { background: #161929 !important; }
        html.dash-no-transition *, html.dash-no-transition *::before, html.dash-no-transition *::after {
            transition: none !important;
        }
    </style>
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        (function() {
            var dark = localStorage.getItem('dash-dark-mode') === '1';
            document.documentElement.classList.add('dash-no-transition');
            if (dark) document.documentElement.classList.add('will-dark');
            document.addEventListener('DOMContentLoaded', function() {
                if (dark) {
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
                // Pré-aplica avatar do cache antes do nav-init.js carregar
                try {
                    var avatarUrl = localStorage.getItem('dash-avatar-url');
                    if (avatarUrl) {
                        var el = document.getElementById('topbar-avatar');
                        if (el) {
                            el.textContent = '';
                            var img = document.createElement('img');
                            img.src = avatarUrl;
                            img.alt = 'Avatar';
                            img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
                            img.onerror = function() { el.innerHTML = '<i class="fa-solid fa-circle-user"></i>'; };
                            el.appendChild(img);
                        }
                    }
                } catch(_) {}
            });
        })();
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js" integrity="sha512-jB0TkTBeQC9ZSkBqDhdmfTv1qdfbWpGE72yJ/01Srq6hEzZIz2xkz1e57p9ai7IeHMwEG7HpzG6NdptChif5Pg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="/assets/js/trusted-types-policy.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/trusted-types-policy.js') ?>"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/css/style.css') ?>">
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
                <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="dash-brand-img" fetchpriority="high" />
            <?php else: ?>
                <img src="/favicon.ico" alt="Vupi.us" class="dash-brand-img" width="32" height="32" />
            <?php endif; ?>
            <span class="dash-brand-name">Vupi.us <span class="dash-brand-accent">API</span></span>
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
                <a href="/dashboard/ide"       class="dash-dd-item" style="color:#818cf8;font-weight:700;"><i class="fa-solid fa-code"></i> Vupi.us IDE</a>
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
            <button type="button" class="dash-avatar" id="topbar-avatar" title="Meu perfil" aria-label="Meu perfil"></button>
            <span class="dash-avatar-status"></span>
        </div>
    </div>
</header>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function(){
    var el = document.getElementById('topbar-avatar');
    if (!el) return;
    try {
        var url = localStorage.getItem('dash-avatar-url');
        if (url) {
            var img = document.createElement('img');
            img.src = url;
            img.alt = 'Avatar';
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
            img.onerror = function() {
                el.innerHTML = '';
                var ic = document.createElement('i');
                ic.className = 'fa-solid fa-circle-user';
                el.appendChild(ic);
                localStorage.removeItem('dash-avatar-url');
            };
            el.appendChild(img);
        } else {
            var ic = document.createElement('i');
            ic.className = 'fa-solid fa-circle-user';
            el.appendChild(ic);
        }
    } catch(_) {
        var ic2 = document.createElement('i');
        ic2.className = 'fa-solid fa-circle-user';
        el.appendChild(ic2);
    }
})();
</script>

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
                    <a href="#migrations"          class="dash-sidenav-link"><i class="fa-solid fa-database"></i> Migrations</a>
                    <a href="#audit-logs"           class="dash-sidenav-link"><i class="fa-solid fa-shield-halved"></i> Logs & Monitoramento</a>
                    <a href="/modules/marketplace" class="dash-sidenav-link"><i class="fa-solid fa-store"></i> Marketplace</a>
                    <a href="/dashboard/ide"        class="dash-sidenav-link" style="color:#818cf8;font-weight:700;"><i class="fa-solid fa-code"></i> Vupi.us IDE</a>
                    <a href="#email-actions"        class="dash-sidenav-link"><i class="fa-solid fa-envelope"></i> E-mail</a>
                    <a href="/dashboard/configuracoes" class="dash-sidenav-link"><i class="fa-solid fa-sliders"></i> Configurações</a>
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
                    <img src="/favicon.ico" alt="Vupi.us API" class="dash-hero-logo" />
                    <span class="dash-hero-brand-name">Vupi.us <span style="color:#818cf8">API</span></span>
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
                    <a id="auth-verify-marketplace-link" href="/modules/marketplace" style="display:none;margin-top:6px;font-size:0.82rem;color:#7c5cff;text-decoration:none;">
                        <i class="fa-solid fa-store"></i> Instalar módulo de E-mail no Marketplace
                    </a>
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

        <!-- Migrations -->
        <section class="dash-card dash-section" id="migrations">
            <div class="dash-section-header">
                <div>
                    <h2 class="dash-section-title"><i class="fa-solid fa-database"></i> Migrations</h2>
                    <p class="dash-section-sub">Status das migrations por banco de dados.</p>
                </div>
                <div class="dash-section-actions">
                    <button class="dash-btn-primary" id="btn-run-migrations" style="margin-right:6px;">
                        <i class="fa-solid fa-play"></i> Rodar Migrations
                    </button>
                    <button class="dash-btn-primary" id="btn-run-seeders" style="margin-right:6px;background:linear-gradient(135deg,#22c55e,#16a34a);">
                        <i class="fa-solid fa-seedling"></i> Rodar Seeders
                    </button>
                    <button class="dash-btn-ghost" id="migrations-refresh-btn">
                        <i class="fa-solid fa-rotate-right"></i> Atualizar
                    </button>
                </div>
            </div>
            <div id="migrations-list"><span class="dash-loading">Carregando...</span></div>
        </section>

        <!-- Logs & Monitoramento -->
        <section class="dash-card dash-section" id="audit-logs">
            <div class="dash-section-header">
                <div>
                    <h2 class="dash-section-title"><i class="fa-solid fa-shield-halved"></i> Logs &amp; Monitoramento</h2>
                    <p class="dash-section-sub">Auditoria em tempo real — autenticações, segurança, alterações e erros.</p>
                </div>
                <div class="dash-section-actions">
                    <button class="dash-btn-ghost" id="audit-refresh-btn" title="Atualizar agora" style="font-size:1rem;padding:10px 18px;">
                        <i class="fa-solid fa-rotate-right"></i> Atualizar
                    </button>
                    <button class="dash-btn-ghost" id="audit-clear-btn" title="Limpar logs antigos" style="color:#f87171;font-size:1rem;padding:10px 18px;">
                        <i class="fa-solid fa-trash"></i> Limpar antigos
                    </button>
                </div>
            </div>

            <!-- Stats cards -->
            <div id="audit-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px;">
                <div class="audit-stat-card" data-cat="total" style="cursor:pointer;transition:all 0.2s ease;">
                    <div class="audit-stat-icon" style="background:rgba(99,102,241,0.15);color:#818cf8;font-size:1.6rem;width:52px;height:52px;display:flex;align-items:center;justify-content:center;border-radius:12px;margin-bottom:12px;"><i class="fa-solid fa-list"></i></div>
                    <div class="audit-stat-val" id="stat-total">—</div>
                    <div class="audit-stat-label" style="font-size:1rem;margin-top:6px;font-weight:600;">Total 24h</div>
                </div>
                <div class="audit-stat-card" data-cat="auth" style="cursor:pointer;transition:all 0.2s ease;">
                    <div class="audit-stat-icon" style="background:rgba(74,222,128,0.15);color:#4ade80;font-size:1.6rem;width:52px;height:52px;display:flex;align-items:center;justify-content:center;border-radius:12px;margin-bottom:12px;"><i class="fa-solid fa-key"></i></div>
                    <div class="audit-stat-val" id="stat-auth">—</div>
                    <div class="audit-stat-label" style="font-size:1rem;margin-top:6px;font-weight:600;">Autenticações</div>
                </div>
                <div class="audit-stat-card" data-cat="usuarios" style="cursor:pointer;transition:all 0.2s ease;">
                    <div class="audit-stat-icon" style="background:rgba(96,165,250,0.15);color:#60a5fa;font-size:1.6rem;width:52px;height:52px;display:flex;align-items:center;justify-content:center;border-radius:12px;margin-bottom:12px;"><i class="fa-solid fa-users"></i></div>
                    <div class="audit-stat-val" id="stat-usuarios">—</div>
                    <div class="audit-stat-label" style="font-size:1rem;margin-top:6px;font-weight:600;">Usuários</div>
                </div>
                <div class="audit-stat-card" data-cat="seguranca" style="cursor:pointer;transition:all 0.2s ease;">
                    <div class="audit-stat-icon" style="background:rgba(248,113,113,0.15);color:#f87171;font-size:1.6rem;width:52px;height:52px;display:flex;align-items:center;justify-content:center;border-radius:12px;margin-bottom:12px;"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="audit-stat-val" id="stat-seguranca">—</div>
                    <div class="audit-stat-label" style="font-size:1rem;margin-top:6px;font-weight:600;">Segurança</div>
                </div>
                <div class="audit-stat-card" data-cat="admin" style="cursor:pointer;transition:all 0.2s ease;">
                    <div class="audit-stat-icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;font-size:1.6rem;width:52px;height:52px;display:flex;align-items:center;justify-content:center;border-radius:12px;margin-bottom:12px;"><i class="fa-solid fa-gear"></i></div>
                    <div class="audit-stat-val" id="stat-admin">—</div>
                    <div class="audit-stat-label" style="font-size:1rem;margin-top:6px;font-weight:600;">Ações Admin</div>
                </div>
            </div>

            <!-- Filtros -->
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
                <div style="position:relative;flex:1;min-width:200px;">
                    <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;font-size:1rem;pointer-events:none;"></i>
                    <input type="search" id="audit-search" placeholder="Buscar evento, IP, endpoint..."
                           style="width:100%;padding:12px 14px 12px 40px;border:1.5px solid var(--border-input,rgba(255,255,255,0.1));border-radius:10px;font-size:1rem;box-sizing:border-box;background:var(--bg-input,rgba(255,255,255,0.05));color:var(--text-primary,#f1f5f9);font-family:inherit;outline:none;">
                </div>
                <select id="audit-categoria" style="padding:12px 16px;border:1.5px solid var(--border-input,rgba(255,255,255,0.1));border-radius:10px;font-size:1rem;background:var(--bg-input,rgba(255,255,255,0.05));color:var(--text-primary,#f1f5f9);font-family:inherit;outline:none;cursor:pointer;min-width:180px;">
                    <option value="">Todas as categorias</option>
                    <option value="auth">Autenticação</option>
                    <option value="usuarios">Usuários</option>
                    <option value="seguranca">Segurança</option>
                    <option value="admin">Admin</option>
                </select>
                <select id="audit-periodo" style="padding:12px 16px;border:1.5px solid var(--border-input,rgba(255,255,255,0.1));border-radius:10px;font-size:1rem;background:var(--bg-input,rgba(255,255,255,0.05));color:var(--text-primary,#f1f5f9);font-family:inherit;outline:none;cursor:pointer;min-width:160px;">
                    <option value="1">Última hora</option>
                    <option value="24" selected>Últimas 24h</option>
                    <option value="168">Últimos 7 dias</option>
                    <option value="720">Últimos 30 dias</option>
                    <option value="">Todos</option>
                </select>
                <label style="display:flex;align-items:center;gap:8px;font-size:1rem;color:#94a3b8;cursor:pointer;white-space:nowrap;">
                    <input type="checkbox" id="audit-realtime" checked style="accent-color:#4f46e5;width:18px;height:18px;cursor:pointer;">
                    Tempo real
                </label>
            </div>

            <!-- Tabela de logs -->
            <div style="overflow-x:auto;border-radius:12px;border:1px solid var(--border-card,rgba(255,255,255,0.07));background:var(--bg-card,rgba(255,255,255,0.02));">
                <table style="width:100%;border-collapse:collapse;font-size:1rem;">
                    <thead>
                        <tr style="background:var(--bg-card,rgba(255,255,255,0.05));border-bottom:2px solid var(--border-card,rgba(255,255,255,0.1));">
                            <th style="padding:18px 20px;text-align:left;color:#cbd5e1;font-weight:700;white-space:nowrap;font-size:1.05rem;letter-spacing:0.3px;">
                                <i class="fa-solid fa-clock" style="margin-right:8px;color:#818cf8;font-size:1.1rem;"></i>Data/Hora
                            </th>
                            <th style="padding:18px 20px;text-align:left;color:#cbd5e1;font-weight:700;font-size:1.05rem;letter-spacing:0.3px;">
                                <i class="fa-solid fa-tag" style="margin-right:8px;color:#818cf8;font-size:1.1rem;"></i>Evento
                            </th>
                            <th style="padding:18px 20px;text-align:left;color:#cbd5e1;font-weight:700;font-size:1.05rem;letter-spacing:0.3px;">
                                <i class="fa-solid fa-network-wired" style="margin-right:8px;color:#818cf8;font-size:1.1rem;"></i>IP
                            </th>
                            <th style="padding:18px 20px;text-align:left;color:#cbd5e1;font-weight:700;font-size:1.05rem;letter-spacing:0.3px;">
                                <i class="fa-solid fa-route" style="margin-right:8px;color:#818cf8;font-size:1.1rem;"></i>Endpoint
                            </th>
                            <th style="padding:18px 20px;text-align:left;color:#cbd5e1;font-weight:700;font-size:1.05rem;letter-spacing:0.3px;">
                                <i class="fa-solid fa-info-circle" style="margin-right:8px;color:#818cf8;font-size:1.1rem;"></i>Detalhes
                            </th>
                        </tr>
                    </thead>
                    <tbody id="audit-log-tbody">
                        <tr><td colspan="5" style="padding:50px;text-align:center;color:#64748b;font-size:1.1rem;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;margin-right:12px;"></i> Carregando...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginação -->
            <div id="audit-pagination" style="display:flex;align-items:center;justify-content:space-between;margin-top:16px;flex-wrap:wrap;gap:12px;">
                <span id="audit-total-label" style="font-size:0.95rem;color:#94a3b8;font-weight:600;"></span>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button id="audit-prev" class="btn ghost" style="font-size:0.95rem;padding:10px 20px;" disabled>
                        <i class="fa-solid fa-chevron-left"></i> Anterior
                    </button>
                    <span id="audit-page-label" style="font-size:0.95rem;color:#94a3b8;font-weight:600;"></span>
                    <button id="audit-next" class="btn ghost" style="font-size:0.95rem;padding:10px 20px;" disabled>
                        Próxima <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
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

<!-- Ativar módulo -->
<div class="modal-overlay" id="enable-modal">
    <div class="modal dash-modal">
        <div class="modal-header"><h2><i class="fa-solid fa-play"></i> Ativar módulo</h2>
            <button class="modal-close" id="enable-close"><i class="fa-solid fa-xmark"></i></button></div>
        <p id="enable-modal-text">Tem certeza que deseja ativar este módulo?</p>
        <div class="pill" style="margin:12px 0;"><i class="fa-solid fa-layer-group"></i> <span id="enable-modal-name">--</span></div>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="enable-cancel">Cancelar</button>
            <button class="btn primary" id="enable-confirm" style="background:#16a34a;"><i class="fa-solid fa-play"></i> Ativar</button>
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

<!-- Ativar verificação de e-mail -->
<div class="modal-overlay" id="auth-verify-enable-modal">
    <div class="modal dash-modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-envelope-circle-check" style="color:#4f46e5;"></i> Ativar verificação de e-mail</h2>
            <button class="modal-close" id="auth-verify-enable-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p style="line-height:1.7;margin:8px 0 6px;">Tem certeza que deseja ativar <strong>Login só após e-mail verificado</strong>?</p>
        <p style="color:#64748b;font-size:0.93rem;line-height:1.6;margin-bottom:16px;">
            Após ativar, todos os usuários precisarão ter o e-mail confirmado para fazer login.<br>
            Usuários com e-mail não verificado serão bloqueados até confirmarem o cadastro.
        </p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="auth-verify-enable-cancel">Cancelar</button>
            <button class="btn primary" id="auth-verify-enable-confirm" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);">
                <i class="fa-solid fa-envelope-circle-check"></i> Ativar
            </button>
        </div>
    </div>
</div>

<!-- Desativar verificação de e-mail -->
<div class="modal-overlay" id="auth-verify-disable-modal">
    <div class="modal dash-modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-envelope-open" style="color:#f59e0b;"></i> Desativar verificação de e-mail</h2>
            <button class="modal-close" id="auth-verify-disable-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p style="line-height:1.7;margin:8px 0 6px;">Tem certeza que deseja desativar <strong>Login só após e-mail verificado</strong>?</p>
        <p style="color:#64748b;font-size:0.93rem;line-height:1.6;margin-bottom:16px;">
            Ao desativar, os usuários poderão fazer login mesmo sem confirmar o e-mail.<br>
            A verificação de e-mail <strong>não</strong> será mais obrigatória para novos logins.
        </p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="auth-verify-disable-cancel">Cancelar</button>
            <button class="btn primary" id="auth-verify-disable-confirm" style="background:#d97706;">
                <i class="fa-solid fa-envelope-open"></i> Desativar
            </button>
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
        <div class="modal-header email-modal-header">
            <div class="email-modal-title-wrap">
                <div class="email-modal-icon">
                    <i class="fa-solid fa-envelope"></i>
                </div>
                <div>
                    <h2 class="email-modal-title">Enviar e-mail personalizado</h2>
                    <p class="email-modal-sub">Compose e dispare mensagens diretamente pela API</p>
                </div>
            </div>
            <div class="email-modal-actions">
                <button class="email-action-btn" id="email-preview-btn" type="button" title="Pré-visualizar">
                    <i class="fa-solid fa-eye"></i>
                    <span>Preview</span>
                </button>
                <button class="email-action-btn" id="email-fullscreen-btn" type="button" title="Tela cheia">
                    <i class="fa-solid fa-up-right-and-down-left-from-center"></i>
                </button>
                <button class="modal-close" id="email-close" aria-label="Fechar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <form id="email-form" class="email-form" autocomplete="off">

            <!-- Campos principais -->
            <div class="email-fields">
                <div class="email-field-row">
                    <div class="email-field-label">
                        <i class="fa-solid fa-at"></i>
                        <span>Para</span>
                    </div>
                    <input type="text" id="email-to" class="email-field-input"
                           placeholder="email@exemplo.com, outro@exemplo.com" />
                </div>
                <div class="email-field-divider"></div>
                <div class="email-field-row">
                    <div class="email-field-label">
                        <i class="fa-solid fa-heading"></i>
                        <span>Assunto</span>
                    </div>
                    <input type="text" id="email-subject" class="email-field-input"
                           placeholder="Assunto do e-mail" required />
                </div>
                <div class="email-field-divider"></div>
                <div class="email-field-row">
                    <div class="email-field-label">
                        <i class="fa-solid fa-image"></i>
                        <span>Logo</span>
                    </div>
                    <input type="url" id="email-logo" class="email-field-input"
                           placeholder="https://.../logo.png (opcional)" />
                </div>
            </div>

            <!-- Toolbar -->
            <div class="email-toolbar" id="email-toolbar">
                <div class="email-toolbar-group">
                    <button type="button" data-cmd="bold" title="Negrito"><i class="fa-solid fa-bold"></i></button>
                    <button type="button" data-cmd="italic" title="Itálico"><i class="fa-solid fa-italic"></i></button>
                    <button type="button" data-cmd="underline" title="Sublinhado"><i class="fa-solid fa-underline"></i></button>
                    <button type="button" data-cmd="strikeThrough" title="Tachado"><i class="fa-solid fa-strikethrough"></i></button>
                </div>
                <div class="email-toolbar-sep"></div>
                <div class="email-toolbar-group">
                    <button type="button" data-cmd="insertOrderedList" title="Lista numerada"><i class="fa-solid fa-list-ol"></i></button>
                    <button type="button" data-cmd="insertUnorderedList" title="Lista"><i class="fa-solid fa-list-ul"></i></button>
                    <button type="button" data-cmd="formatBlock" data-value="blockquote" title="Citação"><i class="fa-solid fa-quote-left"></i></button>
                    <button type="button" data-cmd="formatBlock" data-value="pre" title="Bloco de código"><i class="fa-solid fa-code"></i></button>
                    <button type="button" data-cmd="insertCode" title="Código inline"><i class="fa-solid fa-terminal"></i></button>
                </div>
                <div class="email-toolbar-sep"></div>
                <div class="email-toolbar-group">
                    <button type="button" data-cmd="align-left" title="Alinhar à esquerda"><i class="fa-solid fa-align-left"></i></button>
                    <button type="button" data-cmd="align-center" title="Centralizar"><i class="fa-solid fa-align-center"></i></button>
                    <button type="button" data-cmd="align-right" title="Alinhar à direita"><i class="fa-solid fa-align-right"></i></button>
                </div>
                <div class="email-toolbar-sep"></div>
                <div class="email-toolbar-group">
                    <button type="button" data-cmd="createLink" title="Inserir link"><i class="fa-solid fa-link"></i></button>
                    <button type="button" data-cmd="insertImage" title="Inserir imagem"><i class="fa-solid fa-image"></i></button>
                </div>
                <div class="email-toolbar-sep"></div>
                <div class="email-toolbar-group email-toolbar-extras">
                    <select id="email-font-size" aria-label="Tamanho da fonte" title="Tamanho">
                        <option value="">Tam.</option>
                        <option value="12">12px</option>
                        <option value="14">14px</option>
                        <option value="18">18px</option>
                        <option value="22">22px</option>
                        <option value="28">28px</option>
                        <option value="36">36px</option>
                    </select>
                    <label class="color-picker" title="Cor do texto">
                        <i class="fa-solid fa-font"></i>
                        <input type="color" id="email-font-color" />
                    </label>
                    <label class="color-picker" title="Cor de fundo">
                        <i class="fa-solid fa-fill-drip"></i>
                        <input type="color" id="email-bg-color" />
                    </label>
                </div>
            </div>

            <!-- Editor -->
            <div class="email-editor-wrap">
                <div class="email-editor" id="email-editor" contenteditable="true"
                     aria-label="Editor de e-mail" aria-multiline="true"></div>
                <div class="email-preview" id="email-preview" hidden></div>
            </div>

            <!-- Footer -->
            <div class="email-form-footer">
                <div id="email-feedback" class="email-feedback" aria-live="polite"></div>
                <div class="email-form-btns">
                    <button type="button" class="btn ghost" id="email-draft-btn" title="Salvar rascunho localmente">
                        <i class="fa-solid fa-floppy-disk"></i> Rascunho
                    </button>
                    <button type="button" class="btn ghost" id="email-cancel">
                        <i class="fa-solid fa-xmark"></i> Cancelar
                    </button>
                    <button type="submit" class="btn primary email-send-btn" id="email-send">
                        <i class="fa-solid fa-paper-plane"></i> Enviar e-mail
                    </button>
                </div>
            </div>

        </form>
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
        <!-- Barra de seleção múltipla -->
        <div id="email-bulk-bar" style="display:none;align-items:center;gap:10px;padding:10px 4px 6px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.9rem;color:var(--text-muted,#64748b);user-select:none;">
                <input type="checkbox" id="email-select-all" style="width:16px;height:16px;cursor:pointer;accent-color:#4f46e5;">
                <span id="email-select-all-label">Marcar todos</span>
            </label>
            <span id="email-selected-count" style="font-size:0.85rem;color:#818cf8;font-weight:600;"></span>
            <div style="margin-left:auto;display:flex;gap:8px;">
                <button id="email-bulk-cancel" class="btn ghost" style="font-size:0.85rem;padding:7px 14px;">Cancelar</button>
                <button id="email-bulk-delete" class="btn" style="background:#e74c3c;color:#fff;font-size:0.85rem;padding:7px 14px;" disabled>
                    <i class="fa-solid fa-trash"></i> Excluir selecionados
                </button>
            </div>
        </div>
        <!-- Botão para entrar no modo de seleção -->
        <div id="email-select-mode-btn-wrap" style="display:flex;justify-content:flex-end;padding:4px 0 2px;">
            <button id="email-enter-select-mode" class="btn ghost" style="font-size:0.82rem;padding:6px 12px;">
                <i class="fa-solid fa-check-square"></i> Selecionar
            </button>
        </div>
        <div id="email-history-list" style="max-height:58vh;overflow-y:auto;padding-right:2px;"></div>
    </div>
</div>

<!-- Detalhe e-mail -->
<div class="modal-overlay" id="email-detail-modal">
    <div class="modal email-modal" style="max-width:980px;width:96vw;">
        <div class="modal-header email-modal-header">
            <div class="email-modal-title-wrap">
                <div class="email-modal-icon">
                    <i class="fa-solid fa-envelope-open-text"></i>
                </div>
                <div>
                    <h2 class="email-modal-title">Detalhes do e-mail</h2>
                    <p class="email-modal-sub" id="email-detail-subtitle">Visualizando registro</p>
                </div>
            </div>
            <div class="email-modal-actions">
                <button class="modal-close" id="email-detail-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <div id="email-detail-body" style="overflow-y:auto;max-height:60vh;padding:0 26px 8px;"></div>
        <div class="email-form-footer">
            <div id="detail-resend-feedback" class="email-feedback" aria-live="polite"></div>
            <div class="email-form-btns">
                <button class="btn ghost" id="email-detail-discard" style="display:none;color:#f59e0b;border-color:rgba(245,158,11,0.3);"><i class="fa-solid fa-trash-can"></i> Descartar rascunho</button>
                <button class="btn ghost" id="email-detail-resend"><i class="fa-solid fa-rotate-right"></i> Reenviar</button>
                <button class="btn ghost" id="email-detail-draft-edit" style="display:none;"><i class="fa-solid fa-pen-to-square"></i> Editar rascunho</button>
                <button class="btn ghost" id="email-detail-edit"><i class="fa-solid fa-pen"></i> Editar e reenviar</button>
                <button class="btn" style="background:#e74c3c;color:#fff;" id="email-detail-delete"><i class="fa-solid fa-trash"></i> Excluir</button>
            </div>
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

<!-- Confirmar exclusão em lote -->
<div class="modal-overlay" id="email-bulk-delete-modal">
    <div class="modal dash-modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-trash"></i> Excluir selecionados</h2>
            <button class="modal-close" id="email-bulk-delete-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p id="email-bulk-delete-text">Tem certeza que deseja excluir os registros selecionados? Esta ação não pode ser desfeita.</p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="email-bulk-delete-cancel">Cancelar</button>
            <button class="btn" style="background:#e74c3c;color:#fff;" id="email-bulk-delete-confirm">
                <i class="fa-solid fa-trash"></i> Excluir
            </button>
        </div>
    </div>
</div>

<!-- Confirmar limpeza de logs antigos -->
<div class="modal-overlay" id="audit-clear-modal">
    <div class="modal dash-modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-trash" style="color:#f87171;"></i> Limpar logs antigos</h2>
            <button class="modal-close" id="audit-clear-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p style="line-height:1.7;margin:8px 0 6px;font-size:1rem;">Tem certeza que deseja remover os logs com mais de <strong>90 dias</strong>?</p>
        <p style="color:#94a3b8;font-size:0.93rem;line-height:1.6;margin-bottom:20px;">
            Esta ação não pode ser desfeita. Logs mais recentes serão preservados.
        </p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="audit-clear-cancel" style="font-size:1rem;padding:10px 20px;">Cancelar</button>
            <button class="btn" id="audit-clear-confirm" style="background:#e74c3c;color:#fff;font-size:1rem;padding:10px 20px;">
                <i class="fa-solid fa-trash"></i> Remover logs antigos
            </button>
        </div>
    </div>
</div>
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

<!-- Confirmar Rodar Migrations -->
<div class="modal-overlay" id="migrate-confirm-modal">
    <div class="modal dash-modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-database" style="color:#4f46e5;"></i> Rodar Migrations</h2>
            <button class="modal-close" id="migrate-confirm-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p style="line-height:1.7;margin:8px 0 6px;">Tem certeza que deseja executar <strong>todas as migrations pendentes</strong>?</p>
        <p style="color:#94a3b8;font-size:0.9rem;line-height:1.6;margin-bottom:20px;">Esta ação criará ou alterará tabelas no banco de dados e não pode ser desfeita facilmente.</p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="migrate-confirm-cancel">Cancelar</button>
            <button class="btn primary" id="migrate-confirm-ok"><i class="fa-solid fa-database"></i> Executar</button>
        </div>
    </div>
</div>

<!-- Confirmar Rodar Seeders -->
<div class="modal-overlay" id="seed-confirm-modal">
    <div class="modal dash-modal">
        <div class="modal-header">
            <h2><i class="fa-solid fa-seedling" style="color:#10b981;"></i> Rodar Seeders</h2>
            <button class="modal-close" id="seed-confirm-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p style="line-height:1.7;margin:8px 0 6px;">Tem certeza que deseja executar <strong>todos os seeders pendentes</strong>?</p>
        <p style="color:#94a3b8;font-size:0.9rem;line-height:1.6;margin-bottom:20px;">Esta ação inserirá dados iniciais no banco de dados.</p>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn ghost" id="seed-confirm-cancel">Cancelar</button>
            <button class="btn primary" id="seed-confirm-ok" style="background:#10b981;"><i class="fa-solid fa-seedling"></i> Executar</button>
        </div>
    </div>
</div>

<!-- Resultado de Migration/Seeder -->
<div class="modal-overlay" id="run-result-modal">
    <div class="modal dash-modal">
        <div class="modal-header">
            <h2 id="run-result-title"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Concluído</h2>
            <button class="modal-close" id="run-result-close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <pre id="run-result-output" style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:8px;font-size:0.88rem;line-height:1.6;max-height:320px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;margin:8px 0 20px;"></pre>
        <div class="form-actions" style="justify-content:flex-end;">
            <button class="btn primary" id="run-result-ok">OK</button>
        </div>
    </div>
</div>

<script src="/assets/js/dashboard-init.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/dashboard-init.js') ?>"></script>
<script src="/assets/js/nav-init.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/nav-init.js') ?>"></script>
<script src="/assets/js/dashboard.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/dashboard.js') ?>"></script>
<script src="/assets/js/audit-logs.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/audit-logs.js') ?: time() ?>"></script>
</body>
</html>