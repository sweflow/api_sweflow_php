<?php

declare(strict_types=1);

namespace Src\Modules\LinkEncurtador\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\LinkEncurtador\Services\LinkService;

final class LinkController
{
    public function __construct(
        private readonly LinkService $service,
    ) {}

    // ── GET /api/links ────────────────────────────────────────────────────
    public function listar(Request $request): Response
    {
        $userId  = $this->userId($request);
        $page    = max(1, (int) ($request->query['page']     ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 20)));
        $search  = trim((string) ($request->query['q'] ?? ''));

        return Response::json($this->service->list($userId, $page, $perPage, $search));
    }

    // ── GET /api/links/stats ──────────────────────────────────────────────
    public function stats(Request $request): Response
    {
        return Response::json($this->service->stats($this->userId($request)));
    }

    // ── GET /api/links/my-limit ───────────────────────────────────────────
    public function myLimit(Request $request): Response
    {
        return Response::json($this->service->getLimitStats($this->userId($request)));
    }

    // ── GET /api/links/user-limit/{userId} (admin) ────────────────────────
    public function getUserLimit(Request $request): Response
    {
        $uid = $request->params['userId'] ?? '';
        if ($uid === '') return Response::json(['error' => 'userId obrigatorio.'], 422);
        return Response::json($this->service->getLimitStats($uid));
    }

    // ── PUT /api/links/user-limit/{userId} (admin) ────────────────────────
    public function setUserLimit(Request $request): Response
    {
        $uid      = $request->params['userId'] ?? '';
        $maxLinks = (int) ($request->body['max_links'] ?? -1);
        if ($uid === '') return Response::json(['error' => 'userId obrigatorio.'], 422);
        // Valida: -1 (ilimitado), 0 (bloqueado), ou N >= 1
        if ($maxLinks < -1) return Response::json(['error' => 'max_links invalido. Use -1 (ilimitado), 0 (bloqueado) ou N >= 1.'], 422);
        $this->service->setLimit($uid, $maxLinks);
        return Response::json(['saved' => true, 'user_id' => $uid, 'max_links' => $maxLinks]);
    }

    // ── GET /api/links/{id} ───────────────────────────────────────────────
    public function buscar(Request $request): Response
    {
        $link = $this->service->get($request->params['id'], $this->userId($request));
        if ($link === null) return Response::json(['error' => 'Link não encontrado.'], 404);
        return Response::json(['link' => $link]);
    }

    // ── GET /api/links/{id}/analytics ─────────────────────────────────────
    public function analytics(Request $request): Response
    {
        $days = min(90, max(1, (int) ($request->query['days'] ?? 7)));
        $data = $this->service->analytics($request->params['id'], $this->userId($request), $days);
        if (empty($data)) return Response::json(['error' => 'Link não encontrado.'], 404);
        return Response::json($data);
    }

    // ── POST /api/links ───────────────────────────────────────────────────
    public function criar(Request $request): Response
    {
        try {
            $link = $this->service->create($this->userId($request), $request->body);
            return Response::json(['link' => $link], 201);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getCode() ?: 422);
        }
    }

    // ── PUT /api/links/{id} ───────────────────────────────────────────────
    public function atualizar(Request $request): Response
    {
        try {
            $link = $this->service->update($request->params['id'], $this->userId($request), $request->body);
            return Response::json(['link' => $link]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getCode() ?: 422);
        }
    }

    // ── DELETE /api/links/{id} ────────────────────────────────────────────
    public function deletar(Request $request): Response
    {
        $deleted = $this->service->delete($request->params['id'], $this->userId($request));
        if (!$deleted) return Response::json(['error' => 'Link não encontrado.'], 404);
        return Response::json(['deleted' => true]);
    }

    // ── GET /r/{alias} — redirect público ────────────────────────────────
    public function redirect(Request $request): Response
    {
        $alias     = $request->params['alias'];
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
        $referrer  = $_SERVER['HTTP_REFERER'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $url = $this->service->resolveRedirect($alias, $ip, $referrer, $userAgent);

        if ($url === null) {
            return Response::html(
                '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8">
                <title>Link não encontrado — vupi.us</title>
                <style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#080b14;color:#e8eaf6;}
                .box{text-align:center;padding:40px;}.box h1{font-size:3rem;margin-bottom:8px;}.box p{color:#8892b0;margin-bottom:24px;}
                .box a{color:#818cf8;text-decoration:none;font-weight:700;}</style></head>
                <body><div class="box"><h1>🔗</h1><h2>Link não encontrado</h2>
                <p>Este link não existe, foi desativado ou expirou.</p>
                <a href="https://vupi.us">← Voltar para vupi.us</a></div></body></html>',
                404
            );
        }

        return new Response('', 302, ['Location' => $url]);
    }

    // ── Helper ────────────────────────────────────────────────────────────
    private function userId(Request $request): string
    {
        return $request->attribute('auth_user')->getUuid()->toString();
    }
}
