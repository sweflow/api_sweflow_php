<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($titulo ?? 'IDE', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/ide.css?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/css/ide.css') ?: time() ?>">
    <script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
        (function(){
            var dark = localStorage.getItem('dash-dark-mode') === '1';
            if (dark) document.documentElement.classList.add('will-dark');
        })();
    </script>
</head>
<body class="ide-body">

<!-- TOPBAR -->
<header class="ide-topbar" id="ide-topbar">
    <div class="ide-topbar-left">
        <a href="/dashboard/ide" class="ide-topbar-back" title="Voltar aos projetos" aria-label="Voltar aos projetos">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="ide-topbar-brand">
            <i class="fa-solid fa-code" aria-hidden="true"></i>
            <span>Vupi.us <strong>IDE</strong></span>
        </div>
        <div class="ide-topbar-sep" aria-hidden="true"></div>
        <div class="ide-project-info" id="ide-project-info">
            <span class="ide-project-label" id="topbar-project-name">Carregando...</span>
            <span class="ide-project-module" id="topbar-module-name"></span>
        </div>
    </div>
    <div class="ide-topbar-actions">
        <button class="ide-topbar-btn" id="btn-save-all" title="Salvar tudo (Ctrl+S)" aria-label="Salvar todos os arquivos">
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            <span class="ide-topbar-btn-label">Salvar</span>
        </button>
        <button class="ide-topbar-btn" id="btn-run-file" title="Executar arquivo (F5)" aria-label="Executar arquivo atual">
            <i class="fa-solid fa-play" aria-hidden="true"></i>
            <span class="ide-topbar-btn-label">Executar</span>
        </button>
        <button class="ide-topbar-btn" id="btn-debug-file" title="Debug arquivo (F6)" aria-label="Debugar arquivo atual">
            <i class="fa-solid fa-bug" aria-hidden="true"></i>
            <span class="ide-topbar-btn-label">Debug</span>
        </button>
        <button class="ide-topbar-btn" id="btn-toggle-terminal" title="Terminal (Ctrl+`)" aria-label="Abrir terminal">
            <i class="fa-solid fa-terminal" aria-hidden="true"></i>
            <span class="ide-topbar-btn-label">Terminal</span>
        </button>
        <button class="ide-topbar-btn" id="btn-split-editor" title="Dividir editor (Ctrl+\\)" aria-label="Dividir editor">
            <i class="fa-solid fa-columns" aria-hidden="true"></i>
            <span class="ide-topbar-btn-label">Dividir</span>
        </button>
        <button class="ide-topbar-btn" id="btn-api-tester" title="Testar Rotas (Ctrl+T)" aria-label="Testar rotas da API">
            <i class="fa-solid fa-bolt" aria-hidden="true"></i>
            <span class="ide-topbar-btn-label">Rotas</span>
        </button>
        <button class="ide-topbar-btn ide-topbar-btn-deploy" id="btn-deploy-top" title="Publicar no Packagist" aria-label="Publicar no Packagist">
            <i class="fa-brands fa-php" aria-hidden="true"></i>
            <span class="ide-topbar-btn-label">Publicar</span>
        </button>
        <div class="ide-topbar-divider" aria-hidden="true"></div>
        <button class="ide-topbar-icon" id="btn-settings" title="Configurações da IDE" aria-label="Configurações da IDE">
            <i class="fa-solid fa-sliders" aria-hidden="true"></i>
        </button>
        <button class="ide-topbar-icon" id="btn-theme" title="Alternar tema" aria-label="Alternar tema claro/escuro">
            <i class="fa-solid fa-moon" id="theme-icon" aria-hidden="true"></i>
        </button>
        <a href="/dashboard/ide" class="ide-topbar-icon" title="Projetos" aria-label="Ver todos os projetos">
            <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
        </a>
        <button class="ide-topbar-icon ide-topbar-logout" id="btn-logout" title="Sair" aria-label="Sair da IDE">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
        </button>
    </div>
</header>

<!-- IDE LAYOUT -->
<div class="ide-layout" id="ide-layout">

    <!-- FILE TREE PANEL -->
    <aside class="ide-panel ide-panel-files" id="panel-files" aria-label="Explorador de arquivos">
        <div class="ide-panel-header">
            <button class="ide-panel-toggle" id="toggle-files"
                    aria-expanded="true" aria-controls="panel-files-body"
                    title="Recolher painel de arquivos">
                <i class="fa-solid fa-chevron-left" id="toggle-files-icon" aria-hidden="true"></i>
            </button>
            <span class="ide-panel-title" id="panel-files-title">
                <i class="fa-solid fa-folder-tree" aria-hidden="true"></i>
                <span id="filetree-module-name">Arquivos</span>
            </span>
            <div class="ide-panel-actions-group">
                <button class="ide-panel-action" id="btn-new-file" title="Novo arquivo" aria-label="Criar novo arquivo" disabled>
                    <i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i>
                </button>
                <button class="ide-panel-action" id="btn-new-folder" title="Nova pasta" aria-label="Criar nova pasta" disabled>
                    <i class="fa-solid fa-folder-plus" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="ide-panel-body" id="panel-files-body">
            <div class="ide-filetree" id="ide-filetree">
                <div class="ide-empty-state">
                    <i class="fa-solid fa-spinner fa-spin fa-lg" aria-hidden="true"></i>
                    <p>Carregando...</p>
                </div>
            </div>
        </div>
    </aside>

    <!-- RESIZE HANDLE: Files ↔ Editor -->
    <div class="ide-resize-h" id="resize-files" aria-hidden="true" title="Arrastar para redimensionar"></div>

    <!-- EDITOR AREA -->
    <main class="ide-editor-area" id="ide-editor-area" aria-label="Editor de código">
        <div class="ide-tabs" id="ide-tabs" role="tablist" aria-label="Arquivos abertos"></div>
        <div class="ide-editor-container" id="ide-editor-container">
            <div class="ide-welcome" id="ide-welcome" aria-live="polite">
                <div class="ide-welcome-inner">
                    <i class="fa-solid fa-code" aria-hidden="true"></i>
                    <h2>Selecione um arquivo</h2>
                    <p>Clique em um arquivo no painel esquerdo para começar a editar</p>
                    <button class="ide-welcome-btn" id="btn-welcome-file">
                        <i class="fa-solid fa-file-plus" aria-hidden="true"></i> Novo Arquivo
                    </button>
                </div>
            </div>
            <div id="monaco-editor" style="width:100%;height:100%;display:none;" aria-label="Editor Monaco"></div>
            <!-- Split editor para comparação -->
            <div id="ide-split-container" style="display:none;width:100%;height:100%;">
                <div id="split-left" style="width:50%;height:100%;float:left;"></div>
                <div class="ide-split-handle" id="split-handle" aria-hidden="true"></div>
                <div id="split-right" style="width:50%;height:100%;overflow:hidden;"></div>
            </div>
        </div>

        <!-- RESIZE HANDLE: Editor ↔ Terminal -->
        <div class="ide-resize-v" id="resize-terminal" aria-hidden="true" title="Arrastar para redimensionar"></div>

        <!-- TERMINAL PANEL -->
        <div class="ide-terminal-panel" id="ide-terminal-panel" style="display:none;" aria-label="Terminal PHP">
            <div class="ide-terminal-header">
                <div class="ide-terminal-tabs" id="terminal-tabs-bar">
                    <!-- Tabs dinâmicas geradas pelo JS -->
                </div>
                <div class="ide-terminal-actions">
                    <button class="ide-terminal-action" id="btn-new-terminal" title="Nova aba de terminal" aria-label="Nova aba de terminal">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    </button>
                    <button class="ide-terminal-action" id="btn-clear-terminal" title="Limpar" aria-label="Limpar terminal">
                        <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                    </button>
                    <button class="ide-terminal-action" id="btn-close-terminal" title="Fechar painel" aria-label="Fechar painel">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="ide-terminal-bodies" id="terminal-bodies">
                <!-- Corpos das abas gerados pelo JS -->
            </div>
        </div>

        <div class="ide-statusbar" id="ide-statusbar" role="status" aria-live="polite">
            <span class="ide-status-item" id="status-file">
                <i class="fa-solid fa-file-code" aria-hidden="true"></i>
                <span id="status-file-name">Nenhum arquivo</span>
            </span>
            <span class="ide-status-item" id="status-lang"></span>
            <span class="ide-status-item" id="status-cursor"></span>
            <button class="ide-status-diag" id="status-diag" style="display:none;" aria-label="Ver diagnósticos" title="Ver problemas no código">
                <i class="fa-solid fa-circle-xmark" id="status-diag-icon" aria-hidden="true"></i>
                <span id="status-diag-count"></span>
            </button>
            <span class="ide-status-saved" id="status-saved" style="display:none;" aria-live="polite">
                <i class="fa-solid fa-check" aria-hidden="true"></i> Salvo
            </span>
        </div>

        <!-- DIAGNOSTICS PANEL -->
        <div class="ide-diag-panel" id="ide-diag-panel" style="display:none;" aria-label="Problemas no código">
            <div class="ide-diag-header">
                <span class="ide-diag-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Problemas</span>
                <button class="ide-diag-close" id="ide-diag-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="ide-diag-list" id="ide-diag-list"></div>
        </div>
    </main>

    <!-- RESIZE HANDLE: Editor ↔ Deploy -->
    <div class="ide-resize-h" id="resize-deploy" aria-hidden="true" title="Arrastar para redimensionar"></div>

    <!-- DEPLOY PANEL -->
    <aside class="ide-panel ide-panel-deploy" id="panel-deploy" aria-label="Painel de publicação">
        <div class="ide-panel-header">
            <button class="ide-panel-toggle" id="toggle-deploy"
                    aria-expanded="true" aria-controls="panel-deploy-body"
                    title="Recolher painel de deploy">
                <i class="fa-solid fa-chevron-right" id="toggle-deploy-icon" aria-hidden="true"></i>
            </button>
            <span class="ide-panel-title">
                <i class="fa-solid fa-rocket" aria-hidden="true"></i>
                <span>Publicar</span>
            </span>
        </div>
        <div class="ide-panel-body" id="panel-deploy-body">
            <div class="ide-deploy-content" id="ide-deploy-content">
                <div class="ide-empty-state">
                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                </div>
            </div>
        </div>
    </aside>

</div>

<!-- MODAL: Novo Arquivo -->
<div class="ide-modal-overlay" id="modal-new-file" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-nf-title">
    <div class="ide-modal">
        <div class="ide-modal-header">
            <h2 id="modal-nf-title"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Novo Arquivo</h2>
            <button class="ide-modal-close" id="modal-nf-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body">
            <label for="input-file-path">Caminho do arquivo <small>(relativo ao módulo)</small></label>
            <input type="text" id="input-file-path" placeholder="Ex: Controllers/MeuController.php" autocomplete="off" aria-required="true">
            <span class="ide-modal-hint" id="new-file-hint"></span>
        </div>
        <div class="ide-modal-footer">
            <button class="ide-btn-secondary" id="modal-nf-cancel">Cancelar</button>
            <button class="ide-btn-primary" id="modal-nf-confirm">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Criar
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Nova Pasta -->
<div class="ide-modal-overlay" id="modal-new-folder" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-nfld-title">
    <div class="ide-modal">
        <div class="ide-modal-header">
            <h2 id="modal-nfld-title"><i class="fa-solid fa-folder-plus" aria-hidden="true"></i> Nova Pasta</h2>
            <button class="ide-modal-close" id="modal-nfld-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body">
            <label for="input-folder-path">Nome da pasta</label>
            <input type="text" id="input-folder-path" placeholder="Ex: Helpers" autocomplete="off" aria-required="true">
            <span class="ide-modal-hint" id="new-folder-hint"></span>
        </div>
        <div class="ide-modal-footer">
            <button class="ide-btn-secondary" id="modal-nfld-cancel">Cancelar</button>
            <button class="ide-btn-primary" id="modal-nfld-confirm">
                <i class="fa-solid fa-folder-plus" aria-hidden="true"></i> Criar Pasta
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Input genérico (rename, copy) -->
<div class="ide-modal-overlay" id="modal-input" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-input-title">
    <div class="ide-modal">
        <div class="ide-modal-header">
            <h2 id="modal-input-title"></h2>
            <button class="ide-modal-close" id="modal-input-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body">
            <label for="modal-input-value" id="modal-input-label"></label>
            <input type="text" id="modal-input-value" autocomplete="off" aria-required="true">
        </div>
        <div class="ide-modal-footer">
            <button class="ide-btn-secondary" id="modal-input-cancel">Cancelar</button>
            <button class="ide-btn-primary" id="modal-input-ok"></button>
        </div>
    </div>
</div>

<!-- MODAL: Publicar no Packagist -->
<div class="ide-modal-overlay" id="modal-deploy" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-dep-title">
    <div class="ide-modal ide-modal-wide">
        <div class="ide-modal-header">
            <h2 id="modal-dep-title"><i class="fa-brands fa-php" aria-hidden="true"></i> Publicar no Packagist</h2>
            <button class="ide-modal-close" id="modal-dep-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body">
            <p style="font-size:.92rem;color:var(--ide-muted,#94a3b8);margin:0 0 8px;">
                Gera o <code>composer.json</code> e instruções para publicar seu módulo no Packagist / Marketplace.
            </p>
            <div class="ide-packagist-fields">
                <label for="input-vendor">Vendor <input type="text" id="input-vendor" placeholder="meu-vendor" autocomplete="off"></label>
                <label for="input-package">Package <input type="text" id="input-package" placeholder="nome-do-modulo" autocomplete="off"></label>
                <label for="input-version">Versão <input type="text" id="input-version" value="1.0.0" autocomplete="off"></label>
                <label for="input-description">Descrição <input type="text" id="input-description" placeholder="Descrição do módulo" autocomplete="off"></label>
            </div>
        </div>
        <div class="ide-modal-footer">
            <button class="ide-btn-secondary" id="modal-dep-cancel">Cancelar</button>
            <button class="ide-btn-primary" id="modal-dep-confirm">
                <i class="fa-brands fa-php" aria-hidden="true"></i> Publicar
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Resultado Deploy -->
<div class="ide-modal-overlay" id="modal-deploy-result" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="deploy-result-title">
    <div class="ide-modal ide-modal-wide">
        <div class="ide-modal-header">
            <h2 id="deploy-result-title"></h2>
            <button class="ide-modal-close" id="modal-dr-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body" id="deploy-result-body"></div>
        <div class="ide-modal-footer">
            <button class="ide-btn-primary" id="modal-dr-ok">OK</button>
        </div>
    </div>
</div>

<!-- MODAL: Confirmar exclusão de arquivo -->
<div class="ide-modal-overlay" id="modal-delete-file" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="ide-modal">
        <div class="ide-modal-header">
            <h2><i class="fa-solid fa-trash" style="color:#f38ba8;" aria-hidden="true"></i> Remover Arquivo</h2>
            <button class="ide-modal-close" id="modal-df-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body">
            <p>Remover <strong id="delete-file-name"></strong>?</p>
            <p style="font-size:.95rem;opacity:.7;">Esta ação não pode ser desfeita.</p>
        </div>
        <div class="ide-modal-footer">
            <button class="ide-btn-secondary" id="modal-df-cancel">Cancelar</button>
            <button class="ide-btn-danger" id="modal-df-confirm">
                <i class="fa-solid fa-trash" aria-hidden="true"></i> Remover
            </button>
        </div>
    </div>
</div>

<!-- API Route Tester (completo) -->
<div class="ide-modal-overlay ide-api-overlay" id="modal-api-tester" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="ide-api-tester">
        <div class="ide-api-header">
            <div class="ide-api-title"><i class="fa-solid fa-bolt" aria-hidden="true"></i><span>API Route Tester</span></div>
            <div class="ide-api-header-actions">
                <button class="ide-api-save-btn" id="api-save-req" title="Salvar requisição">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Salvar
                </button>
                <button class="ide-api-close" id="api-tester-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <div class="ide-api-body">
            <!-- SAVED REQUESTS SIDEBAR -->
            <div class="ide-api-saved" id="api-saved-panel">
                <div class="ide-api-saved-title">
                    <i class="fa-solid fa-bookmark" aria-hidden="true"></i> Salvos
                </div>
                <div class="ide-api-saved-list" id="api-saved-list"></div>
            </div>
            <!-- REQUEST -->
            <div class="ide-api-request">
                <div class="ide-api-url-bar">
                    <select class="ide-api-method" id="api-method">
                        <option value="GET">GET</option><option value="POST">POST</option>
                        <option value="PUT">PUT</option><option value="PATCH">PATCH</option>
                        <option value="DELETE">DELETE</option><option value="HEAD">HEAD</option>
                        <option value="OPTIONS">OPTIONS</option>
                    </select>
                    <input type="text" class="ide-api-url" id="api-url" placeholder="/api/..." autocomplete="off" spellcheck="false">
                    <button class="ide-api-send" id="api-send"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar</button>
                </div>
                <!-- Request tabs -->
                <div class="ide-api-tabs" id="api-req-tabs">
                    <button class="ide-api-tab" data-req-tab="query">Query</button>
                    <button class="ide-api-tab active" data-req-tab="headers">Headers</button>
                    <button class="ide-api-tab" data-req-tab="auth">Auth</button>
                    <button class="ide-api-tab" data-req-tab="body">Body</button>
                    <button class="ide-api-tab" data-req-tab="routes">Rotas</button>
                </div>
                <!-- Query -->
                <div class="ide-api-tab-body" id="api-tab-query" style="display:none;">
                    <div class="ide-api-kv-title">Parâmetros de Query</div>
                    <div class="ide-api-kv-list" id="api-query-list"></div>
                    <button class="ide-api-add-row" id="api-add-query"><i class="fa-solid fa-plus"></i> Adicionar Parâmetro</button>
                </div>
                <!-- Headers -->
                <div class="ide-api-tab-body" id="api-tab-headers">
                    <div class="ide-api-kv-title">Headers HTTP</div>
                    <div class="ide-api-kv-list" id="api-headers-list"></div>
                    <button class="ide-api-add-row" id="api-add-header"><i class="fa-solid fa-plus"></i> Adicionar Header</button>
                </div>
                <!-- Auth -->
                <div class="ide-api-tab-body" id="api-tab-auth" style="display:none;">
                    <div class="ide-api-kv-title">Autenticação</div>
                    <div class="ide-api-auth-type">
                        <label><input type="radio" name="api-auth" value="none" checked> Nenhuma</label>
                        <label><input type="radio" name="api-auth" value="bearer"> Bearer Token</label>
                        <label><input type="radio" name="api-auth" value="basic"> Basic Auth</label>
                    </div>
                    <div id="api-auth-bearer" style="display:none;" class="ide-api-auth-fields">
                        <label>Token <input type="text" id="api-auth-token" placeholder="eyJ..." class="ide-api-auth-input" autocomplete="off"></label>
                    </div>
                    <div id="api-auth-basic" style="display:none;" class="ide-api-auth-fields">
                        <label>Usuário <input type="text" id="api-auth-user" class="ide-api-auth-input" autocomplete="off"></label>
                        <label>Senha <input type="password" id="api-auth-pass" class="ide-api-auth-input" autocomplete="off"></label>
                    </div>
                </div>
                <!-- Body -->
                <div class="ide-api-tab-body" id="api-tab-body" style="display:none;">
                    <div class="ide-api-body-type">
                        <label><input type="radio" name="api-body-type" value="json" checked> JSON</label>
                        <label><input type="radio" name="api-body-type" value="form"> Form</label>
                        <label><input type="radio" name="api-body-type" value="form-encoded"> Form URL-Encoded</label>
                        <label><input type="radio" name="api-body-type" value="text"> Texto</label>
                        <label><input type="radio" name="api-body-type" value="xml"> XML</label>
                    </div>
                    <div id="api-body-json"><textarea class="ide-api-body-input" id="api-body-input" placeholder='{"key": "value"}' spellcheck="false"></textarea></div>
                    <div id="api-body-form" style="display:none;">
                        <div class="ide-api-kv-list" id="api-form-list"></div>
                        <button class="ide-api-add-row" id="api-add-form"><i class="fa-solid fa-plus"></i> Adicionar Campo</button>
                    </div>
                </div>
                <!-- Routes -->
                <div class="ide-api-tab-body" id="api-tab-routes" style="display:none;">
                    <div class="ide-api-kv-title">Rotas detectadas no módulo</div>
                    <div class="ide-api-routes" id="api-routes-list"></div>
                </div>
            </div>
            <!-- RESPONSE -->
            <div class="ide-api-response">
                <div class="ide-api-res-header">
                    <span class="ide-api-res-status" id="api-res-status">Aguardando...</span>
                    <span class="ide-api-res-time" id="api-res-time"></span>
                    <span class="ide-api-res-size" id="api-res-size"></span>
                </div>
                <div class="ide-api-res-tabs" id="api-res-tabs">
                    <button class="ide-api-tab active" data-res-tab="res-body">Resposta</button>
                    <button class="ide-api-tab" data-res-tab="res-headers">Headers</button>
                    <button class="ide-api-tab" data-res-tab="res-cookies">Cookies</button>
                </div>
                <div class="ide-api-tab-body" id="api-tab-res-body">
                    <pre class="ide-api-res-body" id="api-res-body">Envie uma requisição para ver a resposta.</pre>
                </div>
                <div class="ide-api-tab-body" id="api-tab-res-headers" style="display:none;">
                    <pre class="ide-api-res-body" id="api-res-headers-out"></pre>
                </div>
                <div class="ide-api-tab-body" id="api-tab-res-cookies" style="display:none;">
                    <pre class="ide-api-res-body" id="api-res-cookies-out">Nenhum cookie na resposta.</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Context Menu (file tree) -->
<div class="ide-ctx-menu" id="ide-ctx-menu" style="display:none;" role="menu" aria-label="Menu de contexto">
    <button class="ide-ctx-item" data-ctx="new-file"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Novo Arquivo</button>
    <button class="ide-ctx-item" data-ctx="new-folder"><i class="fa-solid fa-folder-plus" aria-hidden="true"></i> Nova Pasta</button>
    <div class="ide-ctx-sep"></div>
    <button class="ide-ctx-item" data-ctx="rename"><i class="fa-solid fa-pen" aria-hidden="true"></i> Renomear</button>
    <button class="ide-ctx-item" data-ctx="copy"><i class="fa-solid fa-copy" aria-hidden="true"></i> Copiar</button>
    <button class="ide-ctx-item" data-ctx="delete"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
</div>

<!-- Context Menu (editor - code generation) -->
<div class="ide-ctx-menu ide-ctx-menu-wide" id="ide-code-menu" style="display:none;" role="menu" aria-label="Gerar código">
    <div class="ide-ctx-header">Gerar Código PHP</div>
    <button class="ide-ctx-item" data-gen="constructor"><i class="fa-solid fa-hammer" aria-hidden="true"></i> Construtor</button>
    <button class="ide-ctx-item" data-gen="getters-setters"><i class="fa-solid fa-arrows-left-right" aria-hidden="true"></i> Getters &amp; Setters</button>
    <button class="ide-ctx-item" data-gen="toString"><i class="fa-solid fa-quote-right" aria-hidden="true"></i> Método toString()</button>
    <button class="ide-ctx-item" data-gen="equals"><i class="fa-solid fa-equals" aria-hidden="true"></i> Método equals()</button>
    <button class="ide-ctx-item" data-gen="interface"><i class="fa-solid fa-puzzle-piece" aria-hidden="true"></i> Implementar Interface</button>
    <div class="ide-ctx-sep"></div>
    <button class="ide-ctx-item" data-gen="crud-methods"><i class="fa-solid fa-database" aria-hidden="true"></i> Métodos CRUD</button>
    <button class="ide-ctx-item" data-gen="validation"><i class="fa-solid fa-check-double" aria-hidden="true"></i> Validação de campos</button>
</div>

<!-- MODAL: Confirmação genérica -->
<div class="ide-modal-overlay" id="modal-confirm" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-confirm-title">
    <div class="ide-modal">
        <div class="ide-modal-header">
            <h2 id="modal-confirm-title"></h2>
            <button class="ide-modal-close" id="modal-confirm-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body">
            <p id="modal-confirm-msg"></p>
            <p id="modal-confirm-detail" class="ide-confirm-detail"></p>
        </div>
        <div class="ide-modal-footer">
            <button class="ide-btn-secondary" id="modal-confirm-cancel">Cancelar</button>
            <button class="ide-btn-primary" id="modal-confirm-ok"></button>
        </div>
    </div>
</div>

<div id="ide-toast" role="status" aria-live="polite"></div>

<!-- MODAL: Pré-validação de Migrations -->
<div class="ide-modal-overlay" id="modal-migrate-validate" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="mig-val-title">
    <div class="ide-modal ide-modal-wide">
        <div class="ide-modal-header">
            <h2 id="mig-val-title"></h2>
            <button class="ide-modal-close" id="modal-mig-val-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body" id="mig-val-body" style="max-height:60vh;overflow-y:auto;"></div>
        <div class="ide-modal-footer" id="mig-val-footer"></div>
    </div>
</div>

<!-- MODAL: Bloqueio de Migration (violação) -->
<div class="ide-modal-overlay" id="modal-migrate-blocked" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="mig-blocked-title">
    <div class="ide-modal ide-modal-wide">
        <div class="ide-modal-header">
            <h2 id="mig-blocked-title" style="color:#f38ba8">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                Migração bloqueada por segurança
            </h2>
            <button class="ide-modal-close" id="modal-mig-blocked-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-modal-body" id="mig-blocked-body" style="max-height:60vh;overflow-y:auto;"></div>
        <div class="ide-modal-footer">
            <button class="ide-btn-primary" id="mig-blocked-ok">Entendi</button>
        </div>
    </div>
</div>

<!-- MODAL: Configurações da IDE -->
<div class="ide-modal-overlay ide-settings-overlay" id="modal-settings" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="settings-title">
    <div class="ide-settings-modal">
        <div class="ide-settings-header">
            <div class="ide-settings-title">
                <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                <h2 id="settings-title">Configurações da IDE</h2>
            </div>
            <button class="ide-modal-close" id="modal-settings-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ide-settings-search-bar">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="text" id="settings-search" placeholder="Buscar configuração..." autocomplete="off" spellcheck="false">
        </div>
        <div class="ide-settings-body">
            <nav class="ide-settings-nav" id="settings-nav">
                <button class="ide-settings-nav-item active" data-section="editor">
                    <i class="fa-solid fa-code"></i> Editor
                </button>
                <button class="ide-settings-nav-item" data-section="interface">
                    <i class="fa-solid fa-display"></i> Interface
                </button>
                <button class="ide-settings-nav-item" data-section="icons">
                    <i class="fa-solid fa-icons"></i> Ícones
                </button>
            </nav>
            <div class="ide-settings-content" id="settings-content">

                <!-- SECTION: Editor -->
                <section class="ide-settings-section active" data-section="editor">
                    <h3 class="ide-settings-section-title">Editor de Código</h3>

                    <div class="ide-settings-item" data-tags="fonte tamanho editor código">
                        <div class="ide-settings-item-info">
                            <span class="ide-settings-item-label">Tamanho da fonte do editor</span>
                            <span class="ide-settings-item-desc">Tamanho da fonte nos arquivos de código</span>
                        </div>
                        <div class="ide-settings-item-control">
                            <button class="ide-settings-stepper" data-action="dec" data-target="editor-font-size">−</button>
                            <span class="ide-settings-value" id="val-editor-font-size">15</span>
                            <span class="ide-settings-unit">px</span>
                            <button class="ide-settings-stepper" data-action="inc" data-target="editor-font-size">+</button>
                        </div>
                    </div>

                    <div class="ide-settings-item" data-tags="linha altura espaçamento editor">
                        <div class="ide-settings-item-info">
                            <span class="ide-settings-item-label">Altura de linha do editor</span>
                            <span class="ide-settings-item-desc">Espaçamento entre linhas no editor</span>
                        </div>
                        <div class="ide-settings-item-control">
                            <button class="ide-settings-stepper" data-action="dec" data-target="editor-line-height">−</button>
                            <span class="ide-settings-value" id="val-editor-line-height">22</span>
                            <span class="ide-settings-unit">px</span>
                            <button class="ide-settings-stepper" data-action="inc" data-target="editor-line-height">+</button>
                        </div>
                    </div>

                    <div class="ide-settings-item" data-tags="minimap mapa editor">
                        <div class="ide-settings-item-info">
                            <span class="ide-settings-item-label">Minimapa</span>
                            <span class="ide-settings-item-desc">Exibir miniatura do código no lado direito do editor</span>
                        </div>
                        <div class="ide-settings-item-control">
                            <label class="ide-settings-toggle">
                                <input type="checkbox" id="toggle-minimap">
                                <span class="ide-settings-toggle-track"></span>
                            </label>
                        </div>
                    </div>

                    <div class="ide-settings-item" data-tags="word wrap quebra linha editor">
                        <div class="ide-settings-item-info">
                            <span class="ide-settings-item-label">Quebra de linha automática</span>
                            <span class="ide-settings-item-desc">Quebrar linhas longas automaticamente</span>
                        </div>
                        <div class="ide-settings-item-control">
                            <label class="ide-settings-toggle">
                                <input type="checkbox" id="toggle-wordwrap">
                                <span class="ide-settings-toggle-track"></span>
                            </label>
                        </div>
                    </div>
                </section>

                <!-- SECTION: Interface -->
                <section class="ide-settings-section" data-section="interface">
                    <h3 class="ide-settings-section-title">Interface</h3>

                    <div class="ide-settings-item" data-tags="fonte tamanho interface ui">
                        <div class="ide-settings-item-info">
                            <span class="ide-settings-item-label">Tamanho da fonte da interface</span>
                            <span class="ide-settings-item-desc">Afeta menus, painéis, abas e textos da IDE</span>
                        </div>
                        <div class="ide-settings-item-control">
                            <button class="ide-settings-stepper" data-action="dec" data-target="ui-font-size">−</button>
                            <span class="ide-settings-value" id="val-ui-font-size">14</span>
                            <span class="ide-settings-unit">px</span>
                            <button class="ide-settings-stepper" data-action="inc" data-target="ui-font-size">+</button>
                        </div>
                    </div>

                    <div class="ide-settings-item" data-tags="painel largura arquivo">
                        <div class="ide-settings-item-info">
                            <span class="ide-settings-item-label">Largura do painel de arquivos</span>
                            <span class="ide-settings-item-desc">Largura padrão do explorador de arquivos</span>
                        </div>
                        <div class="ide-settings-item-control">
                            <button class="ide-settings-stepper" data-action="dec" data-target="panel-width" data-step="20">−</button>
                            <span class="ide-settings-value" id="val-panel-width">260</span>
                            <span class="ide-settings-unit">px</span>
                            <button class="ide-settings-stepper" data-action="inc" data-target="panel-width" data-step="20">+</button>
                        </div>
                    </div>
                </section>

                <!-- SECTION: Ícones -->
                <section class="ide-settings-section" data-section="icons">
                    <h3 class="ide-settings-section-title">Ícones</h3>

                    <div class="ide-settings-item" data-tags="icone tamanho interface">
                        <div class="ide-settings-item-info">
                            <span class="ide-settings-item-label">Tamanho dos ícones da interface</span>
                            <span class="ide-settings-item-desc">Afeta ícones da topbar, painéis e botões</span>
                        </div>
                        <div class="ide-settings-item-control">
                            <button class="ide-settings-stepper" data-action="dec" data-target="ui-icon-size">−</button>
                            <span class="ide-settings-value" id="val-ui-icon-size">14</span>
                            <span class="ide-settings-unit">px</span>
                            <button class="ide-settings-stepper" data-action="inc" data-target="ui-icon-size">+</button>
                        </div>
                    </div>

                    <div class="ide-settings-item" data-tags="icone arquivo arvore filetree">
                        <div class="ide-settings-item-info">
                            <span class="ide-settings-item-label">Tamanho dos ícones de arquivo</span>
                            <span class="ide-settings-item-desc">Ícones na árvore de arquivos</span>
                        </div>
                        <div class="ide-settings-item-control">
                            <button class="ide-settings-stepper" data-action="dec" data-target="filetree-icon-size">−</button>
                            <span class="ide-settings-value" id="val-filetree-icon-size">13</span>
                            <span class="ide-settings-unit">px</span>
                            <button class="ide-settings-stepper" data-action="inc" data-target="filetree-icon-size">+</button>
                        </div>
                    </div>
                </section>

            </div>
        </div>
        <div class="ide-settings-footer">
            <button class="ide-btn-secondary" id="settings-reset">
                <i class="fa-solid fa-rotate-left"></i> Restaurar padrões
            </button>
            <button class="ide-btn-primary" id="settings-close-btn">
                <i class="fa-solid fa-check"></i> Fechar
            </button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js" integrity="sha512-jB0TkTBeQC9ZSkBqDhdmfTv1qdfbWpGE72yJ/01Srq6hEzZIz2xkz1e57p9ai7IeHMwEG7HpzG6NdptChif5Pg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="/assets/js/trusted-types-policy.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/trusted-types-policy.js') ?: time() ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs/loader.min.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function(){
    var dark = localStorage.getItem('dash-dark-mode') === '1';
    if (dark) { document.body.classList.add('dark'); var ic=document.getElementById('theme-icon'); if(ic) ic.className='fa-solid fa-sun'; }
    document.documentElement.classList.remove('will-dark');
})();
</script>
<script src="/assets/js/ide.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/ide.js') ?: time() ?>"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
// ── Bloqueia menu nativo do browser na IDE ────────────────────────────────────
// O menu de contexto da IDE é gerenciado pelo ide.js — o menu nativo do browser
// (inspecionar, ver código-fonte, etc.) é bloqueado para proteger o ambiente.
(function() {
    // Bloqueia F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C, Ctrl+U
    document.addEventListener('keydown', function(e) {
        if (e.key === 'F12') { e.preventDefault(); return false; }
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && ['I','i','J','j','C','c'].includes(e.key)) {
            e.preventDefault(); return false;
        }
        if ((e.ctrlKey || e.metaKey) && ['U','u'].includes(e.key)) {
            e.preventDefault(); return false;
        }
    }, true);

    // Bloqueia o menu nativo do browser em toda a página da IDE
    // O menu de contexto da IDE (ide-ctx-menu) é gerenciado pelo ide.js
    document.addEventListener('contextmenu', function(e) {
        // Permite apenas dentro da área do file tree e do editor (gerenciados pelo ide.js)
        var inFileTree = e.target.closest('#ide-filetree');
        var inEditor   = e.target.closest('#ide-editor-container');
        if (!inFileTree && !inEditor) {
            e.preventDefault();
        }
    }, true);
})();
</script>
</body>
</html>
