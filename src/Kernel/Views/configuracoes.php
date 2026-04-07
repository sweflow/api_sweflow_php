<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($titulo ?? 'Configurações', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <style>
        html, body { margin: 0; padding: 0; background: #f1f5f9; }
        html.will-dark body { background: #0b0d18 !important; color: #f1f5f9 !important; }
        html.dash-no-transition *, html.dash-no-transition *::before, html.dash-no-transition *::after { transition: none !important; }
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
            });
        })();
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js" integrity="sha512-jB0TkTBeQC9ZSkBqDhdmfTv1qdfbWpGE72yJ/01Srq6hEzZIz2xkz1e57p9ai7IeHMwEG7HpzG6NdptChif5Pg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="/assets/js/trusted-types-policy.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/trusted-types-policy.js') ?>"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/configuracoes.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/css/configuracoes.css') ?>">
</head>
<body class="cfg-body">

<!-- ── TOPBAR ── -->
<header class="cfg-topbar">
    <div class="cfg-topbar-left">
        <a href="/dashboard" class="cfg-back-btn">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
        <div class="cfg-topbar-title">
            <i class="fa-solid fa-sliders"></i>
            Configurações do ambiente
        </div>
    </div>
    <div class="cfg-topbar-right">
        <button class="cfg-theme-btn" id="cfg-theme-btn" aria-label="Alternar tema" title="Alternar dark/light">
            <i class="fa-solid fa-moon" id="cfg-theme-icon"></i>
        </button>
    </div>
</header>

<!-- ── LAYOUT ── -->
<div class="cfg-layout">

    <!-- Sidebar de navegação interna -->
    <nav class="cfg-sidenav" aria-label="Seções de configuração">
        <span class="cfg-sidenav-label">Seções</span>
        <a href="#sec-app"    class="cfg-sidenav-link" data-sec="app">    <i class="fa-solid fa-rocket"></i>       Aplicação</a>
        <a href="#sec-db"     class="cfg-sidenav-link" data-sec="db">     <i class="fa-solid fa-database"></i>     Banco de dados (core)</a>
        <a href="#sec-db2"    class="cfg-sidenav-link" data-sec="db2">    <i class="fa-solid fa-database"></i>     Banco de dados (modules)</a>
        <a href="#sec-jwt"    class="cfg-sidenav-link" data-sec="jwt">    <i class="fa-solid fa-shield-halved"></i> Segurança / JWT</a>
        <a href="#sec-mail"   class="cfg-sidenav-link" data-sec="mail">   <i class="fa-solid fa-envelope"></i>     E-mail (SMTP)</a>
        <a href="#sec-cors"   class="cfg-sidenav-link" data-sec="cors">   <i class="fa-solid fa-globe"></i>        URLs permitidas (CORS)</a>
        <a href="#sec-redis"  class="cfg-sidenav-link" data-sec="redis">  <i class="fa-solid fa-bolt"></i>         Redis</a>
        <a href="#sec-admin"  class="cfg-sidenav-link" data-sec="admin">  <i class="fa-solid fa-user-shield"></i>  Admin padrão</a>
        <a href="#sec-docker" class="cfg-sidenav-link" data-sec="docker"> <i class="fa-solid fa-box"></i>          Docker / Infra</a>
    </nav>

    <!-- Main -->
    <main class="cfg-main" id="cfg-main">
        <div id="cfg-loading" style="text-align:center;padding:60px;color:var(--cfg-text-muted);font-size:1.1rem;">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:2rem;margin-bottom:16px;display:block;color:var(--cfg-accent);"></i>
            Carregando configurações...
        </div>
    </main>

</div><!-- /.cfg-layout -->

<script src="/assets/js/configuracoes.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/configuracoes.js') ?>"></script>
</body>
</html>
