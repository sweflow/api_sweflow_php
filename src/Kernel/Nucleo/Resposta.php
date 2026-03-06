<?php
namespace Src\Kernel\Nucleo;

class Resposta
{
    public static function json(array $dados, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json');
        echo json_encode($dados);
    }
}
