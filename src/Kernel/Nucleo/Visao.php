<?php
namespace Src\Nucleo;

class Visao
{
    public static function renderizar(string $nome, array $dados = []): void
    {
        extract($dados);
        include __DIR__ . '/../Views/' . $nome . '.php';
    }
}
