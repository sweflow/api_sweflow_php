<?php

namespace Src\Kernel\Controllers\Concerns;

use Src\Kernel\Http\Response\Response;
use Src\Kernel\View;

/**
 * Trait compartilhado pelos controllers de página do dashboard.
 * Centraliza a lógica de renderização de views HTML e resolução de logo.
 */
trait RendersView
{
    /**
     * Renderiza uma view PHP e retorna Response::html().
     * Captura o output via ob_start/ob_get_clean para compatibilidade com o pipeline.
     */
    /** @param array<string, mixed> $data */
    protected function renderView(string $view, array $data = []): Response
    {
        ob_start();
        View::render($view, $data);
        $html = ob_get_clean();
        return Response::html($html !== false ? $html : '');
    }

    /**
     * Resolve a URL do logo a partir do .env.
     * Retorna null se não configurada ou se for um .ico (não exibível como <img>).
     */
    protected function resolveLogoUrl(): ?string
    {
        $logoUrl = $_ENV['APP_LOGO_URL'] ?? (getenv('APP_LOGO_URL') ?: null);
        if ($logoUrl === null) {
            return null;
        }
        $ext = strtolower(pathinfo(parse_url((string) $logoUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        return ($ext === 'ico' || $ext === '') ? null : (string) $logoUrl;
    }
}
