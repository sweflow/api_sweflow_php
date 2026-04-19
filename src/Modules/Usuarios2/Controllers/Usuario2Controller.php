<?php

namespace Src\Modules\Usuarios2\Controllers;

use PDO;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Modules\Usuarios2\Services\Usuario2Service;
use Src\Modules\Usuarios2\Exceptions\Usuarios2Exception;

/**
 * Controller: Usuario2Controller
 * 
 * CRUD de usuários
 */
class Usuario2Controller
{
    private Usuario2Service $usuarioService;
    
    public function __construct(PDO $pdo)
    {
        $this->usuarioService = new Usuario2Service($pdo);
    }
    
    /**
     * GET /api/usuarios2
     * Lista usuários com paginação e filtros
     */
    public function listar(Request $request): Response
    {
        try {
            $filtros = [
                'ativo' => $request->query['ativo'] ?? null,
                'bloqueado' => $request->query['bloqueado'] ?? null,
                'nivel_acesso' => $request->query['nivel_acesso'] ?? null,
                'email_verificado' => $request->query['email_verificado'] ?? null,
                'busca' => $request->query['busca'] ?? null,
            ];
            
            $pagina = (int) ($request->query['pagina'] ?? 1);
            $porPagina = (int) ($request->query['por_pagina'] ?? 20);
            
            $resultado = $this->usuarioService->listar($filtros, $pagina, $porPagina);
            
            return Response::json([
                'success' => true,
                'data' => array_map(fn($u) => $u->toArray(), $resultado['dados']),
                'pagination' => [
                    'total' => $resultado['total'],
                    'pagina' => $resultado['pagina'],
                    'por_pagina' => $resultado['por_pagina'],
                    'total_paginas' => $resultado['total_paginas'],
                ],
            ], 200);
            
        } catch (\Exception $e) {
            return Response::json(['error' => 'Erro interno do servidor'], 500);
        }
    }
    
    /**
     * GET /api/usuarios2/{uuid}
     * Busca usuário por UUID
     */
    public function buscar(Request $request): Response
    {
        try {
            $uuid = $request->params['uuid'] ?? null;
            
            if (!$uuid) {
                return Response::json(['error' => 'UUID é obrigatório'], 422);
            }
            
            $usuario = $this->usuarioService->buscarPorUuid($uuid);
            
            return Response::json([
                'success' => true,
                'data' => $usuario->toArray(),
            ], 200);
            
        } catch (Usuarios2Exception $e) {
            return Response::json(['error' => $e->getMessage()], $e->getHttpCode());
        } catch (\Exception $e) {
            return Response::json(['error' => 'Erro interno do servidor'], 500);
        }
    }
    
