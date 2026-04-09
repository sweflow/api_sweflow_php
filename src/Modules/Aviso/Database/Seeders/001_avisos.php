<?php
return function (PDO $pdo): void {
    // Idempotente: só insere se a tabela estiver vazia
    $count = (int) $pdo->query('SELECT COUNT(*) FROM avisos')->fetchColumn();
    if ($count > 0) {
        echo "  ⊘ Avisos já existem, seed ignorado.\n";
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO avisos (titulo, mensagem, tipo) VALUES (:titulo, :mensagem, :tipo)'
    );

    $stmt->execute([':titulo' => 'Bem-vindo!',          ':mensagem' => 'O sistema está no ar.',              ':tipo' => 'sucesso']);
    $stmt->execute([':titulo' => 'Manutenção agendada', ':mensagem' => 'Haverá manutenção no domingo às 2h.', ':tipo' => 'alerta']);

    echo "  ✔ Avisos de exemplo criados.\n";
};
