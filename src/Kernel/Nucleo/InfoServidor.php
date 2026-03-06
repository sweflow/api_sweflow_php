<?php
namespace Src\Kernel\Nucleo;

class InfoServidor
{
    public static function obter(): array
    {
        return [
            'host' => $_SERVER['SERVER_NAME'] ?? 'localhost',
            'porta' => $_SERVER['SERVER_PORT'] ?? '80',
            'ambiente' => getenv('APP_ENV') ?: 'local',
            'debug' => (getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === true) ? 'true' : 'false',
        ];
    }
}
