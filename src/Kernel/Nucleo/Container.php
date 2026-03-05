<?php
namespace Src\Kernel\Nucleo;

use ReflectionClass;
use ReflectionNamedType;
use Src\Kernel\Contracts\ContainerInterface;

class Container implements ContainerInterface
{
    private array $bindings = [];
    private array $instances = [];
    private array $buildStack = [];
    private array $reflectionCache = [];

    public function bind(string $abstract, callable|object|string $concrete, bool $singleton = false): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'singleton' => $singleton,
        ];
    }

    public function make(string $abstract)
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        // Tenta resolver binding explícito
        if (isset($this->bindings[$abstract])) {
            $definition = $this->bindings[$abstract]['concrete'];
            $object = $this->resolveConcrete($definition, $abstract);
            if ($this->bindings[$abstract]['singleton']) {
                $this->instances[$abstract] = $object;
            }
            return $object;
        }

        // Tenta resolver por classe concreta existente
        if (class_exists($abstract)) {
            return $this->build($abstract);
        }

        // Tenta convenção de interface (Interface -> Class)
        $candidate = $this->conventionClassForInterface($abstract);
        if ($candidate && class_exists($candidate)) {
            return $this->build($candidate);
        }

        throw new \RuntimeException("Não há binding ou classe para {$abstract}");
    }

    private function build(string $concrete)
    {
        if (isset($this->buildStack[$concrete])) {
            throw new \RuntimeException("Dependência Circular detectada: " . implode(' -> ', array_keys($this->buildStack)) . " -> " . $concrete);
        }

        $this->buildStack[$concrete] = true;

        try {
            // Cache de Reflection para evitar overhead em múltiplas instâncias
            if (isset($this->reflectionCache[$concrete])) {
                $refClass = $this->reflectionCache[$concrete];
            } else {
                $refClass = new ReflectionClass($concrete);
                $this->reflectionCache[$concrete] = $refClass;
            }

            if (!$refClass->isInstantiable()) {
                 throw new \RuntimeException("Classe {$concrete} não é instanciável.");
            }

            $constructor = $refClass->getConstructor();
            if (!$constructor || $constructor->getNumberOfParameters() === 0) {
                return new $concrete();
            }

            $dependencies = $this->resolveDependencies($constructor->getParameters(), $concrete);

            return $refClass->newInstanceArgs($dependencies);
        } finally {
            unset($this->buildStack[$concrete]);
        }
    }

    private function resolveDependencies(array $parameters, string $class): array
    {
        $dependencies = [];
        foreach ($parameters as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencyName = $type->getName();
                
                // Se a classe existe, tentamos instanciar.
                // Se falhar (ex: erro no construtor), a exceção DEVE subir.
                // Se a classe NÃO existe, e é nullable, retornamos null.
                
                try {
                    $dependencies[] = $this->make($dependencyName);
                } catch (\Throwable $e) {
                    // Só engolimos o erro se for "Classe não encontrada" E o parâmetro for nullable.
                    // Erros de runtime (ex: erro de sintaxe, db connection fail) devem explodir.
                    
                    $isMissingClass = str_contains($e->getMessage(), "Não há binding ou classe para");
                    // Ou se for um ReflectionException de classe inexistente vindo do build
                    $isReflectionError = $e instanceof \ReflectionException;
                    
                    if (($isMissingClass || $isReflectionError) && $param->allowsNull()) {
                         // Se tem valor default (ex: = null), usa ele. Senão usa null.
                         if ($param->isDefaultValueAvailable()) {
                             $dependencies[] = $param->getDefaultValue();
                         } else {
                             $dependencies[] = null;
                         }
                    } else {
                         throw $e;
                    }
                }
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
                continue;
            }
            throw new \RuntimeException("Não é possível resolver dependência: {$param->getName()} em {$class}");
        }
        return $dependencies;
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
            return $this->build($definition);
        }

        return $definition($this);
    }
}
