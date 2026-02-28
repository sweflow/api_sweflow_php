<?php
require 'vendor/autoload.php';
use Dotenv\Dotenv;
use Firebase\JWT\JWT;

$payload = [
    'sub' => 'user_id',
    'exp' => time() + 3600 // expira em 1 hora
];
// Carrega variáveis do .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$secret = $_ENV['JWT_SECRET'] ?? '';

$jwt = JWT::encode($payload, $secret, 'HS256');
echo $jwt;