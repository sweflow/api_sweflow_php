<?php

namespace Src\Modules\Tarefa\Services;

use Src\Modules\Tarefa\Repositories\TarefaRepository;
use Src\Modules\Tarefa\Exceptions\TarefaException;

final class TarefaService
{
    public function __construct(
        private readonly TarefaRepository $repository
    ) {}

    public function listar(int $page = 1, int $perPage = 20): array
    {
        $total = $this->repository->count();
        $items = $this->repository->findPaginated($page, $perPage);
        return ['data' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))];
    }

    public function criar(array $data): array { return $this->repository->create($data); }

    public function buscar(string $id): ?array { return $this->repository->findById($id); }

    public function atualizar(string $id, array $data): void
    {
        if ($this->repository->findById($id) === null) { throw TarefaException::naoEncontrado(); }
        $this->repository->update($id, $data);
    }

    public function deletar(string $id): void
    {
        if ($this->repository->findById($id) === null) { throw TarefaException::naoEncontrado(); }
        $this->repository->delete($id);
    }
}
