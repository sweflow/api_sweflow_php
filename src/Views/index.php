<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sweflow API</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">
                <i class="fa-solid fa-cubes"></i> Sweflow API
            </div>
            <nav>
                <ul>
                    <li><a href="#status"><i class="fa-solid fa-server"></i> Status</a></li>
                    <li><a href="#modulos"><i class="fa-solid fa-layer-group"></i> Módulos</a></li>
                    <li><a href="#rotas"><i class="fa-solid fa-route"></i> Rotas</a></li>
                </ul>
            </nav>
        </aside>
        <main class="content">
            <section id="descricao">
                <h1><i class="fa-solid fa-cubes"></i> Sweflow API</h1>
                <p><?= $descricao ?></p>
            </section>
            <section id="status">
                <h2><i class="fa-solid fa-server"></i> Status do Servidor</h2>
                <ul class="status-list" id="status-list">
                    <li>Carregando...</li>
                </ul>
            </section>
            <section id="modulos">
                <h2><i class="fa-solid fa-layer-group"></i> Módulos Detectados</h2>
                <ul class="modules-list" id="modules-list">
                    <li>Carregando...</li>
                </ul>
            </section>
            <section id="rotas">
                <h2><i class="fa-solid fa-route"></i> Rotas dos Módulos</h2>
                <div id="routes-list">Carregando...</div>
            </section>
            <script>
            function renderStatus(data) {
                const status = data.status;
                document.getElementById('status-list').innerHTML = `
                    <li><strong>Host:</strong> ${status.host}</li>
                    <li><strong>Porta:</strong> ${status.port}</li>
                    <li><strong>Ambiente:</strong> ${status.env}</li>
                    <li><strong>Debug:</strong> ${status.debug}</li>
                `;
            }
            function renderModules(data) {
                const modules = data.modules;
                document.getElementById('modules-list').innerHTML = modules.map(m => `<li><strong>${m.name}</strong></li>`).join('');
            }
            function renderRoutes(data) {
                const modules = data.modules;
                let html = '';
                modules.forEach(mod => {
                    html += `<h3>${mod.name}</h3>`;
                    html += `<table class="routes-table"><thead><tr><th>Método</th><th>URI</th><th>Tipo</th></tr></thead><tbody>`;
                    mod.routes.forEach(route => {
                        html += `<tr><td>${route.method}</td><td>${route.uri}</td><td>${route.tipo === 'pública' ? '<span class=public><i class=fa-solid fa-unlock></i> Pública</span>' : '<span class=private><i class=fa-solid fa-lock></i> Privada</span>'}</td></tr>`;
                    });
                    html += `</tbody></table>`;
                });
                document.getElementById('routes-list').innerHTML = html;
            }
            function updateData() {
                fetch('/api/status').then(r => r.json()).then(data => {
                    renderStatus(data);
                    renderModules(data);
                    renderRoutes(data);
                });
            }
            updateData();
            setInterval(updateData, 3000);
            </script>
        </main>
    </div>
</body>
</html>