    /**
     * POST /api/usuarios2
     * Cria um novo usuário
     */
    public function criar(Request $request): Response
    {
        try {
            $body = $request->body ?? [];
            $criadoPor = $request->usuarioUuid ?? null;
            
            $usuario = $this->usuarioService->criar($body, $criadoPor);
            
            return Response::json([
                'success' => true,
                'message' => 'Usuário criado com sucesso',
                'data' => $usuario->toArray(),
            ], 201);
            
        } catch (Usuarios2Exception $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'errors' => method_exists($e, 'getErros') ? $e->getErros() : null,
            ], $e->getHttpCode());
        } catch (\Exception $e) {
            // Log temporário para debug
            error_log('[Usuario2Controller] Erro ao criar usuário: ' . $e->getMessage());
            return Response::json([
                'error' => 'Erro interno do servidor',
                'debug' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ], 500);
        }
    }
    
    /**
     * PUT /api/usuarios2/{uuid}
     * Atualiza um usuário
     */
    public function atualizar(Request $request): Response
    {
        try {
            $uuid = $request->params['uuid'] ?? null;
            $body = $request->body ?? [];
            $atualizadoPor = $request->usuarioUuid ?? null;
            
            if (!$uuid) {
                return Response::json(['error' => 'UUID é obrigatório'], 422);
            }
            
            $usuario = $this->usuarioService->atualizar($uuid, $body, $atualizadoPor);
            
            return Response::json([
                'success' => true,
                'message' => 'Usuário atualizado com sucesso',
                'data' => $usuario->toArray(),
            ], 200);
            
        } catch (Usuarios2Exception $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'errors' => method_exists($e, 'getErros') ? $e->getErros() : null,
            ], $e->getHttpCode());
        } catch (\Exception $e) {
            return Response::json(['error' => 'Erro interno do servidor'], 500);
        }
    }
    
    /**
     * DELETE /api/usuarios2/{uuid}
     * Deleta um usuário (soft delete)
     */
    public function deletar(Request $request): Response
    {
        try {
            $uuid = $request->params['uuid'] ?? null;
            $deletadoPor = $request->usuarioUuid ?? null;
            
            if (!$uuid) {
                return Response::json(['error' => 'UUID é obrigatório'], 422);
            }
            
            $this->usuarioService->deletar($uuid, $deletadoPor);
            
            return Response::json([
                'success' => true,
                'message' => 'Usuário deletado com sucesso',
            ], 200);
            
        } catch (Usuarios2Exception $e) {
            return Response::json(['error' => $e->getMessage()], $e->getHttpCode());
        } catch (\Exception $e) {
            return Response::json(['error' => 'Erro interno do servidor'], 500);
        }
    }
    
    /**
     * POST /api/usuarios2/{uuid}/bloquear
     * Bloqueia um usuário
     */
    public function bloquear(Request $request): Response
    {
        try {
            $uuid = $request->params['uuid'] ?? null;
            $body = $request->body ?? [];
            $bloqueadoPor = $request->usuarioUuid ?? null;
            
            if (!$uuid) {
                return Response::json(['error' => 'UUID é obrigatório'], 422);
            }
            
            if (empty($body['motivo'])) {
                return Response::json(['error' => 'Motivo é obrigatório'], 422);
            }
            
            $this->usuarioService->bloquear(
                $uuid,
                $body['motivo'],
                $body['bloqueado_ate'] ?? null,
                $bloqueadoPor
            );
            
            return Response::json([
                'success' => true,
                'message' => 'Usuário bloqueado com sucesso',
            ], 200);
            
        } catch (Usuarios2Exception $e) {
            return Response::json(['error' => $e->getMessage()], $e->getHttpCode());
        } catch (\Exception $e) {
            return Response::json(['error' => 'Erro interno do servidor'], 500);
        }
    }
    
    /**
     * POST /api/usuarios2/{uuid}/desbloquear
     * Desbloqueia um usuário
     */
    public function desbloquear(Request $request): Response
    {
        try {
            $uuid = $request->params['uuid'] ?? null;
            $atualizadoPor = $request->usuarioUuid ?? null;
            
            if (!$uuid) {
                return Response::json(['error' => 'UUID é obrigatório'], 422);
            }
            
            $this->usuarioService->desbloquear($uuid, $atualizadoPor);
            
            return Response::json([
                'success' => true,
                'message' => 'Usuário desbloqueado com sucesso',
            ], 200);
            
        } catch (Usuarios2Exception $e) {
            return Response::json(['error' => $e->getMessage()], $e->getHttpCode());
        } catch (\Exception $e) {
            return Response::json(['error' => 'Erro interno do servidor'], 500);
        }
    }
    
    /**
     * GET /api/usuarios2/estatisticas
     * Retorna estatísticas de usuários
     */
    public function estatisticas(Request $request): Response
    {
        try {
            $stats = $this->usuarioService->estatisticas();
            
            return Response::json([
                'success' => true,
                'data' => $stats,
            ], 200);
            
        } catch (\Exception $e) {
            return Response::json(['error' => 'Erro interno do servidor'], 500);
        }
    }
}
