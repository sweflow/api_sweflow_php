<?php

use PDO;

return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    // Dados de exemplo
    $dados = [
        ['nome' => 'Exemplo 1'],
        ['nome' => 'Exemplo 2'],
        ['nome' => 'Exemplo 3'],
    ];
    
    if ($driver === 'pgsql') {
        // PostgreSQL - usa gen_random_uuid() automaticamente
        $stmt = $pdo->prepare("
            INSERT INTO estoque_estoques (nome) 
            VALUES (:nome)
            ON CONFLICT DO NOTHING
        ");
        
        foreach ($dados as $item) {
            $stmt->execute([':nome' => $item['nome']]);
        }
    } else {
        // MySQL - gera UUID manualmente
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO estoque_estoques (id, nome) 
            VALUES (:id, :nome)
        ");
        
        foreach ($dados as $item) {
            // Gera UUID v4
            $uuid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            
            $stmt->execute([
                ':id' => $uuid,
                ':nome' => $item['nome']
            ]);
        }
    }
};
