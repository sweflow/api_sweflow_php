<?php
namespace Src\Nucleo;

use ReflectionClass;
use ReflectionNamedType;
use Src\Contracts\ContainerInterface;

class Container implements ContainerInterface
{
    private array $bindings = [];
    private array $instances = [];

    public function bind(string $abstract, callable|object|string $concrete, bool $singleton = false): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'singleton' => $singleton,
        ];
    }

    public function make(string $abstract)
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $definition = $this->bindings[$abstract]['concrete'];
            $object = $this->resolveConcrete($definition, $abstract);
            if ($this->bindings[$abstract]['singleton']) {
                $this->instances[$abstract] = $object;
            }
            return $object;
        }

        $candidate = $this->conventionClassForInterface($abstract);
        if ($candidate !== null) {
            return $this->autoResolve($candidate);
        }

        if (!class_exists($abstract)) {
            throw new \RuntimeException("Não há binding ou classe para {$abstract}");
        }

        return $this->autoResolve($abstract);
    }

    /**
     * Tries to resolve FooInterface -> Foo in the same namespace when no binding exists.
     */
    private function conventionClassForInterface(string $abstract): ?string
    {
        if (!str_ends_with($abstract, 'Interface')) {
            return null;
        }

        $pos = strrpos($abstract, '\\');
        $ns = $pos !== false ? substr($abstract, 0, $pos + 1) : '';
        $base = $pos !== false ? substr($abstract, $pos + 1) : $abstract;
        $candidate = $ns . substr($base, 0, -9); // drop 'Interface'

        return class_exists($candidate) ? $candidate : null;
    }

    private function resolveConcrete(callable|object|string $definition, string $abstract)
    {
        if (is_object($definition) && !($definition instanceof \Closure)) {
            return $definition;
        }

        if (is_string($definition)) {
            return $this->autoResolve($definition);
        }

        return $definition($this);
    }

    private function autoResolve(string $class)
    {
        $refClass = new ReflectionClass($class);
        $constructor = $refClass->getConstructor();
        if (!$constructor || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->make($type->getName());
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
                continue;
            }
            throw new \RuntimeException("Não é possível resolver dependência: {$param->getName()} em {$class}");
        }

        return $refClass->newInstanceArgs($dependencies);
    }
}
