<?php

namespace Src\Modules\Estoque\Helpers;

final class EstoqueHelper
{
    public static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    public static function formatarData(string $date): string
    {
        return date('d/m/Y H:i', strtotime($date));
    }

    public static function slug(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = (string) preg_replace('/[\s-]+/', '-', $text);
        return trim($text, '-');
    }
}
