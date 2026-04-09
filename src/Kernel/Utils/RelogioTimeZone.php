<?php

namespace Src\Kernel\Utils;

final class RelogioTimeZone
{
    private static ?\DateTimeZone $timeZone = null;

    private function __construct()
    {
    }

    public static function obterTimeZone(): \DateTimeZone
    {
        if (self::$timeZone === null) {
            $timeZoneString = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'UTC';

            try {
                self::$timeZone = new \DateTimeZone($timeZoneString);
            } catch (\Throwable) {
                self::$timeZone = new \DateTimeZone('UTC');
            }
        }

        return self::$timeZone;
    }

    public static function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::obterTimeZone());
    }

    /** Limpa o cache — útil em testes que alteram APP_TIMEZONE. */
    public static function reset(): void
    {
        self::$timeZone = null;
    }
}
