<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\Nucleo\CapabilityResolver;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

class CapabilitiesController
{
    private function resolver(): CapabilityResolver
    {
        return new CapabilityResolver(
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage'
        );
    }

    public function index(): Response
    {
        $resolver = $this->resolver();

        // Auto-correção: Valida se os providers ativos ainda existem
        // (só remove se há providers registrados via plugin.json — não remove providers manuais)
        $resolver->validate();

        // Descobre capabilities via plugin.json + capabilities já salvas no registry
        $capabilities = $resolver->getAllCapabilitiesIncludingSaved();

        $items = [];
        foreach ($capabilities as $cap) {
            $items[] = [
                'capability' => $cap,
                'active'     => $resolver->resolve($cap),
                'providers'  => $resolver->listProviders($cap),
            ];
        }

        usort($items, fn($a, $b) => strcmp($a['capability'], $b['capability']));
        return Response::json(['items' => $items]);
    }

    public function set(Request $request): Response
    {
        $body   = $request->body ?? [];
        $cap    = trim((string) ($body['capability'] ?? ''));
        $plugin = trim((string) ($body['plugin'] ?? ''));

        if ($cap === '') {
            return Response::json(['error' => 'Campo capability é obrigatório.'], 400);
        }

        // Sanitiza: apenas letras, números, ponto, hífen e underline
        if (!preg_match('/^[a-zA-Z0-9._\-]{1,100}$/', $cap)) {
            return Response::json(['error' => 'Valor de capability inválido.'], 422);
        }

        $resolver = $this->resolver();

        if ($plugin === '') {
            $resolver->removeProvider($cap);
            return Response::json(['capability' => $cap, 'active' => null]);
        }

        // Sanitiza plugin: apenas letras, números, ponto, hífen e underline
        if (!preg_match('/^[a-zA-Z0-9._\-]{1,100}$/', $plugin)) {
            return Response::json(['error' => 'Valor de plugin inválido.'], 422);
        }

        $resolver->setProvider($cap, $plugin);
        return Response::json(['capability' => $cap, 'active' => $plugin]);
    }
}
