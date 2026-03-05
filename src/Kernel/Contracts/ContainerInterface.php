<?php

namespace Src\Kernel\Contracts;

interface ContainerInterface
{
    public function bind(string $abstract, callable|object|string $concrete, bool $singleton = false): void;
    public function make(string $abstract);
}
