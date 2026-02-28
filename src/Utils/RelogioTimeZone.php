<?php

namespace src\Utils;

final class RelogioTimeZone
{
    private static ?\DateTimeZone $timeZone = null;

    private function __construct()
    {
    }

    public static function obterTimeZone(): \DateTimeZone
    {
        if (self::$timeZone === null) {
            $timeZoneString = $_ENV['APP_TIMEZONE'] ?? 'UTC';

            try {
                self::$timeZone = new \DateTimeZone($timeZoneString);
            } catch (\Exception $e) {
                self::$timeZone = new \DateTimeZone('UTC');
            }
        }

        return self::$timeZone;
    }

    public static function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::obterTimeZone());
    }
}