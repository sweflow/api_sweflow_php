<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace de Módulos</title>
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
                    <i class="fa-solid fa-cubes"></i>
                <?php endif; ?>
                Dashboard
            </div>
            <nav>
                <ul>
                    <li><a href="/dashboard"><i class="fa-solid fa-arrow-left"></i> Voltar</a></li>
                    <li><a href="/modules/marketplace"><i class="fa-solid fa-store"></i> Marketplace</a></li>
                    <li><a href="/"><i class="fa-solid fa-house"></i> Início</a></li>
                </ul>
            </nav>
        </aside>
        <main class="content">
            <section class="hero">
                <h1><i class="fa-solid fa-store"></i> Marketplace de Módulos</h1>
                <p>Instale módulos e estenda a plataforma com um clique.</p>
            </section>

            <section class="card">
                <div style="display:flex;gap:8px;align-items:center;">
                    <input id="q" type="text" placeholder="Pesquisar (ex.: sweflow/module)" style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:6px;" />
                    <button class="btn" id="search"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                </div>
            </section>

            <section class="card-grid" id="pkg-grid">
            </section>
        </main>
    </div>

    <div class="modal-overlay" id="confirm-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-triangle-exclamation"></i> Confirmação</h2>
                <button class="modal-close" onclick="closeModal('confirm-modal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p id="confirm-message">Tem certeza que deseja realizar esta ação?</p>
            <div class="form-actions" style="justify-content: flex-end; margin-top: 20px;">
                <button class="btn ghost" onclick="closeModal('confirm-modal')">Cancelar</button>
                <button class="btn danger" id="confirm-btn">Confirmar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="success-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-circle-check" style="color: #2ecc40;"></i> Sucesso</h2>
                <button class="modal-close" onclick="closeModal('success-modal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p id="success-message">Operação realizada com sucesso.</p>
            <div class="form-actions" style="justify-content: flex-end; margin-top: 20px;">
                <button class="btn primary" onclick="closeModal('success-modal')">OK</button>
            </div>
        </div>
    </div>

    <script>
    function openModal(id) {
        document.getElementById(id).classList.add('show');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    (function(){
        const grid = document.getElementById('pkg-grid');
        const q = document.getElementById('q');
        const search = document.getElementById('search');
        let currentPkg = null;
        let currentAction = null;
        let currentBtn = null;

        document.getElementById('confirm-btn').addEventListener('click', async () => {
            closeModal('confirm-modal');
            if (!currentPkg || !currentAction) return;
            
            const btn = currentBtn;
            if(btn) {
                btn.disabled = true;
                btn.textContent = currentAction === 'install' ? 'Instalando...' : 'Removendo...';
            }

            try {
                const url = currentAction === 'install' 
                    ? '/api/system/modules/install'
                    : '/api/system/modules/uninstall';
                    
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ package: currentPkg })
                });
                const out = await res.json().catch(() => ({}));
                
                if (res.ok) {
                    document.getElementById('success-message').textContent = out.message || 'Operação realizada com sucesso.';
                    openModal('success-modal');
                    // Recarrega a lista
                    const qVal = document.getElementById('q').value;
                    const newPkgs = await fetchPkgs(qVal || 'sweflow/module');
                    renderPkgs(newPkgs);
                } else {
                    alert('Falha: ' + (out.message || res.status));
                    if(btn) {
                        btn.disabled = false;
                        btn.textContent = currentAction === 'install' ? 'Instalar' : 'Remover';
                    }
                }
            } catch (e) {
                alert('Erro na operação.');
                if(btn) btn.disabled = false;
            }
        });

        async function fetchPkgs(query) {
            try {
                const res = await fetch('/api/system/marketplace?q=' + encodeURIComponent(query || 'sweflow/module'));
                const data = await res.json();
                return data.results || [];
            } catch (e) {
                return [];
            }
        }

        function card(pkg) {
            const name = pkg.name || '';
            const desc = pkg.description || '';
            const dls = pkg.downloads || 0;
            const installed = pkg.installed || false;
            
            const btnHtml = installed 
                ? `<button class="btn danger" data-pkg="${name}" data-action="uninstall"><i class="fa-solid fa-trash"></i> Remover</button>`
                : `<button class="btn primary" data-pkg="${name}" data-action="install"><i class="fa-solid fa-plug"></i> Instalar</button>`;

            return `
                <div class="card">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:8px;">
                            <i class="fa-solid fa-puzzle-piece"></i> ${name}
                        </h3>
                        <span class="pill"><i class="fa-solid fa-download"></i> ${dls.toLocaleString()}</span>
                    </div>
                    <p style="margin:8px 0 12px;color:#505050;">${desc}</p>
                    <div class="form-actions" style="justify-content:flex-end;">
                        ${btnHtml}
                    </div>
                </div>
            `;
        }

        function renderPkgs(pkgs) {
            if (!grid) return;
            if (!pkgs || pkgs.length === 0) {
                grid.innerHTML = '<div class="card"><div class="muted">Nenhum módulo encontrado.</div></div>';
                return;
            }
            grid.innerHTML = pkgs.map(p => card(p)).join('');
            
            grid.querySelectorAll('button[data-pkg]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const pkg = btn.getAttribute('data-pkg');
                    const action = btn.getAttribute('data-action');
                    
                    currentPkg = pkg;
                    currentAction = action;
                    currentBtn = btn;

                    if (action === 'uninstall') {
                        document.getElementById('confirm-message').textContent = `Tem certeza que deseja remover o módulo "${pkg}"?`;
                        openModal('confirm-modal');
                    } else {
                        // Para instalação, não precisa confirmar (ou pode confirmar se quiser)
                        // Vamos fazer direto como estava, ou usar modal? 
                        // O usuário pediu modal para "Tem certeza que deseja remover...".
                        // Mas vou usar o mesmo fluxo do botão de confirmação para padronizar.
                        // Se for install, clica direto no botão oculto ou chama a função?
                        // Vou simular o click no confirm ou chamar a lógica.
                        // Melhor: Se for install, roda direto. Se for uninstall, pede confirm.
                        
                        // Chamando a lógica direto para install
                        document.getElementById('confirm-btn').click();
                    }
                });
            });
        }

        async function initial() {
            const pkgs = await fetchPkgs('sweflow/module');
            renderPkgs(pkgs);
        }

        if (search) {
            search.addEventListener('click', async () => {
                const pkgs = await fetchPkgs(q.value || 'sweflow/module');
                renderPkgs(pkgs);
            });
        }

        initial();
    })();
    </script>
</body>
</html>
