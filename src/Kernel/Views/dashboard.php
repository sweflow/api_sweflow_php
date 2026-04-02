<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo ?? 'Dashboard da API', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= time() ?>">
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
                    <li class="nav-section-label">Navegação</li>
                    <li><a href="/"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></li>

                    <li class="nav-divider"></li>
                    <li class="nav-section-label">Monitoramento</li>
                    <li><a href="#metrics"><i class="fa-solid fa-chart-line"></i> Métricas</a></li>
                    <li><a href="#modules"><i class="fa-solid fa-layer-group"></i> Módulos</a></li>
                    <li><a href="#routes"><i class="fa-solid fa-route"></i> Rotas</a></li>

                    <li class="nav-divider"></li>
                    <li class="nav-section-label">Configuração</li>
                    <li><a href="#capabilities"><i class="fa-solid fa-plug"></i> Capacidades</a></li>
                    <li><a href="/modules/marketplace"><i class="fa-solid fa-store"></i> Marketplace</a></li>

                    <li class="nav-divider"></li>
                    <li class="nav-section-label">Conta</li>
                    <li><a href="/dashboard/usuarios"><i class="fa-solid fa-users"></i> Usuários</a></li>
                    <li><a href="#" id="open-meu-perfil"><i class="fa-solid fa-circle-user"></i> Meu perfil</a></li>
                    <li><a href="#" id="open-criar-usuario"><i class="fa-solid fa-user-plus"></i> Novo usuário</a></li>

                    <li class="nav-divider"></li>
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

            <section class="card" id="email-actions">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <h2 style="margin:0;display:flex;align-items:center;gap:10px;"><i class="fa-solid fa-envelope"></i> Disparo de e-mail <span id="email-module-state" class="pill" style="font-weight:700;">Carregando...</span></h2>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="btn ghost" id="open-email-history"><i class="fa-solid fa-clock-rotate-left"></i> Histórico</button>
                        <button class="btn primary" id="open-email-modal"><i class="fa-solid fa-paper-plane"></i> Enviar e-mail personalizado</button>
                    </div>
                </div>
                <p style="margin-top:10px;color:#505050;">Envie comunicações de confirmação, recuperação de senha ou mensagens customizadas.</p>
            </section>

            <section class="card" id="features">
                <h2><i class="fa-solid fa-toggle-on"></i> Funcionalidades (módulos)</h2>
                <div class="toggle-grid" id="modules-toggle-list">Carregando...</div>
                <div class="toggle-card" id="auth-verify-card" style="margin-top:12px;">
                    <div class="toggle-info">
                        <span class="toggle-name">Login só após e-mail verificado</span>
                        <span class="toggle-tag" id="auth-verify-tag">Carregando...</span>
                        <small style="color:#6c6c6c;">Aplicável a novos logins do módulo Auth; exige <code>verificado_email = true</code>.</small>
                    </div>
                    <label class="switch">
                        <input type="checkbox" id="require-email-verification" />
                        <span class="slider"></span>
                    </label>
                </div>
            </section>

            <section class="card" id="modules">
                <h2><i class="fa-solid fa-layer-group"></i> Módulos registrados</h2>
                <div id="modules-list" class="module-grid">
                    Carregando...
                </div>
            </section>

            <section class="card" id="routes">
                <h2><i class="fa-solid fa-route"></i> Rotas dos módulos</h2>
                <div id="routes-list" style="overflow-x:auto;">Carregando...</div>
            </section>

            <section class="card" id="capabilities">
                <h2><i class="fa-solid fa-plug"></i> Capacidades</h2>
                <div id="capabilities-list">Carregando...</div>
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

    <div class="modal-overlay" id="email-disabled-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-ban"></i> Módulo desabilitado</h2>
                <button class="modal-close" id="email-disabled-close" aria-label="Fechar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <p>O módulo de E-mail está desabilitado. Habilite em "Funcionalidades" para usar os envios.</p>
            <div class="form-actions" style="justify-content: flex-end;">
                <button class="btn primary" id="email-disabled-ok">Entendi</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="email-modal">
        <div class="modal email-modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-envelope"></i> Enviar e-mail personalizado</h2>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button class="btn ghost" id="email-preview-btn" type="button"><i class="fa-solid fa-eye"></i> Pré-visualizar</button>
                    <button class="btn ghost" id="email-fullscreen-btn" type="button"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> Tela cheia</button>
                    <button class="modal-close" id="email-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
            <form id="email-form" class="email-form" autocomplete="off">
                <div class="input-group">
                    <label for="email-to">Para</label>
                    <input type="text" id="email-to" name="to" placeholder="email@exemplo.com ou vários separados por vírgula" />
                    <small>Para múltiplos e-mails, separe por vírgula ou quebra de linha.</small>
                </div>
                <div class="input-group">
                    <label for="email-subject">Assunto</label>
                    <input type="text" id="email-subject" name="subject" placeholder="Assunto do e-mail" required />
                </div>
                <div class="input-group">
                    <label for="email-logo">Logo (URL opcional)</label>
                    <input type="url" id="email-logo" name="logo_url" placeholder="https://.../logo.png" />
                </div>

                <div class="email-toolbar" id="email-toolbar">
                    <button type="button" data-cmd="bold"><i class="fa-solid fa-bold"></i></button>
                    <button type="button" data-cmd="italic"><i class="fa-solid fa-italic"></i></button>
                    <button type="button" data-cmd="underline"><i class="fa-solid fa-underline"></i></button>
                    <button type="button" data-cmd="strikeThrough"><i class="fa-solid fa-strikethrough"></i></button>
                    <button type="button" data-cmd="insertOrderedList"><i class="fa-solid fa-list-ol"></i></button>
                    <button type="button" data-cmd="insertUnorderedList"><i class="fa-solid fa-list-ul"></i></button>
                    <button type="button" data-cmd="formatBlock" data-value="blockquote"><i class="fa-solid fa-quote-left"></i></button>
                    <button type="button" data-cmd="formatBlock" data-value="pre"><i class="fa-solid fa-code"></i></button>
                    <button type="button" data-cmd="align-left" title="Alinhar à esquerda"><i class="fa-solid fa-align-left"></i></button>
                    <button type="button" data-cmd="align-center" title="Centralizar"><i class="fa-solid fa-align-center"></i></button>
                    <button type="button" data-cmd="align-right" title="Alinhar à direita"><i class="fa-solid fa-align-right"></i></button>
                    <button type="button" data-cmd="align-justify" title="Justificar"><i class="fa-solid fa-align-justify"></i></button>
                    <button type="button" data-cmd="createLink"><i class="fa-solid fa-link"></i></button>
                    <button type="button" data-cmd="insertImage"><i class="fa-solid fa-image"></i></button>
                    <select id="email-font-size" aria-label="Tamanho da fonte">
                        <option value="">Tam.</option>
                        <option value="12">12px</option>
                        <option value="14">14px</option>
                        <option value="18">18px</option>
                        <option value="22">22px</option>
                        <option value="28">28px</option>
                        <option value="36">36px</option>
                    </select>
                    <label class="color-picker">Cor
                        <input type="color" id="email-font-color" />
                    </label>
                    <label class="color-picker">Fundo
                        <input type="color" id="email-bg-color" />
                    </label>
                </div>

                <div class="email-editor" id="email-editor" contenteditable="true" aria-label="Editor de e-mail"></div>
                <div class="email-preview" id="email-preview" hidden></div>

                <div class="form-actions" style="justify-content: flex-end;">
                    <button type="button" class="btn ghost" id="email-cancel">Cancelar</button>
                    <button type="submit" class="btn primary" id="email-send"><i class="fa-solid fa-paper-plane"></i> Enviar</button>
                </div>
                <div id="email-feedback" class="login-feedback" aria-live="polite"></div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="link-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-link"></i> Inserir link</h2>
                <button class="modal-close" id="link-close" aria-label="Fechar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="input-group">
                <label for="link-url">URL do link</label>
                <input type="url" id="link-url" name="link-url" placeholder="https://exemplo.com" />
                <small class="hint">Selecione o texto no editor e informe a URL do link.</small>
            </div>
            <div class="form-actions" style="justify-content: flex-end;">
                <button class="btn ghost" id="link-cancel">Cancelar</button>
                <button class="btn primary" id="link-confirm">Inserir</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="image-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-image"></i> Inserir imagem</h2>
                <button class="modal-close" id="image-close" aria-label="Fechar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="input-group">
                <label for="image-url">URL da imagem</label>
                <input type="url" id="image-url" name="image-url" placeholder="https://exemplo.com/imagem.png" />
                <small class="hint">Informe a URL pública da imagem que será embutida no e-mail.</small>
            </div>
            <div class="form-actions" style="justify-content: flex-end;">
                <button class="btn ghost" id="image-cancel">Cancelar</button>
                <button class="btn primary" id="image-confirm">Inserir</button>
            </div>
        </div>
    </div>

    <!-- Histórico de e-mails -->
    <div class="modal-overlay" id="email-history-modal">
        <div class="modal" style="max-width:780px;width:95vw;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-clock-rotate-left"></i> Histórico de e-mails</h2>
                <button class="modal-close" id="email-history-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:0 0 10px;">
                <input type="search" id="email-history-search" placeholder="Buscar por assunto, destinatário, status..." style="width:100%;padding:10px 12px;border:1px solid #d5daf2;border-radius:10px;font-size:.95rem;box-sizing:border-box;" />
            </div>
            <div id="email-history-list" style="max-height:55vh;overflow-y:auto;">
                <p style="color:#888;text-align:center;padding:24px;">Carregando...</p>
            </div>
        </div>
    </div>

    <!-- Detalhe de e-mail do histórico -->
    <div class="modal-overlay" id="email-detail-modal">
        <div class="modal email-modal" style="max-width:780px;width:95vw;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-envelope-open-text"></i> Detalhes do e-mail</h2>
                <button class="modal-close" id="email-detail-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="email-detail-body" style="overflow-y:auto;max-height:65vh;"></div>
            <div class="form-actions" style="justify-content:flex-end;margin-top:16px;">
                <button class="btn ghost" id="email-detail-edit"><i class="fa-solid fa-pen"></i> Editar e reenviar</button>
                <button class="btn ghost" id="email-detail-resend"><i class="fa-solid fa-rotate-right"></i> Reenviar</button>
                <button class="btn" style="background:#e74c3c;color:#fff;" id="email-detail-delete"><i class="fa-solid fa-trash"></i> Excluir</button>
            </div>
        </div>
    </div>

    <!-- Confirmação de exclusão -->
    <div class="modal-overlay" id="email-delete-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-trash"></i> Excluir registro</h2>
                <button class="modal-close" id="email-delete-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p>Tem certeza que deseja excluir este registro do histórico? Esta ação não pode ser desfeita.</p>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn ghost" id="email-delete-cancel">Cancelar</button>
                <button class="btn" style="background:#e74c3c;color:#fff;" id="email-delete-confirm">Excluir</button>
            </div>
        </div>
    </div>

    <!-- ── Meu Perfil (visualização) ──────────────────────────────────── -->
    <div class="modal-overlay" id="meu-perfil-modal">
        <div class="modal" style="max-width:520px;width:95vw;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-circle-user"></i> Meu perfil</h2>
                <button class="modal-close" id="meu-perfil-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="meu-perfil-body" style="display:flex;flex-direction:column;gap:14px;">
                <p style="color:#888;text-align:center;">Carregando...</p>
            </div>
            <div class="form-actions" style="justify-content:flex-end;margin-top:16px;gap:8px;">
                <button class="btn ghost" id="meu-perfil-alterar-senha"><i class="fa-solid fa-key"></i> Alterar senha</button>
                <button class="btn primary" id="meu-perfil-editar"><i class="fa-solid fa-pen"></i> Editar dados</button>
            </div>
        </div>
    </div>

    <!-- ── Editar Perfil ───────────────────────────────────────────────── -->
    <div class="modal-overlay" id="editar-perfil-modal">
        <div class="modal" style="max-width:560px;width:95vw;max-height:90vh;overflow-y:auto;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-pen"></i> Editar dados</h2>
                <button class="modal-close" id="editar-perfil-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="editar-perfil-form" autocomplete="off" style="display:flex;flex-direction:column;gap:14px;">
                <div class="input-group">
                    <label for="ep-nome">Nome completo</label>
                    <input type="text" id="ep-nome" placeholder="Seu nome completo" />
                </div>
                <div class="input-group">
                    <label for="ep-username">Username</label>
                    <input type="text" id="ep-username" placeholder="seu.username" autocomplete="off" />
                    <small id="ep-username-feedback" class="hint"></small>
                </div>
                <div class="input-group">
                    <label for="ep-email">E-mail</label>
                    <input type="email" id="ep-email" placeholder="email@exemplo.com" />
                    <small id="ep-email-feedback" class="hint"></small>
                    <small class="hint">Para alterar o e-mail, informe sua senha atual abaixo.</small>
                </div>
                <div class="input-group" id="ep-senha-email-group" style="display:none;">
                    <label for="ep-senha-email">Senha atual (para confirmar troca de e-mail)</label>
                    <input type="password" id="ep-senha-email" placeholder="Senha atual" autocomplete="current-password" />
                </div>
                <div class="input-group">
                    <label for="ep-avatar">URL do avatar</label>
                    <input type="url" id="ep-avatar" placeholder="https://..." />
                    <div id="ep-avatar-preview" style="margin-top:6px;display:none;">
                        <img id="ep-avatar-img" src="" alt="Preview avatar" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #e0e0e0;" />
                    </div>
                </div>
                <div class="input-group">
                    <label for="ep-capa">URL da capa</label>
                    <input type="url" id="ep-capa" placeholder="https://..." />
                    <div id="ep-capa-preview" style="margin-top:6px;display:none;">
                        <img id="ep-capa-img" src="" alt="Preview capa" style="width:100%;max-height:80px;object-fit:cover;border-radius:8px;border:2px solid #e0e0e0;" />
                    </div>
                </div>
                <div class="input-group">
                    <label for="ep-bio">Biografia</label>
                    <textarea id="ep-bio" rows="3" placeholder="Fale um pouco sobre você..." style="padding:10px;border:1px solid #d5daf2;border-radius:10px;font-size:1rem;resize:vertical;"></textarea>
                </div>
                <div id="ep-feedback" class="login-feedback" aria-live="polite"></div>
                <div class="form-actions" style="justify-content:flex-end;">
                    <button type="button" class="btn ghost" id="editar-perfil-cancel">Cancelar</button>
                    <button type="submit" class="btn primary" id="editar-perfil-save"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Alterar Senha ───────────────────────────────────────────────── -->
    <div class="modal-overlay" id="alterar-senha-modal">
        <div class="modal" style="max-width:460px;width:95vw;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-key"></i> Alterar senha</h2>
                <button class="modal-close" id="alterar-senha-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="alterar-senha-form" autocomplete="off" style="display:flex;flex-direction:column;gap:14px;">
                <div class="input-group">
                    <label for="as-atual">Senha atual</label>
                    <input type="password" id="as-atual" placeholder="Senha atual" autocomplete="current-password" />
                </div>
                <div class="input-group">
                    <label for="as-nova">Nova senha</label>
                    <input type="password" id="as-nova" placeholder="Nova senha" autocomplete="new-password" />
                </div>
                <div class="input-group">
                    <label for="as-confirmar">Confirmar nova senha</label>
                    <input type="password" id="as-confirmar" placeholder="Confirmar nova senha" autocomplete="new-password" />
                </div>
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

    <!-- ── Criar Usuário ───────────────────────────────────────────────── -->
    <div class="modal-overlay" id="criar-usuario-modal">
        <div class="modal" style="max-width:560px;width:95vw;max-height:90vh;overflow-y:auto;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-plus"></i> Novo usuário</h2>
                <button class="modal-close" id="criar-usuario-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="criar-usuario-form" autocomplete="off" style="display:flex;flex-direction:column;gap:14px;">
                <div class="input-group">
                    <label for="cu-nome">Nome completo <span style="color:#e74c3c;">*</span></label>
                    <input type="text" id="cu-nome" placeholder="Nome completo" required />
                </div>
                <div class="input-group">
                    <label for="cu-username">Username <span style="color:#e74c3c;">*</span></label>
                    <input type="text" id="cu-username" placeholder="usuario.exemplo" autocomplete="off" required />
                    <small id="cu-username-feedback" class="hint"></small>
                </div>
                <div class="input-group">
                    <label for="cu-email">E-mail <span style="color:#e74c3c;">*</span></label>
                    <input type="email" id="cu-email" placeholder="email@exemplo.com" required />
                    <small id="cu-email-feedback" class="hint"></small>
                </div>
                <div class="input-group">
                    <label for="cu-nivel">Nível de acesso</label>
                    <select id="cu-nivel" style="padding:10px;border:1px solid #d5daf2;border-radius:10px;font-size:1rem;background:#fff;">
                        <option value="usuario">Usuário</option>
                        <option value="moderador">Moderador</option>
                        <option value="admin">Admin</option>
                        <option value="admin_system">Admin System</option>
                    </select>
                </div>
                <div class="input-group">
                    <label for="cu-senha">Senha <span style="color:#e74c3c;">*</span></label>
                    <input type="password" id="cu-senha" placeholder="Senha segura" autocomplete="new-password" required />
                </div>
                <div class="input-group">
                    <label for="cu-confirmar">Confirmar senha <span style="color:#e74c3c;">*</span></label>
                    <input type="password" id="cu-confirmar" placeholder="Confirmar senha" autocomplete="new-password" required />
                </div>
                <div id="cu-regras" class="senha-regras">
                    <div class="regra" id="cu-r-len"><i class="fa-solid fa-circle-xmark"></i> Mínimo 8 caracteres</div>
                    <div class="regra" id="cu-r-upper"><i class="fa-solid fa-circle-xmark"></i> Uma letra maiúscula</div>
                    <div class="regra" id="cu-r-lower"><i class="fa-solid fa-circle-xmark"></i> Uma letra minúscula</div>
                    <div class="regra" id="cu-r-num"><i class="fa-solid fa-circle-xmark"></i> Um número</div>
                    <div class="regra" id="cu-r-special"><i class="fa-solid fa-circle-xmark"></i> Um caractere especial</div>
                    <div class="regra" id="cu-r-match"><i class="fa-solid fa-circle-xmark"></i> Senhas coincidem</div>
                </div>
                <div id="cu-feedback" class="login-feedback" aria-live="polite"></div>
                <div class="form-actions" style="justify-content:flex-end;">
                    <button type="button" class="btn ghost" id="criar-usuario-cancel">Cancelar</button>
                    <button type="submit" class="btn primary" id="criar-usuario-save" disabled><i class="fa-solid fa-user-plus"></i> Criar usuário</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de erro genérico -->
    <div class="modal-overlay" id="error-modal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fa-solid fa-circle-exclamation"></i> <span id="error-modal-title">Erro</span></h2>
                <button class="modal-close" id="error-modal-close" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p id="error-modal-message"></p>
            <div class="form-actions" style="justify-content:flex-end;">
                <button class="btn primary" id="error-modal-ok">OK</button>
            </div>
        </div>
    </div>

    <script src="/assets/dashboard.js?v=<?= time() ?>"></script>
</body>
</html>
