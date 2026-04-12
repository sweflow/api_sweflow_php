<?php

namespace Task\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Task\Services\TaskService;
use Task\Validators\TaskValidator;
use Task\Exceptions\TaskException;

final class TaskController
{
    public function __construct(
        private readonly TaskService $service
    ) {}

    public function listar(Request $request): Response
    {
        $page    = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 20)));
        return Response::json($this->service->listar($page, $perPage));
    }

    public function criar(Request $request): Response
    {
        $erro = TaskValidator::validarCriacao($request->body);
        if ($erro !== null) {
            return Response::json(['error' => $erro], 422);
        }
        try {
            $item = $this->service->criar(TaskValidator::sanitizar($request->body));
            return Response::json(['task' => $item], 201);
        } catch (TaskException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function buscar(Request $request): Response
    {
        $item = $this->service->buscar($request->params['id'] ?? '');
        if ($item === null) {
            return Response::json(['error' => 'Nao encontrado.'], 404);
        }
        return Response::json(['task' => $item]);
    }

    public function atualizar(Request $request): Response
    {
        $erro = TaskValidator::validarAtualizacao($request->body);
        if ($erro !== null) {
            return Response::json(['error' => $erro], 422);
        }
        try {
            $this->service->atualizar($request->params['id'] ?? '', TaskValidator::sanitizar($request->body));
            return Response::json(['updated' => true]);
        } catch (TaskException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function deletar(Request $request): Response
    {
        try {
            $this->service->deletar($request->params['id'] ?? '');
            return Response::json(['deleted' => true]);
        } catch (TaskException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }
}
