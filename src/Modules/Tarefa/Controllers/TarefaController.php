<?php

namespace Src\Modules\Tarefa\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Tarefa\Repositories\TarefaRepository;

final class TarefaController
{
    public function __construct(
        private readonly TarefaRepository $repository
    ) {}

    public function listar(Request $request): Response
    {
        $userId = $request->attribute('auth_user')->getUuid()->toString();
        return Response::json(['tarefas' => $this->repository->findByUser($userId)]);
    }

    public function criar(Request $request): Response
    {
        $userId = $request->attribute('auth_user')->getUuid()->toString();
        $titulo = trim($request->body['titulo'] ?? '');

        if ($titulo === '') {
            return Response::json(['error' => 'O campo titulo e obrigatorio.'], 422);
        }

        $tarefa = $this->repository->create(['titulo' => $titulo, 'user_id' => $userId]);
        return Response::json(['tarefa' => $tarefa], 201);
    }

    public function buscar(Request $request): Response
    {
        $userId = $request->attribute('auth_user')->getUuid()->toString();
        $tarefa = $this->repository->findById($request->params['id'], $userId);

        if ($tarefa === null) {
            return Response::json(['error' => 'Tarefa nao encontrada.'], 404);
        }
        return Response::json(['tarefa' => $tarefa]);
    }

    public function atualizar(Request $request): Response
    {
        $userId = $request->attribute('auth_user')->getUuid()->toString();
        $id = $request->params['id'];

        if ($this->repository->findById($id, $userId) === null) {
            return Response::json(['error' => 'Tarefa nao encontrada.'], 404);
        }

        $this->repository->update($id, $userId, [
            'titulo'    => trim($request->body['titulo'] ?? ''),
            'concluida' => (bool) ($request->body['concluida'] ?? false),
        ]);
        return Response::json(['updated' => true]);
    }

    public function deletar(Request $request): Response
    {
        $userId = $request->attribute('auth_user')->getUuid()->toString();
        $id = $request->params['id'];

        if ($this->repository->findById($id, $userId) === null) {
            return Response::json(['error' => 'Tarefa nao encontrada.'], 404);
        }

        $this->repository->delete($id, $userId);
        return Response::json(['deleted' => true]);
    }
}