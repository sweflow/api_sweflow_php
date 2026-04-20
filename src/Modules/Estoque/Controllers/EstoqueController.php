<?php
namespace Src\Modules\Estoque\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Estoque\Services\EstoqueService;
use Src\Modules\Estoque\Validators\EstoqueValidator;
use Src\Modules\Estoque\Exceptions\EstoqueException;

final class EstoqueController
{
    public function __construct(
        private readonly EstoqueService $service
    ) {}

    // =========================
    // LISTAR
    // =========================
    public function listar(Request $request): Response
    {
        $page    = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 20)));

        return Response::json(
            $this->service->listar($page, $perPage)
        );
    }

    // =========================
    // BUSCAR
    // =========================
    public function buscar(Request $request): Response
    {
        try {
            $item = $this->service->buscar($request->params['id'] ?? '');

            return Response::json(['estoque' => $item]);

        } catch (EstoqueException $e) {
            return Response::json(
                ['error' => $e->getMessage()],
                $e->getStatusCode()
            );
        }
    }

    // =========================
    // CRIAR
    // =========================
    public function criar(Request $request): Response
    {
        $erro = EstoqueValidator::validarCriacao($request->body);

        if ($erro !== null) {
            return Response::json(['error' => $erro], 422);
        }

        try {
            $item = $this->service->criar(
                EstoqueValidator::sanitizar($request->body)
            );

            return Response::json(['estoque' => $item], 201);

        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);

        } catch (EstoqueException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    // =========================
    // ATUALIZAR
    // =========================
    public function atualizar(Request $request): Response
    {
        error_log("[EstoqueController] Body recebido: " . json_encode($request->body));
        error_log("[EstoqueController] Raw body: " . file_get_contents('php://input'));
        
        $erro = EstoqueValidator::validarAtualizacao($request->body);

        if ($erro !== null) {
            return Response::json(['error' => $erro], 422);
        }

        try {
            $sanitized = EstoqueValidator::sanitizar($request->body);
            error_log("[EstoqueController] Dados sanitizados: " . json_encode($sanitized));
            
            $this->service->atualizar(
                $request->params['id'] ?? '',
                $sanitized
            );

            return Response::json(['updated' => true]);

        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);

        } catch (EstoqueException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    // =========================
    // ADICIONAR ESTOQUE
    // =========================
    public function adicionar(Request $request): Response
    {
        $quantidade = (float) ($request->body['quantidade'] ?? 0);

        if ($quantidade <= 0) {
            return Response::json(['error' => 'Quantidade inválida.'], 422);
        }

        try {
            $this->service->adicionar(
                $request->params['id'] ?? '',
                $quantidade
            );

            return Response::json(['updated' => true]);

        } catch (EstoqueException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    // =========================
    // REMOVER ESTOQUE
    // =========================
    public function remover(Request $request): Response
    {
        $quantidade = (float) ($request->body['quantidade'] ?? 0);

        if ($quantidade <= 0) {
            return Response::json(['error' => 'Quantidade inválida.'], 422);
        }

        try {
            $this->service->remover(
                $request->params['id'] ?? '',
                $quantidade
            );

            return Response::json(['updated' => true]);

        } catch (EstoqueException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    // =========================
    // DELETE
    // =========================
    public function deletar(Request $request): Response
    {
        try {
            $this->service->deletar($request->params['id'] ?? '');

            return Response::json(['deleted' => true]);

        } catch (EstoqueException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }
}