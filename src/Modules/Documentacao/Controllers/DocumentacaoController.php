<?php

namespace Src\Modules\Documentacao\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class DocumentacaoController
{
    private string $docRoot;

    public function __construct()
    {
        $this->docRoot = dirname(__DIR__, 4) . '/Documentacao';
    }

    public function index(): Response
    {
        $path = $this->docRoot . '/index.html';

        if (!is_file($path)) {
            return Response::html('<h1>Documentação não encontrada</h1>', 404);
        }

        $html = (string) file_get_contents($path);

        // Reescreve caminhos relativos de assets para /doc/assets/...
        $html = str_replace('href="assets/', 'href="/doc/assets/', $html);
        $html = str_replace('src="assets/', 'src="/doc/assets/', $html);

        return Response::html($html);
    }

    public function assetCss(Request $request): Response
    {
        $file = $request->param('file') ?? '';
        // Remove query string parameters (e.g., ?v=3)
        $file = preg_replace('/\?.*$/', '', $file);
        return $this->serveAsset('css/' . $file);
    }

    public function assetJs(Request $request): Response
    {
        $file = $request->param('file') ?? '';
        // Remove query string parameters
        $file = preg_replace('/\?.*$/', '', $file);
        return $this->serveAsset('js/' . $file);
    }

    public function assetImg(Request $request): Response
    {
        $file = $request->param('file') ?? '';
        // Remove query string parameters
        $file = preg_replace('/\?.*$/', '', $file);
        return $this->serveAsset('imgs/' . $file);
    }

    private function serveAsset(string $relativePath): Response
    {
        // Proteção contra path traversal
        $relativePath = str_replace(['../', '..\\', "\0"], '', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        if (empty($relativePath)) {
            return Response::html('Asset path required', 400);
        }

        $targetPath = $this->docRoot . '/assets/' . $relativePath;

        // Normaliza caminhos para comparação (Windows/Unix)
        $normalizedDoc    = rtrim(str_replace('\\', '/', realpath($this->docRoot) ?: $this->docRoot), '/');
        $normalizedTarget = str_replace('\\', '/', realpath($targetPath) ?: $targetPath);

        // Verifica se o arquivo existe e está dentro do diretório permitido
        if (!is_file($normalizedTarget) || !str_starts_with($normalizedTarget, $normalizedDoc . '/')) {
            return Response::html('Asset not found: ' . htmlspecialchars($relativePath), 404);
        }

        $ext = strtolower(pathinfo($normalizedTarget, PATHINFO_EXTENSION));
        $mimeMap = [
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'ttf'   => 'font/ttf',
            'webp'  => 'image/webp',
        ];

        if (!isset($mimeMap[$ext])) {
            return Response::html('Forbidden file type', 403);
        }

        $content = @file_get_contents($normalizedTarget);
        if ($content === false) {
            return Response::html('Error reading file', 500);
        }

        return new Response($content, 200, [
            'Content-Type'           => $mimeMap[$ext],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'public, max-age=86400',
        ]);
    }
}
