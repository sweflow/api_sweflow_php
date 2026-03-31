<?php
$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAccept: application/json",
        'content' => json_encode(['login' => 'admin@admin.com', 'senha' => 'admin123']),
        'ignore_errors' => true,
    ]
]);
$raw  = file_get_contents('http://localhost:3005/api/auth/login', false, $ctx);
$data = json_decode($raw, true);

if (($data['status'] ?? '') === 'success') {
    echo "✓ Login OK — " . $data['usuario']['email'] . " (" . $data['usuario']['nivel_acesso'] . ")\n";
    foreach ($http_response_header as $h) {
        if (stripos($h, 'set-cookie') !== false) echo "✓ Cookie: " . substr($h, 0, 80) . "...\n";
    }
} else {
    echo "✗ Falhou: " . ($data['message'] ?? $data['error'] ?? $raw) . "\n";
}
