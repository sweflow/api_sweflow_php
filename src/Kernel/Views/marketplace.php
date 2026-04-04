<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Marketplace de Módulos — Sweflow</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <style>
        html, body { margin: 0; padding: 0; background: #f8fafc; }
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
                if (dark) document.body.classList.add('dark');
                document.documentElement.classList.remove('will-dark');
                requestAnimationFrame(function() {
                    requestAnimationFrame(function() {
                        document.documentElement.classList.remove('dash-no-transition');
                    });
                });
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
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/marketplace.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/css/marketplace.css') ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js" integrity="sha512-jB0TkTBeQC9ZSkBqDhdmfTv1qdfbWpGE72yJ/01Srq6hEzZIz2xkz1e57p9ai7IeHMwEG7HpzG6NdptChif5Pg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="/assets/js/trusted-types-policy.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/trusted-types-policy.js') ?>"></script>
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
                    <button type="button" id="logout-btn" class="dash-dd-item dash-dd-danger"><i class="fa-solid fa-right-from-bracket"></i> Sair</button>
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
                <button type="button" class="dash-avatar" id="topbar-avatar" title="Meu perfil" aria-label="Meu perfil"></button>
                <span class="dash-avatar-status"></span>
            </div>
        </div>
    </header>
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
    (function(){var el=document.getElementById('topbar-avatar');if(!el)return;try{var url=localStorage.getItem('dash-avatar-url');if(url){var img=document.createElement('img');img.src=url;img.alt='Avatar';img.style.cssText='width:100%;height:100%;object-fit:cover;border-radius:50%;';img.onerror=function(){el.innerHTML='';var ic=document.createElement('i');ic.className='fa-solid fa-circle-user';el.appendChild(ic);localStorage.removeItem('dash-avatar-url');};el.appendChild(img);}else{var ic=document.createElement('i');ic.className='fa-solid fa-circle-user';el.appendChild(ic);}}catch(_){var ic2=document.createElement('i');ic2.className='fa-solid fa-circle-user';el.appendChild(ic2);}})();
    </script>

    <div class="dash-layout">
        <!-- SIDEBAR -->
        <aside class="dash-sidebar" id="dash-sidebar">
            <div class="dash-sidebar-inner">
                <nav class="dash-sidenav">
                    <div class="dash-sidenav-section">
                        <span class="dash-sidenav-label">Configuração</span>
                        <a href="/modules/marketplace" class="dash-sidenav-link dash-sidenav-active"><i class="fa-solid fa-store"></i> Marketplace</a>
                    </div>
                    <div class="dash-sidenav-section">
                        <a href="/dashboard" class="dash-sidenav-link"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                        <a href="/" class="dash-sidenav-link"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a>
                        <button type="button" id="sb-logout" class="dash-sidenav-link dash-sidenav-danger"><i class="fa-solid fa-right-from-bracket"></i> Sair</button>
                    </div>
                </nav>
            </div>
        </aside>
        <div class="dash-sidebar-backdrop" id="sidebar-backdrop"></div>

        <main class="dash-main" id="main-content">

            <!-- Hero -->
            <div class="mp-hero" role="banner">
                <div class="mp-hero-text">
                    <h1><i class="fa-solid fa-store" aria-hidden="true"></i> Marketplace de Módulos</h1>
                    <p>Descubra, instale e gerencie módulos para estender a plataforma Sweflow.</p>
                </div>
                <div class="mp-hero-badge" aria-label="Total de módulos encontrados">
                    <span class="badge-num" id="total-count">—</span>
                    <span class="badge-label">módulos</span>
                </div>
            </div>

            <!-- Search -->
            <div class="mp-search-wrap" role="search">
                <label for="q"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar</label>
                <input
                    id="q"
                    type="search"
                    class="mp-search-input"
                    placeholder="Nome do módulo ou vendor (ex.: sweflow)"
                    aria-label="Pesquisar módulos no Packagist"
                    autocomplete="off"
                />
                <button class="mp-search-btn" id="search" aria-label="Executar busca">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar
                </button>
            </div>

            <!-- Grid -->
            <div class="mp-grid" id="pkg-grid" role="list" aria-live="polite" aria-label="Lista de módulos"></div>

        </main>
    </div><!-- /.dash-layout -->

    <!-- Modal: Confirmar instalação -->
    <div class="modal-overlay" id="install-modal" role="dialog" aria-modal="true" aria-labelledby="install-title">
        <div class="modal">
            <div class="modal-header">
                <h2 id="install-title"><i class="fa-solid fa-download" style="color:#818cf8;" aria-hidden="true"></i> Confirmar instalação</h2>
                <button class="modal-close" data-close="install-modal" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <p id="install-message" style="font-size:1.05rem;line-height:1.6;margin:8px 0 14px;"></p>
            <div class="mp-install-warning">
                <i class="fa-solid fa-circle-info"></i>
                A instalação irá baixar e registrar o módulo na plataforma. O processo pode levar alguns segundos.
            </div>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn ghost" data-close="install-modal" style="font-size:1rem;padding:12px 20px;">Cancelar</button>
                <button class="btn" id="install-confirm-btn" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;font-size:1rem;padding:12px 22px;border:none;">
                    <i class="fa-solid fa-download" aria-hidden="true"></i> Instalar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Confirmar remoção -->
    <div class="modal-overlay" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <div class="modal">
            <div class="modal-header">
                <h2 id="confirm-title"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;" aria-hidden="true"></i> Confirmar remoção</h2>
                <button class="modal-close" data-close="confirm-modal" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <p id="confirm-message" style="font-size:1.05rem;line-height:1.6;margin:8px 0 20px;"></p>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn ghost" data-close="confirm-modal" style="font-size:1rem;padding:12px 20px;">Cancelar</button>
                <button class="btn" id="confirm-btn" style="background:#dc2626;color:#fff;font-size:1rem;padding:12px 20px;border:none;">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i> Remover
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Sucesso -->
    <div class="modal-overlay" id="success-modal" role="dialog" aria-modal="true" aria-labelledby="success-title">
        <div class="modal">
            <div class="modal-header">
                <h2 id="success-title"><i class="fa-solid fa-circle-check" style="color:#10b981;" aria-hidden="true"></i> Operação concluída</h2>
                <button class="modal-close" data-close="success-modal" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <p id="success-message" style="font-size:1.05rem;line-height:1.6;margin:8px 0 20px;"></p>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn primary" data-close="success-modal" style="font-size:1rem;padding:12px 24px;">OK</button>
            </div>
        </div>
    </div>

    <!-- Modal: Detalhes do módulo -->
    <div class="modal-overlay" id="detail-modal" role="dialog" aria-modal="true" aria-labelledby="detail-modal-title">
        <div class="modal pkg-detail-modal">
            <div class="modal-header">
                <h2 id="detail-modal-title"><i class="fa-solid fa-puzzle-piece" style="color:#818cf8;" aria-hidden="true"></i> Detalhes do módulo</h2>
                <button class="modal-close" data-close="detail-modal" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div id="detail-modal-body"></div>
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

    <script src="/assets/js/marketplace.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/marketplace.js') ?>"></script>
<script src="/assets/js/dashboard.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/dashboard.js') ?>"></script>
    <script src="/assets/js/nav-init.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/nav-init.js') ?>"></script>
</body>
</html>
