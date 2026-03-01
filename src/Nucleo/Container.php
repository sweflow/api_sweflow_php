<?php
namespace Src\Nucleo;

use ReflectionClass;

class Container
{
    public function make(string $classe)
    {
        $refClass = new ReflectionClass($classe);
        $construtor = $refClass->getConstructor();
        if (!$construtor || count($construtor->getParameters()) === 0) {
            return new $classe();
        }
        $dependencias = [];
        foreach ($construtor->getParameters() as $param) {
            $tipo = $param->getType();
            if ($tipo && !$tipo->isBuiltin()) {
                $dependencias[] = $this->make($tipo->getName());
            } else {
                throw new \Exception("Não é possível resolver dependência: " . $param->getName());
            }
        }
        return $refClass->newInstanceArgs($dependencias);
    }
}
