<?php

namespace Src\Modules\Documentacao\Controllers;

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

    public function asset(): Response
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

        // Extrai o caminho após /doc/
        $relative = preg_replace('#^/doc/#', '', $requestUri);

        // Proteção contra path traversal — normaliza sem depender de realpath
        // (realpath falha silenciosamente se www-data não tiver permissão de traversal)
        $relative = str_replace(['../', '..\\', "\0"], '', $relative);
        $relative = ltrim($relative, '/');

        $targetPath = $this->docRoot . '/' . $relative;

        // Verifica containment sem realpath: normaliza manualmente
        $normalizedDoc    = rtrim(str_replace('\\', '/', $this->docRoot), '/');
        $normalizedTarget = str_replace('\\', '/', $targetPath);

        // Remove segmentos .. após normalização
        $parts  = explode('/', $normalizedTarget);
        $stack  = [];
        foreach ($parts as $part) {
            if ($part === '..') { array_pop($stack); }
            elseif ($part !== '.' && $part !== '') { $stack[] = $part; }
        }
        $normalizedTarget = '/' . implode('/', $stack);

        if (!str_starts_with($normalizedTarget, $normalizedDoc . '/') || !is_file($normalizedTarget)) {
            return Response::html('', 404);
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
            return Response::html('', 403);
        }

        $content = @file_get_contents($normalizedTarget);
        if ($content === false) {
            return Response::html('', 500);
        }

        return new Response($content, 200, [
            'Content-Type'           => $mimeMap[$ext],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'public, max-age=86400',
        ]);
    }
}
