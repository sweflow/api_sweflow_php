<?php
$_ENV['JWT_SECRET'] = '367285dc1fb194516d55c192a1139aa567759a523279e6b0fa93f8e9b9bf8784';
$_ENV['JWT_API_SECRET'] = '94125af6c46a2fa3e651b3e4900eac247ca14ce62ec534db356e7e0c705a595a';
require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Ramsey\Uuid\Uuid;
$now = time();
$payload = [
    'sub' => Uuid::uuid4()->toString(),
    'email' => 'test@test.com',
    'username' => 'testadmin',
    'nivel_acesso' => 'admin_system',
    'iat' => $now,
    'exp' => $now + 3600,
    'iss' => 'https://api.typper.shop',
    'aud' => 'https://api.typper.shop',
    'tipo' => 'user',
    'jti' => Uuid::uuid4()->toString(),
];
$token = JWT::encode($payload, $_ENV['JWT_API_SECRET'], 'HS256');
echo $token . PHP_EOL;
