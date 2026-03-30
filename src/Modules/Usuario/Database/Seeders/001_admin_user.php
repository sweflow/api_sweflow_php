<?php
/**
 * Seeder: Módulo Usuario — Usuário admin padrão
 *
 * Cria o usuário admin_system inicial se não existir.
 * Senha padrão: Admin@123456 (TROQUE EM PRODUÇÃO)
 */
return function (PDO $pdo): void {
    $email = $_ENV['ADMIN_EMAIL'] ?? 'admin@sweflow.local';
    $senha = $_ENV['ADMIN_PASSWORD'] ?? 'Admin@123456';
    $nome  = $_ENV['ADMIN_NAME']  ?? 'Administrador';

    // Verifica se já existe
    $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetchColumn()) {
        echo "  ⊘ Admin já existe: $email\n";
        return;
    }

    $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
    $hash = password_hash($senha, PASSWORD_ARGON2ID);

    $stmt = $pdo->prepare("
        INSERT INTO usuarios
            (uuid, nome_completo, username, email, senha_hash, nivel_acesso, ativo, verificado_email, status_verificacao, criado_em)
        VALUES
            (:uuid, :nome, :username, :email, :hash, 'admin_system', TRUE, TRUE, 'verificado', NOW())
    ");
    $stmt->execute([
        ':uuid'     => $uuid,
        ':nome'     => $nome,
        ':username' => 'admin',
        ':email'    => $email,
        ':hash'     => $hash,
    ]);

    echo "  ✔ Admin criado: $email (senha: $senha)\n";
    echo "  ⚠  TROQUE A SENHA EM PRODUÇÃO!\n";
};
