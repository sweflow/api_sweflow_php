<?php
require 'vendor/autoload.php';
use Dotenv\Dotenv;
use Firebase\JWT\JWT;


// Carrega variáveis do .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Sempre gera apenas token de API
$payload = [
    'sub' => 'api_user_id',
    'exp' => time() + 3600,
    'api_access' => true,
    'tipo' => 'api'
];
$secret = $_ENV['JWT_SECRET'] ?? '';
if (!$secret) {
    echo "JWT_SECRET não configurado\n";
    exit(1);
}
$jwt = JWT::encode($payload, $secret, 'HS256');
echo $jwt;