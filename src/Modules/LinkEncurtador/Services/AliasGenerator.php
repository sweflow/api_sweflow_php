<?php

declare(strict_types=1);

namespace Src\Modules\LinkEncurtador\Services;

use Src\Modules\LinkEncurtador\Repositories\LinkRepository;

/**
 * Gera aliases únicos e extremamente curtos para os links.
 * Usa base62 (a-z A-Z 0-9) para máxima compactação.
 */
final class AliasGenerator
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const MIN_LEN  = 4;
    private const MAX_LEN  = 8;

    // Aliases reservados que não podem ser usados
    private const RESERVED = [
        'api', 'doc', 'docs', 'admin', 'login', 'logout', 'register',
        'dashboard', 'perfil', 'links', 'static', 'assets', 'img',
        'css', 'js', 'favicon', 'robots', 'sitemap', 'health',
    ];

    public function __construct(private readonly LinkRepository $repository) {}

    /**
     * Gera um alias único de 4 caracteres (aumenta se houver colisão).
     */
    public function generate(): string
    {
        for ($len = self::MIN_LEN; $len <= self::MAX_LEN; $len++) {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $alias = $this->random($len);
                if (!$this->repository->aliasExists($alias) && !$this->isReserved($alias)) {
                    return $alias;
                }
            }
        }
        // Fallback: timestamp base62
        return $this->toBase62((int) (microtime(true) * 1000));
    }

    /**
     * Valida um alias personalizado fornecido pelo usuário.
     */
    public function validate(string $alias, ?string $excludeId = null): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $alias)) {
            return 'Alias deve ter entre 2 e 32 caracteres (letras, números, _ e -).';
        }
        if ($this->isReserved(strtolower($alias))) {
            return "Alias '{$alias}' é reservado pelo sistema.";
        }
        if ($this->repository->aliasExists($alias, $excludeId)) {
            return "Alias '{$alias}' já está em uso.";
        }
        return null;
    }

    private function random(int $len): string
    {
        $result = '';
        $max    = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $len; $i++) {
            $result .= self::ALPHABET[random_int(0, $max)];
        }
        return $result;
    }

    private function toBase62(int $num): string
    {
        $base   = strlen(self::ALPHABET);
        $result = '';
        while ($num > 0) {
            $result = self::ALPHABET[$num % $base] . $result;
            $num    = intdiv($num, $base);
        }
        return $result ?: '0';
    }

    private function isReserved(string $alias): bool
    {
        return in_array(strtolower($alias), self::RESERVED, true);
    }
}
