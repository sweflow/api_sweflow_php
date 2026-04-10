<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo ?? 'Vupi.us API', ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars($descricao ?? 'API modular com detecção automática de módulos e rotas.', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= htmlspecialchars(($_ENV['APP_URL'] ?? '') . '/', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <!-- Open Graph -->
    <meta property="og:type"        content="website">
    <meta property="og:title"       content="<?= htmlspecialchars($titulo ?? 'Vupi.us API', ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($descricao ?? 'API modular com detecção automática de módulos e rotas.', ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url"         content="<?= htmlspecialchars(($_ENV['APP_URL'] ?? '') . '/', ENT_QUOTES, 'UTF-8') ?>">
    <?php if (!empty($logo_url)): ?>
    <meta property="og:image"       content="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <!-- Twitter Card -->
    <meta name="twitter:card"        content="summary">
    <meta name="twitter:title"       content="<?= htmlspecialchars($titulo ?? 'Vupi.us API', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($descricao ?? 'API modular com detecção automática de módulos e rotas.', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="home-body">

    <!-- NAV -->
    <header class="home-nav" id="home-nav">
        <div class="home-nav-inner">

            <!-- Brand -->
            <a href="/" class="home-nav-brand" aria-label="Vupi.us API — início">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="home-nav-logo-img" />
                <?php else: ?>
                    <img src="/favicon.ico" alt="Vupi.us API" class="home-nav-logo-img" />
                <?php endif; ?>
                <span class="home-nav-name">Vupi.us <span class="home-nav-name-accent">API</span></span>
            </a>

            <!-- Links desktop -->
            <nav class="home-nav-links" aria-label="Navegação principal">
                <a href="#features" class="home-nav-link">
                    <i class="fa-solid fa-layer-group"></i> Recursos
                </a>
                <a href="/doc" class="home-nav-link">
                    <i class="fa-solid fa-book-open"></i> Docs
                </a>
                <a href="/dashboard/ide" class="home-nav-link home-nav-link-ide">
                    <i class="fa-solid fa-code"></i> IDE
                </a>
            </nav>

            <!-- Ações desktop -->
            <div class="home-nav-actions">
                <button class="home-theme-toggle" id="home-theme-toggle" aria-label="Alternar tema" title="Alternar dark/light">
                    <i class="fa-solid fa-sun" id="home-theme-icon"></i>
                </button>
                <button class="btn-nav-login" id="open-login">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    <span id="nav-login-text">Entrar</span>
                </button>
            </div>

            <!-- Hamburger mobile -->
            <button class="home-nav-hamburger" id="nav-hamburger" aria-label="Abrir menu" aria-expanded="false" aria-controls="nav-mobile">
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
            </button>
        </div>

        <!-- Menu mobile -->
        <div class="home-nav-mobile" id="nav-mobile" aria-hidden="true">
            <div class="home-nav-mobile-inner">
                <a href="#features" class="home-nav-mobile-link">
                    <span class="mobile-link-icon"><i class="fa-solid fa-layer-group"></i></span>
                    <span>Recursos</span>
                    <i class="fa-solid fa-chevron-right mobile-link-arrow"></i>
                </a>
                <a href="/doc" class="home-nav-mobile-link">
                    <span class="mobile-link-icon"><i class="fa-solid fa-book-open"></i></span>
                    <span>Docs</span>
                    <i class="fa-solid fa-chevron-right mobile-link-arrow"></i>
                </a>
                <a href="/dashboard/ide" class="home-nav-mobile-link" style="color:#818cf8;">
                    <span class="mobile-link-icon"><i class="fa-solid fa-code"></i></span>
                    <span>IDE</span>
                    <i class="fa-solid fa-chevron-right mobile-link-arrow"></i>
                </a>
                <div class="home-nav-mobile-divider"></div>
                <div class="home-nav-mobile-bottom">
                    <button class="home-theme-toggle home-theme-toggle-mobile" id="home-theme-toggle-mobile" aria-label="Alternar tema">
                        <i class="fa-solid fa-sun" id="home-theme-icon-mobile"></i>
                        <span id="home-theme-label-mobile">Modo claro</span>
                    </button>
                    <button class="btn-nav-login btn-nav-login-mobile" id="open-login-mobile">
                        <i class="fa-solid fa-right-to-bracket"></i>
                        <span id="nav-login-text-mobile">Entrar no Dashboard</span>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- HERO -->
    <section class="home-hero">
        <div class="home-hero-inner">
            <div class="home-hero-badge">
                <i class="fa-solid fa-bolt"></i> Modular &amp; Extensível
            </div>
            <h1 class="home-hero-title">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="home-hero-logo" />
                <?php endif; ?>
                Vupi.us API
            </h1>
            <p class="home-hero-sub"><?= htmlspecialchars($descricao ?? '', ENT_QUOTES, 'UTF-8') ?></p>
            <div class="home-hero-actions">
                <button class="btn-hero-primary" id="cta-open-login">
                    <i class="fa-solid fa-door-open"></i>
                    <span id="cta-login-text">Acessar Dashboard</span>
                </button>
                <a href="/dashboard/ide" class="btn-hero-ide">
                    <i class="fa-solid fa-code"></i>
                    <span>Abrir IDE</span>
                </a>
                <a href="#features" class="btn-hero-ghost">
                    <i class="fa-solid fa-circle-info"></i> Saiba mais
                </a>
            </div>
        </div>
        <div class="home-hero-visual" aria-hidden="true">
            <div class="hero-code-block">
                <div class="hero-code-bar">
                    <span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span>
                    <span class="hero-code-label">GET /api/status</span>
                </div>
                <pre class="hero-code-pre"><code><span class="c-brace">{</span>
  <span class="c-key">"status"</span><span class="c-colon">:</span> <span class="c-str">"ok"</span>
<span class="c-brace">}</span></code></pre>
            </div>
        </div>
    </section>

    <!-- FEATURES -->
    <section class="home-features" id="features">
        <div class="home-section-inner">
            <h2 class="home-section-title">Recursos principais</h2>
            <p class="home-section-sub">Tudo que você precisa para construir APIs robustas e seguras.</p>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-layer-group"></i></div>
                    <h3>Módulos dinâmicos</h3>
                    <p>Ative, desative e crie módulos sem reiniciar o servidor. Detecção automática de rotas.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <h3>Segurança integrada</h3>
                    <p>JWT, rate limiting, audit log, circuit breaker e headers de segurança prontos para produção.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-plug"></i></div>
                    <h3>Sistema de plugins</h3>
                    <p>Instale e gerencie plugins via CLI ou marketplace. Migrations e seeders automáticos.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-database"></i></div>
                    <h3>Multi-banco</h3>
                    <p>Suporte nativo a MySQL e PostgreSQL com factory de conexão e tratamento de exceções.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-envelope"></i></div>
                    <h3>E-mail transacional</h3>
                    <p>Templates HTML, verificação de conta, recuperação de senha e reenvio automático.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><i class="fa-solid fa-terminal"></i></div>
                    <h3>CLI poderosa</h3>
                    <p>Comandos para setup, migrations, seeders, geração de JWT e gerenciamento de módulos.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="home-footer">
        <div class="home-footer-inner">
            <span class="home-footer-brand">
                <img src="/favicon.ico" alt="Vupi.us API" style="width:20px;height:20px;border-radius:5px;object-fit:contain;vertical-align:middle;" />
                Vupi.us API
            </span>
            <span class="home-footer-copy">&copy; <?= date('Y') ?> Vupi.us &mdash; Desenvolvido por Adimael</span>
        </div>
    </footer>

    <!-- MODAL LOGIN -->
    <div class="modal-overlay" id="login-modal" aria-hidden="true">
        <div class="lm-box" role="dialog" aria-modal="true" aria-labelledby="login-modal-title">

            <div class="lm-panel">
                <button type="button" class="lm-close" id="login-close" aria-label="Fechar modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>

                <div class="lm-panel-top">
                    <div class="lm-brand-icon-sm">
                        <?php if (!empty($logo_url)): ?>
                            <img src="<?= htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;border-radius:12px;" />
                        <?php else: ?>
                            <img src="/favicon.ico" alt="Vupi.us API" style="width:100%;height:100%;object-fit:contain;" />
                        <?php endif; ?>
                    </div>
                    <h2 id="login-modal-title" class="lm-title">Acesso administrativo</h2>
                    <p class="lm-subtitle">Entre com suas credenciais para continuar</p>
                </div>

                <form id="login-form" class="lm-form">
                    <div class="lm-field">
                        <label for="login-user" class="lm-label">
                            Usuário ou e-mail
                        </label>
                        <div class="lm-input-wrap">
                            <span class="lm-input-icon"><i class="fa-solid fa-user"></i></span>
                            <input type="text" id="login-user" name="login"
                                   class="lm-input" placeholder="admin ou email@exemplo.com"
                                   autocomplete="username" required />
                        </div>
                    </div>

                    <div class="lm-field">
                        <label for="login-password" class="lm-label">Senha</label>
                        <div class="lm-input-wrap">
                            <span class="lm-input-icon"><i class="fa-solid fa-key"></i></span>
                            <input type="password" id="login-password" name="senha"
                                   class="lm-input" placeholder="••••••••"
                                   autocomplete="current-password" required />
                            <button type="button" class="lm-toggle-pw" id="toggle-password" aria-label="Mostrar senha" tabindex="-1">
                                <i class="fa-solid fa-eye" id="toggle-pw-icon"></i>
                            </button>
                        </div>
                    </div>

                    <div id="login-feedback" class="lm-feedback" aria-live="polite"></div>

                    <button type="submit" id="login-submit" class="lm-submit">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                        <span id="login-submit-text">Entrar</span>
                    </button>

                    <button type="button" class="lm-cancel" id="login-cancel">
                        Cancelar
                    </button>
                </form>
            </div>

        </div>
    </div>

    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
    // Aplica tema antes do render para evitar flash
    (function () {
        const DARK_KEY = 'home-dark-mode';
        const body = document.body;
        const saved = localStorage.getItem(DARK_KEY);
        if (saved === '1') body.classList.add('home-dark');

        function applyTheme(dark) {
            body.classList.toggle('home-dark', dark);
            const icons  = ['home-theme-icon', 'home-theme-icon-mobile'];
            const labels = document.getElementById('home-theme-label-mobile');
            icons.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
            });
            if (labels) labels.textContent = dark ? 'Modo claro' : 'Modo escuro';
        }

        document.addEventListener('DOMContentLoaded', function () {
            const isDark = body.classList.contains('home-dark');
            applyTheme(isDark);
            ['home-theme-toggle', 'home-theme-toggle-mobile'].forEach(id => {
                const btn = document.getElementById(id);
                if (btn) {
                    btn.addEventListener('click', () => {
                        const nowDark = body.classList.toggle('home-dark');
                        localStorage.setItem(DARK_KEY, nowDark ? '1' : '0');
                        applyTheme(nowDark);
                    });
                }
            });
        });
    })();
    </script>
    <script src="/assets/js/home.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/home.js') ?>"></script>
</body>
</html>
