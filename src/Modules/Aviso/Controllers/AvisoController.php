<?php

namespace Src\Modules\Aviso\Controllers;

use DomainException;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Database\ModuleConnectionResolver;
use Src\Modules\Aviso\Entities\Aviso;
use Src\Modules\Aviso\Repositories\AvisoRepository;

class AvisoController
{
    // O Container injeta o PDO automaticamente via ModuleConnectionResolver.
    // Não precisamos de binding manual — o Container resolve PDO pelo tipo.
    // Usamos lazy init para garantir que a conexão só é aberta quando necessário.
    private ?AvisoRepository $repo = null;

    private function repo(): AvisoRepository
    {
        if ($this->repo === null) {
            $pdo = ModuleConnectionResolver::forModule('Aviso');
            $this->repo = new AvisoRepository($pdo);
        }
        return $this->repo;
    }

    // ── Público ───────────────────────────────────────────────────────────

    /** GET /api/avisos — lista avisos ativos (público, sem autenticação) */
    public function listar(Request $request): Response
    {
        $avisos = $this->repo()->listarAtivos();
        return Response::json([
            'status'  => 'success',
            'avisos'  => array_map([$this, 'serial'], $avisos),
        ]);
    }

    /** GET /api/avisos/{id} — busca aviso por ID (público) */
    public function buscar(Request $request, string $id): Response
    {
        $aviso = $this->repo()->buscarPorId((int) $id);
        if (!$aviso || !$aviso->isAtivo()) {
            return Response::json(['status' => 'error', 'message' => 'Aviso não encontrado.'], 404);
        }
        return Response::json(['status' => 'success', 'aviso' => $this->serial($aviso)]);
    }

    // ── Admin ─────────────────────────────────────────────────────────────

    /** GET /api/admin/avisos — lista todos (admin) */
    public function listarTodos(Request $request): Response
    {
        $avisos = $this->repo()->listarTodos();
        return Response::json([
            'status' => 'success',
            'avisos' => array_map([$this, 'serial'], $avisos),
        ]);
    }

    /** POST /api/admin/avisos — cria aviso (admin) */
    public function criar(Request $request): Response
    {
        try {
            $b = $request->body ?? [];
            $aviso = Aviso::criar(
                (string) ($b['titulo']   ?? ''),
                (string) ($b['mensagem'] ?? ''),
                (string) ($b['tipo']     ?? 'info')
            );
            $aviso = $this->repo()->salvar($aviso);
            return Response::json(['status' => 'success', 'aviso' => $this->serial($aviso)], 201);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /** PUT /api/admin/avisos/{id} — atualiza aviso (admin) */
    public function atualizar(Request $request, string $id): Response
    {
        try {
            $aviso = $this->repo()->buscarPorId((int) $id);
            if (!$aviso) {
                return Response::json(['status' => 'error', 'message' => 'Aviso não encontrado.'], 404);
            }
            $b = $request->body ?? [];
            $aviso->atualizar(
                (string) ($b['titulo']   ?? $aviso->getTitulo()),
                (string) ($b['mensagem'] ?? $aviso->getMensagem()),
                (string) ($b['tipo']     ?? $aviso->getTipo())
            );
            if (isset($b['ativo'])) {
                $b['ativo'] ? $aviso->ativar() : $aviso->desativar();
            }
            $aviso = $this->repo()->salvar($aviso);
            return Response::json(['status' => 'success', 'aviso' => $this->serial($aviso)]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /** DELETE /api/admin/avisos/{id} — remove aviso (admin) */
    public function deletar(Request $request, string $id): Response
    {
        $aviso = $this->repo()->buscarPorId((int) $id);
        if (!$aviso) {
            return Response::json(['status' => 'error', 'message' => 'Aviso não encontrado.'], 404);
        }
        $this->repo()->deletar((int) $id);
        return Response::json(['status' => 'success', 'message' => 'Aviso removido.']);
    }

    // ── Serialização ──────────────────────────────────────────────────────

    private function serial(Aviso $a): array
    {
        return [
            'id'        => $a->getId(),
            'titulo'    => $a->getTitulo(),
            'mensagem'  => $a->getMensagem(),
            'tipo'      => $a->getTipo(),
            'ativo'     => $a->isAtivo(),
            'criado_em' => $a->getCriadoEm()?->format('Y-m-d\TH:i:sP'),
        ];
    }
}
