<?php
namespace Src\Modules\Estoque\Services;

use Src\Modules\Estoque\Entities\Estoque;
use Src\Modules\Estoque\Repositories\EstoqueRepository;
use Src\Modules\Estoque\Exceptions\EstoqueException;

final class EstoqueService
{
    public function __construct(
        private readonly EstoqueRepository $repository
    ) {}

    // =========================
    // LISTAGEM
    // =========================
    public function listar(int $page = 1, int $perPage = 20): array
    {
        $total = $this->repository->count();
        $items = $this->repository->findPaginated($page, $perPage);

        return [
            'data' => array_map(fn (Estoque $e) => $e->toArray(), $items),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    // =========================
    // BUSCAR
    // =========================
    public function buscar(string $id): array
    {
        $estoque = $this->repository->findById($id);

        if ($estoque === null) {
            throw EstoqueException::naoEncontrado();
        }

        return $estoque->toArray();
    }

    // =========================
    // CRIAR
    // =========================
    public function criar(array $data): array
    {
        $produto = trim($data['produto'] ?? '');
        $quantidade = (float) ($data['quantidade'] ?? 0);

        if ($produto === '') {
            throw new \InvalidArgumentException('Produto é obrigatório.');
        }

        if ($quantidade < 0) {
            throw new \InvalidArgumentException('Quantidade não pode ser negativa.');
        }

        $id = $this->generateId();

        $estoque = new Estoque(
            id: $id,
            produto: $produto,
            quantidade: $quantidade,
            criadoEm: new \DateTimeImmutable()
        );

        $this->repository->create($estoque);

        return $estoque->toArray();
    }

    // =========================
    // ATUALIZAR PRODUTO
    // =========================
    public function atualizar(string $id, array $data): void
    {
        error_log("[EstoqueService] atualizar() chamado - ID: {$id}, Data: " . json_encode($data));
        
        $estoque = $this->repository->findById($id);

        if ($estoque === null) {
            throw EstoqueException::naoEncontrado();
        }

        error_log("[EstoqueService] Estoque encontrado - Quantidade atual: " . $estoque->getQuantidade());

        $reflection = new \ReflectionClass($estoque);

        // Atualiza produto se fornecido
        if (isset($data['produto'])) {
            $produto = trim($data['produto']);
            if ($produto === '') {
                throw new \InvalidArgumentException('Produto inválido.');
            }

            $prop = $reflection->getProperty('produto');
            $prop->setAccessible(true);
            $prop->setValue($estoque, $produto);
            error_log("[EstoqueService] Produto atualizado para: {$produto}");
        }

        // Atualiza quantidade se fornecida
        if (isset($data['quantidade'])) {
            $quantidade = (float) $data['quantidade'];
            error_log("[EstoqueService] Atualizando quantidade para: {$quantidade}");
            
            if ($quantidade < 0) {
                throw new \InvalidArgumentException('Quantidade não pode ser negativa.');
            }

            $prop = $reflection->getProperty('quantidade');
            $prop->setAccessible(true);
            $prop->setValue($estoque, $quantidade);
            error_log("[EstoqueService] Quantidade atualizada - Nova quantidade: " . $estoque->getQuantidade());
        }

        error_log("[EstoqueService] Salvando no banco...");
        $this->repository->save($estoque);
        error_log("[EstoqueService] Salvo com sucesso!");
    }

    // =========================
    // MOVIMENTAÇÃO (CORE DO DOMÍNIO)
    // =========================
    public function adicionar(string $id, float $quantidade): void
    {
        $estoque = $this->getOrFail($id);

        $estoque->adicionar($quantidade);

        $this->repository->save($estoque);
    }

    public function remover(string $id, float $quantidade): void
    {
        $estoque = $this->getOrFail($id);

        $estoque->remover($quantidade);

        $this->repository->save($estoque);
    }

    // =========================
    // DELETE
    // =========================
    public function deletar(string $id): void
    {
        $this->getOrFail($id);

        $this->repository->delete($id);
    }

    // =========================
    // HELPERS
    // =========================
    private function getOrFail(string $id): Estoque
    {
        $estoque = $this->repository->findById($id);

        if ($estoque === null) {
            throw EstoqueException::naoEncontrado();
        }

        return $estoque;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16)); // compatível com MySQL
    }
}