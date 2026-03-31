<?php
namespace Src\Kernel\Nucleo;

use Src\Kernel\Http\Response\Response;

class Resposta
{
    public static function json(array $dados, int $codigo = 200): void
    {
        Response::json($dados, $codigo)->Enviar();
    }
}
