<?php
require 'vendor/autoload.php';
use Dotenv\Dotenv;
use Firebase\JWT\JWT;

// Carrega variáveis do .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$secret = $_ENV['JWT_API_SECRET'] ?? '';
if (!$secret) {
    echo "JWT_API_SECRET não configurado\n";
    exit(1);
}

// Gera token de API com claims completos
$agora = time();
$payload = [
    'sub'        => 'api_service',
    'iat'        => $agora,
    'exp'        => $agora + 3600,
    'jti'        => bin2hex(random_bytes(16)),
    'api_access' => true,
    'tipo'       => 'api',
];

$jwt = JWT::encode($payload, $secret, 'HS256');
echo $jwt;
