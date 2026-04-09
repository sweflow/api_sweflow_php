<?php
namespace Src\CLI;

use Src\Kernel\Nucleo\CapabilityResolver;

class CapabilityListCommand
{
    public function handle(?string $filterCapability = null): void
    {
        $resolver = new CapabilityResolver(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage');
        if ($filterCapability) {
            $active = $resolver->resolve($filterCapability);
            $providers = $resolver->listProviders($filterCapability);
            echo "Capability: {$filterCapability}\n";
            echo "Providers:\n";
            foreach ($providers as $p) {
                echo " - {$p}\n";
            }
            echo "Active:\n";
            echo " - " . ($active ?? 'none') . "\n";
            return;
        }
        // List all capabilities present in registry
        $mapFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'capabilities_registry.json';
        $map = [];
        if (is_file($mapFile) && is_readable($mapFile)) {
            $raw = file_get_contents($mapFile);
            $map = $raw !== false ? (json_decode($raw, true) ?: []) : [];
        }
        if (!$map) {
            echo "Nenhuma capability configurada\n";
            return;
        }
        foreach ($map as $cap => $plugin) {
            $providers = $resolver->listProviders($cap);
            echo "Capability: {$cap}\n";
            echo "Providers:\n";
            foreach ($providers as $p) {
                echo " - {$p}\n";
            }
            echo "Active:\n";
            echo " - " . ($plugin ?? 'none') . "\n\n";
        }
    }
}
