<?php

namespace src\Configs;

use src\Exceptions\EnvFileNotFoundException;

final class EnvConfig
{
    private const ENV_FILE = __DIR__ . '/../../.env';

    private function __construct()
    {
    }

    public static function carregar(): void
    {
        if (!file_exists(self::ENV_FILE))
        {
            throw new EnvFileNotFoundException(self::ENV_FILE);
        }

        $lines = file(self::ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line)
        {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            $line = trim($line);

            if($line === '' || str_starts_with($line, '#'))
            {
                continue;
            }

            if (!str_contains($line, '='))
            {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            $value = trim($value, "\"'");

            if(getenv($key) === false)
            {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

    }
}

