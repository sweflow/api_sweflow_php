<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Nucleo\LeitorModulos;

class StatusController
{
    public function __construct(
        private LeitorModulos $modulos
    ) {}

    public function modules(Request $request): Response
    {
        return Response::json($this->modulos->lerCompleto());
    }

    public function toggle(Request $request): Response
    {
        $input  = $request->body ?? [];
        $module = trim((string) ($input['module'] ?? ''));

        if ($module === '') {
            return Response::json(['error' => 'Module name required.'], 400);
        }

        $newState = $this->modulos->alternar($module);

        return Response::json(['module' => $module, 'enabled' => $newState]);
    }
}
