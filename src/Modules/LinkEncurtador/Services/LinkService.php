<?php

declare(strict_types=1);

namespace Src\Modules\LinkEncurtador\Services;

use Src\Modules\LinkEncurtador\Repositories\LinkRepository;
use Src\Modules\LinkEncurtador\Repositories\LinkLimiteRepository;

final class LinkService
{
    public function __construct(
        private readonly LinkRepository       $repository,
        private readonly AliasGenerator       $aliasGenerator,
        private readonly LinkLimiteRepository $limiteRepository,
    ) {}

    // ── Listagem ──────────────────────────────────────────────────────────

    public function list(string $userId, int $page, int $perPage, string $search): array
    {
        return $this->repository->findByUser($userId, $page, $perPage, $search);
    }

    public function get(string $id, string $userId): ?array
    {
        return $this->repository->findById($id, $userId);
    }

    public function stats(string $userId): array
    {
        $base  = $this->repository->statsForUser($userId);
        $limit = $this->limiteRepository->getLimitStats($userId, $base['total']);
        return array_merge($base, ['limite' => $limit]);
    }

    public function analytics(string $id, string $userId, int $days): array
    {
        $link = $this->repository->findById($id, $userId);
        if ($link === null) return [];
        return [
            'link'           => $link,
            'clicks_per_day' => $this->repository->clicksPerDay($id, $days),
        ];
    }

    // ── Limites (admin) ───────────────────────────────────────────────────

    public function getLimitStats(string $userId): array
    {
        $total = $this->repository->statsForUser($userId)['total'];
        return $this->limiteRepository->getLimitStats($userId, $total);
    }

    public function setLimit(string $userId, int $maxLinks): void
    {
        $maxLinks = max(-1, $maxLinks);
        $this->limiteRepository->setLimit($userId, $maxLinks);
    }

    public function setLimitForAll(int $maxLinks): int
    {
        $maxLinks = max(-1, $maxLinks);
        return $this->limiteRepository->setLimitForAll($maxLinks);
    }

    // ── Criação ───────────────────────────────────────────────────────────

    /**
     * @throws \InvalidArgumentException com código 403 se limite atingido
     */
    public function create(string $userId, array $data): array
    {
        $this->assertWithinLimit($userId);

        $url    = $this->validateUrl($data['url'] ?? '');
        $alias  = $this->resolveAlias($data['alias'] ?? '');
        $expiry = $this->resolveExpiry($data['expires_at'] ?? null, $data['expiry_preset'] ?? null);
        $titulo = mb_substr(trim($data['titulo'] ?? ''), 0, 255);

        return $this->repository->create([
            'user_id'    => $userId,
            'alias'      => $alias,
            'url'        => $url,
            'titulo'     => $titulo,
            'expires_at' => $expiry,
        ]);
    }

    // ── Atualização ───────────────────────────────────────────────────────

    /**
     * @throws \InvalidArgumentException
     */
    public function update(string $id, string $userId, array $data): array
    {
        $link = $this->repository->findById($id, $userId);
        if ($link === null) {
            throw new \InvalidArgumentException('Link não encontrado.', 404);
        }

        $url    = $this->validateUrl($data['url'] ?? $link['url']);
        $titulo = mb_substr(trim($data['titulo'] ?? $link['titulo']), 0, 255);
        $expiry = $this->resolveExpiry($data['expires_at'] ?? $link['expires_at'], $data['expiry_preset'] ?? null);
        $ativo  = isset($data['ativo']) ? (bool) $data['ativo'] : $link['ativo'];

        $newAlias = trim($data['alias'] ?? '');
        if ($newAlias === '' || $newAlias === $link['alias']) {
            $alias = $link['alias'];
        } else {
            $err = $this->aliasGenerator->validate($newAlias, $id);
            if ($err !== null) throw new \InvalidArgumentException($err, 422);
            $alias = $newAlias;
        }

        $this->repository->update($id, $userId, [
            'alias'      => $alias,
            'url'        => $url,
            'titulo'     => $titulo,
            'expires_at' => $expiry,
            'ativo'      => $ativo,
        ]);

        return $this->repository->findById($id, $userId) ?? [];
    }

    // ── Exclusão ──────────────────────────────────────────────────────────

    public function delete(string $id, string $userId): bool
    {
        return $this->repository->delete($id, $userId);
    }

    // ── Redirect ──────────────────────────────────────────────────────────

    public function resolveRedirect(string $alias, string $ip, string $referrer, string $userAgent): ?string
    {
        $link = $this->repository->findByAlias($alias);
        if ($link === null) return null;
        if (!$link['ativo']) return null;
        if ($link['expires_at'] !== null && strtotime($link['expires_at']) < time()) return null;

        $this->repository->incrementClicks($alias, $ip, $referrer, $userAgent);
        return $link['url'];
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function assertWithinLimit(string $userId): void
    {
        $total = $this->repository->statsForUser($userId)['total'];
        $stats = $this->limiteRepository->getLimitStats($userId, $total);

        if ($stats['blocked']) {
            throw new \InvalidArgumentException(
                'Sua conta está impedida de criar links. Entre em contato com o suporte em contato@vupi.us.',
                403
            );
        }

        if (!$stats['unlimited'] && ($stats['reached'] ?? false)) {
            throw new \InvalidArgumentException(
                'Você atingiu o limite de ' . $stats['max_links'] . ' link(s). Para aumentar seu limite, entre em contato em contato@vupi.us.',
                403
            );
        }
    }

    private function validateUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') throw new \InvalidArgumentException('URL é obrigatória.', 422);

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('URL inválida.', 422);
        }

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (str_contains($host, 'vupi.us')) {
            throw new \InvalidArgumentException('Não é possível encurtar links do próprio vupi.us.', 422);
        }

        return $url;
    }

    private function resolveAlias(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            return $this->aliasGenerator->generate();
        }
        $err = $this->aliasGenerator->validate($alias);
        if ($err !== null) throw new \InvalidArgumentException($err, 422);
        return $alias;
    }

    private function resolveExpiry(?string $expiresAt, ?string $preset): ?string
    {
        if ($preset !== null && $preset !== '') {
            $map = [
                '1h'  => 3600,    '6h'  => 21600,   '24h' => 86400,
                '3d'  => 259200,  '7d'  => 604800,   '30d' => 2592000,
                '90d' => 7776000, '1y'  => 31536000,
            ];
            if (isset($map[$preset])) {
                return date('Y-m-d H:i:s', time() + $map[$preset]);
            }
        }

        if ($expiresAt === null || $expiresAt === '' || $expiresAt === 'null') {
            return null;
        }

        $ts = strtotime($expiresAt);
        if ($ts === false || $ts <= time()) {
            throw new \InvalidArgumentException('Data de expiração inválida ou no passado.', 422);
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
