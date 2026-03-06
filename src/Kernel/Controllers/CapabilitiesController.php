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
        $mapFile = $storage . DIRECTORY_SEPARATOR . 'capabilities_registry.json';
        $map = is_file($mapFile) ? (json_decode(@file_get_contents($mapFile), true) ?: []) : [];

        // Descobrir capabilities oferecidas por plugins e unificar com as do registry
        $capabilities = array_fill_keys(array_keys($map), true);
        foreach ([$storage . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'plugins', $storage . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'sweflow'] as $root) {
            $root = realpath($root) ?: $root;
            if (!is_dir($root)) continue;
            foreach (scandir($root) as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $pj = $root . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'plugin.json';
                if (!is_file($pj)) continue;
                $data = json_decode(@file_get_contents($pj), true) ?: [];
                $provides = $data['provides'] ?? [];
                if (is_array($provides)) {
                    foreach ($provides as $cap) {
                        $capabilities[$cap] = true;
                    }
                }
            }
        }

        $items = [];
        foreach (array_keys($capabilities) as $cap) {
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
        if (!$cap || !$plugin) {
            return Response::json(['error' => 'Campos capability e plugin são obrigatórios'], 400);
        }
        $resolver = new CapabilityResolver(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage');
        $resolver->setProvider($cap, $plugin);
        return Response::json(['capability' => $cap, 'active' => $plugin]);
    }
}
