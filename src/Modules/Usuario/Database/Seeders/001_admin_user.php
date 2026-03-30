<?php
/**
 * Seeder: Módulo Usuario — Usuário admin padrão
 *
 * Cria o usuário admin_system inicial se não existir.
 *
 * Credenciais padrão:
 *   E-mail: admin@admin.com
 *   Senha:  admin123
 *
 * Sobrescreva via variáveis de ambiente:
 *   ADMIN_EMAIL, ADMIN_PASSWORD, ADMIN_NAME, ADMIN_USERNAME
 *
 * ⚠ TROQUE A SENHA EM PRODUÇÃO!
 */
return function (PDO $pdo): void {
    $email    = $_ENV['ADMIN_EMAIL']    ?? 'admin@admin.com';
    $senha    = $_ENV['ADMIN_PASSWORD'] ?? 'admin123';
    $nome     = $_ENV['ADMIN_NAME']     ?? 'Administrador';
    $username = $_ENV['ADMIN_USERNAME'] ?? 'admin';

    // Verifica se já existe pelo e-mail
    $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetchColumn()) {
        echo "  ⊘ Admin já existe: $email\n";
        return;
    }

    // Verifica se o username já está em uso
    $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = :username LIMIT 1");
    $stmt->execute([':username' => $username]);
    if ($stmt->fetchColumn()) {
        $username = 'admin_' . substr(md5($email), 0, 6);
    }

    $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();

    // Usa PASSWORD_BCRYPT para compatibilidade máxima
    // O seeder cria o hash diretamente — sem passar pela validação de complexidade
    // da entidade Usuario, pois é um usuário de bootstrap do sistema
    $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios
                (uuid, nome_completo, username, email, senha_hash,
                 nivel_acesso, ativo, verificado_email, status_verificacao, criado_em)
            VALUES
                (:uuid, :nome, :username, :email, :hash,
                 'admin_system', TRUE, TRUE, 'verificado', NOW())
        ");
    } else {
        // MySQL — BOOLEAN como 1
        $stmt = $pdo->prepare("
            INSERT INTO usuarios
                (uuid, nome_completo, username, email, senha_hash,
                 nivel_acesso, ativo, verificado_email, status_verificacao, criado_em)
            VALUES
                (:uuid, :nome, :username, :email, :hash,
                 'admin_system', 1, 1, 'verificado', NOW())
        ");
    }

    $stmt->execute([
        ':uuid'     => $uuid,
        ':nome'     => $nome,
        ':username' => $username,
        ':email'    => $email,
        ':hash'     => $hash,
    ]);

    echo "  ✔ Admin criado com sucesso!\n";
    echo "    E-mail:   $email\n";
    echo "    Username: $username\n";
    echo "    Senha:    $senha\n";
    echo "    Nível:    admin_system\n";
    echo "\n  ⚠  TROQUE A SENHA EM PRODUÇÃO!\n";
};
