<?php
namespace Src\CLI;

use Src\Kernel\Nucleo\CapabilityResolver;

class PluginProviderSetCommand
{
    public function handle(string $capability, string $plugin): void
    {
        $resolver = new CapabilityResolver(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage');
        $resolver->setProvider($capability, $plugin);
        echo "Provider for {$capability} set to {$plugin}\n";
    }
}
