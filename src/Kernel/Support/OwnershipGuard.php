<?php

namespace Src\Kernel\Support;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Proteção contra IDOR (Insecure Direct Object Reference).
 *
 * Garante que o usuário autenticado só acessa seus próprios recursos,
 * a menos que seja admin_system com JWT_API_SECRET.
 *
 * Uso nos controllers:
 *
 *   $guard = OwnershipGuard::check($request, $targetUuid);
 *   if ($guard !== null) return $guard; // 403
 *
 * Ou com tenant:
 *   $guard = OwnershipGuard::checkTenant($request, $resourceTenantId);
 *   if ($guard !== null) return $guard;
 */
final class OwnershipGuard
{
    /**
     * Verifica se o usuário autenticado é dono do recurso identificado por $targetUuid.
     * Admin_system com JWT_API_SECRET tem acesso irrestrito.
     *
     * @return Response|null  null = acesso permitido, Response = 403 bloqueado
     */
    public static function check(Request $request, string $targetUuid): ?Response
    {
        // API token puro tem acesso irrestrito
        if ($request->attribute('api_token') === true) {
            return null;
        }

        $authUser = $request->attribute('auth_user');
        if ($authUser === null) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        // admin_system com JWT_API_SECRET tem acesso irrestrito
        $payload = $request->attribute('auth_payload');
        $isAdminSystem = ($payload->nivel_acesso ?? '') === 'admin_system'
            && $request->attribute('token_signed_with_api_secret') === true;

        if ($isAdminSystem) {
            return null;
        }

        // Verifica ownership
        if (!is_object($authUser) || !method_exists($authUser, 'getUuid')) {
            return Response::json(['error' => 'Acesso negado.'], 403);
        }

        $authUuid = (string) $authUser->getUuid();
        if ($authUuid !== $targetUuid) {
            return Response::json(['error' => 'Acesso negado: recurso pertence a outro usuário.'], 403);
        }

        return null;
    }

    /**
     * Verifica se o usuário autenticado pertence ao tenant do recurso.
     * Útil para sistemas multi-tenant.
     *
     * @return Response|null  null = acesso permitido, Response = 403 bloqueado
     */
    public static function checkTenant(Request $request, string $resourceTenantId): ?Response
    {
        if ($request->attribute('api_token') === true) {
            return null;
        }

        $authUser = $request->attribute('auth_user');
        if ($authUser === null) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        $payload = $request->attribute('auth_payload');
        $isAdminSystem = ($payload->nivel_acesso ?? '') === 'admin_system'
            && $request->attribute('token_signed_with_api_secret') === true;

        if ($isAdminSystem) {
            return null;
        }

        // Verifica tenant via claim do JWT
        $tokenTenant = (string) ($payload->tenant_id ?? '');
        if ($tokenTenant === '' || $tokenTenant !== $resourceTenantId) {
            return Response::json(['error' => 'Acesso negado: recurso pertence a outro tenant.'], 403);
        }

        return null;
    }

    /**
     * Verifica se o usuário tem pelo menos o nível de acesso requerido.
     *
     * @return Response|null  null = acesso permitido, Response = 403 bloqueado
     */
    public static function requireLevel(Request $request, string $minLevel): ?Response
    {
        if ($request->attribute('api_token') === true) {
            return null;
        }

        $payload = $request->attribute('auth_payload');
        if ($payload === null) {
            return Response::json(['error' => 'Não autenticado.'], 401);
        }

        $hierarchy = ['usuario' => 1, 'moderador' => 2, 'admin' => 3, 'admin_system' => 4];
        $userLevel = $hierarchy[$payload->nivel_acesso ?? ''] ?? 0;
        $required  = $hierarchy[$minLevel] ?? 99;

        if ($userLevel < $required) {
            return Response::json(['error' => 'Nível de acesso insuficiente.'], 403);
        }

        return null;
    }
}
