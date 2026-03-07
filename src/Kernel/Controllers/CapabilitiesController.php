<?php
namespace Src\Kernel\Controllers;

use Src\Kernel\Nucleo\CapabilityResolver;
use Src\Kernel\Http\Response\Response;

class CapabilitiesController
{
    public function index(): Response
    {
        $storage = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage';
        $resolver = new CapabilityResolver($storage);
        
        // 1. Auto-correção: Valida se os providers ativos ainda existem
        $resolver->validate();

        // 2. Descobrir todas as capabilities disponíveis no sistema (plugins/vendor/src)
        $capabilities = $resolver->getAllCapabilities();

        // 3. Montar resposta
        $items = [];
        foreach ($capabilities as $cap) {
            $items[] = [
                'capability' => $cap,
                'active' => $resolver->resolve($cap),
                'providers' => $resolver->listProviders($cap),
            ];
        }
        
        usort($items, fn($a, $b) => strcmp($a['capability'], $b['capability']));
        return Response::json(['items' => $items]);
    }

    public function set($request): Response
    {
        $body = $request->body ?? [];
        $cap = $body['capability'] ?? null;
        $plugin = $body['plugin'] ?? null;
        
        if (!$cap) {
            return Response::json(['error' => 'Campo capability é obrigatório'], 400);
        }
        
        $resolver = new CapabilityResolver(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage');
        
        // Se plugin for vazio, remove o provider (limpa)
        if (empty($plugin)) {
            $resolver->removeProvider($cap);
            return Response::json(['capability' => $cap, 'active' => null]);
        }

        $resolver->setProvider($cap, $plugin);
        return Response::json(['capability' => $cap, 'active' => $plugin]);
    }
}
