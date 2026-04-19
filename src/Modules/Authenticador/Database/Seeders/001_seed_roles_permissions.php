<?php
/**
 * Seeder: Roles e Permissions iniciais
 * 
 * Popula o sistema com roles e permissões padrão
 */
return [
    'run' => function (PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isPostgres = $driver === 'pgsql';
        
        // ═══════════════════════════════════════════════════════════
        // ROLES (Papéis)
        // ═══════════════════════════════════════════════════════════
        
        $roles = [
            [
                'nome' => 'Super Administrador',
                'slug' => 'super_admin',
                'descricao' => 'Acesso total ao sistema, incluindo configurações críticas',
                'nivel' => 100,
                'sistema' => true,
            ],
            [
                'nome' => 'Administrador',
                'slug' => 'admin',
                'descricao' => 'Gerencia usuários, conteúdo e configurações gerais',
                'nivel' => 80,
                'sistema' => true,
            ],
            [
                'nome' => 'Moderador',
                'slug' => 'moderador',
                'descricao' => 'Modera conteúdo e gerencia usuários básicos',
                'nivel' => 50,
                'sistema' => true,
            ],
            [
                'nome' => 'Usuário',
                'slug' => 'usuario',
                'descricao' => 'Usuário padrão com permissões básicas',
                'nivel' => 10,
                'sistema' => true,
            ],
        ];
        
        foreach ($roles as $role) {
            if ($isPostgres) {
                $stmt = $pdo->prepare("
                    INSERT INTO roles (uuid, nome, slug, descricao, nivel, sistema, ativo)
                    VALUES (gen_random_uuid(), :nome, :slug, :descricao, :nivel, :sistema, TRUE)
                    ON CONFLICT (slug) DO NOTHING
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO roles (uuid, nome, slug, descricao, nivel, sistema, ativo)
                    VALUES (UUID(), :nome, :slug, :descricao, :nivel, :sistema, 1)
                ");
            }
            
            $stmt->execute([
                'nome' => $role['nome'],
                'slug' => $role['slug'],
                'descricao' => $role['descricao'],
                'nivel' => $role['nivel'],
                'sistema' => $role['sistema'] ? ($isPostgres ? 'TRUE' : 1) : ($isPostgres ? 'FALSE' : 0),
            ]);
        }
        
        // ═══════════════════════════════════════════════════════════
        // PERMISSIONS (Permissões)
        // ═══════════════════════════════════════════════════════════
        
        $permissions = [
            // ── Usuários ──
            ['nome' => 'Visualizar Usuários', 'slug' => 'usuarios.view', 'modulo' => 'usuarios2', 'categoria' => 'usuarios'],
            ['nome' => 'Criar Usuários', 'slug' => 'usuarios.create', 'modulo' => 'usuarios2', 'categoria' => 'usuarios'],
            ['nome' => 'Editar Usuários', 'slug' => 'usuarios.edit', 'modulo' => 'usuarios2', 'categoria' => 'usuarios'],
            ['nome' => 'Deletar Usuários', 'slug' => 'usuarios.delete', 'modulo' => 'usuarios2', 'categoria' => 'usuarios'],
            ['nome' => 'Bloquear Usuários', 'slug' => 'usuarios.block', 'modulo' => 'usuarios2', 'categoria' => 'usuarios'],
            ['nome' => 'Gerenciar Próprio Perfil', 'slug' => 'usuarios.manage_own', 'modulo' => 'usuarios2', 'categoria' => 'usuarios'],
            
            // ── Roles e Permissões ──
            ['nome' => 'Visualizar Roles', 'slug' => 'roles.view', 'modulo' => 'authenticador', 'categoria' => 'permissoes'],
            ['nome' => 'Criar Roles', 'slug' => 'roles.create', 'modulo' => 'authenticador', 'categoria' => 'permissoes'],
            ['nome' => 'Editar Roles', 'slug' => 'roles.edit', 'modulo' => 'authenticador', 'categoria' => 'permissoes'],
            ['nome' => 'Deletar Roles', 'slug' => 'roles.delete', 'modulo' => 'authenticador', 'categoria' => 'permissoes'],
            ['nome' => 'Atribuir Roles', 'slug' => 'roles.assign', 'modulo' => 'authenticador', 'categoria' => 'permissoes'],
            ['nome' => 'Gerenciar Permissões', 'slug' => 'permissions.manage', 'modulo' => 'authenticador', 'categoria' => 'permissoes'],
            
            // ── Sessões ──
            ['nome' => 'Visualizar Sessões', 'slug' => 'sessoes.view', 'modulo' => 'authenticador', 'categoria' => 'sessoes'],
            ['nome' => 'Revogar Sessões', 'slug' => 'sessoes.revoke', 'modulo' => 'authenticador', 'categoria' => 'sessoes'],
            ['nome' => 'Visualizar Próprias Sessões', 'slug' => 'sessoes.view_own', 'modulo' => 'authenticador', 'categoria' => 'sessoes'],
            ['nome' => 'Revogar Próprias Sessões', 'slug' => 'sessoes.revoke_own', 'modulo' => 'authenticador', 'categoria' => 'sessoes'],
            
            // ── Auditoria ──
            ['nome' => 'Visualizar Logs', 'slug' => 'logs.view', 'modulo' => 'authenticador', 'categoria' => 'auditoria'],
            ['nome' => 'Exportar Logs', 'slug' => 'logs.export', 'modulo' => 'authenticador', 'categoria' => 'auditoria'],
            ['nome' => 'Limpar Logs', 'slug' => 'logs.clear', 'modulo' => 'authenticador', 'categoria' => 'auditoria'],
            
            // ── Sistema ──
            ['nome' => 'Acessar Configurações', 'slug' => 'sistema.config', 'modulo' => 'authenticador', 'categoria' => 'sistema'],
            ['nome' => 'Gerenciar Módulos', 'slug' => 'sistema.modules', 'modulo' => 'authenticador', 'categoria' => 'sistema'],
            ['nome' => 'Executar Migrations', 'slug' => 'sistema.migrations', 'modulo' => 'authenticador', 'categoria' => 'sistema'],
        ];
        
        foreach ($permissions as $perm) {
            if ($isPostgres) {
                $stmt = $pdo->prepare("
                    INSERT INTO permissions (uuid, nome, slug, descricao, modulo, categoria, ativo)
                    VALUES (gen_random_uuid(), :nome, :slug, NULL, :modulo, :categoria, TRUE)
                    ON CONFLICT (slug) DO NOTHING
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO permissions (uuid, nome, slug, descricao, modulo, categoria, ativo)
                    VALUES (UUID(), :nome, :slug, NULL, :modulo, :categoria, 1)
                ");
            }
            
            $stmt->execute([
                'nome' => $perm['nome'],
                'slug' => $perm['slug'],
                'modulo' => $perm['modulo'],
                'categoria' => $perm['categoria'],
            ]);
        }
        
        // ═══════════════════════════════════════════════════════════
        // ATRIBUIR PERMISSÕES ÀS ROLES
        // ═══════════════════════════════════════════════════════════
        
        // Super Admin: TODAS as permissões
        $pdo->exec("
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE r.slug = 'super_admin'
            ON CONFLICT DO NOTHING
        ");
        
        // Admin: Todas exceto migrations e módulos
        $pdo->exec("
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
            FROM roles r
            CROSS JOIN permissions p
            WHERE r.slug = 'admin'
            AND p.slug NOT IN ('sistema.migrations', 'sistema.modules')
            ON CONFLICT DO NOTHING
        ");
        
        // Moderador: Visualizar e gerenciar usuários básicos
        $moderadorPerms = [
            'usuarios.view', 'usuarios.edit', 'usuarios.block',
            'sessoes.view', 'sessoes.revoke',
            'logs.view',
        ];
        
        foreach ($moderadorPerms as $slug) {
            $pdo->exec("
                INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id
                FROM roles r
                CROSS JOIN permissions p
                WHERE r.slug = 'moderador' AND p.slug = '{$slug}'
                ON CONFLICT DO NOTHING
            ");
        }
        
        // Usuário: Apenas gerenciar próprio perfil e sessões
        $usuarioPerms = [
            'usuarios.manage_own',
            'sessoes.view_own',
            'sessoes.revoke_own',
        ];
        
        foreach ($usuarioPerms as $slug) {
            $pdo->exec("
                INSERT INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id
                FROM roles r
                CROSS JOIN permissions p
                WHERE r.slug = 'usuario' AND p.slug = '{$slug}'
                ON CONFLICT DO NOTHING
            ");
        }
    },
];
