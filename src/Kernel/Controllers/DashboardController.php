<?php
namespace Src\Controllers;

use Src\View;
use Src\Database\PdoFactory;
use Src\Modules\Auth\Services\AuthService;
use Src\Modules\Auth\Repositories\RefreshTokenRepository;
use Src\Modules\Usuario\Repositories\UsuarioRepository;
use DomainException;

class DashboardController
{
    public function index(): void
    {
        if (!$this->autorizarAdminSystem()) {
            header('Location: /', true, 302);
            exit;
        }

        $logoUrl = $_ENV['APP_LOGO_URL'] ?? getenv('APP_LOGO_URL') ?? '/public/favicon.ico';

        View::render('dashboard', [
            'titulo' => 'Dashboard da API',
            'descricao' => 'Monitoramento em tempo real do núcleo da API.',
            'logo_url' => $logoUrl,
        ]);
    }

    private function autorizarAdminSystem(): bool
    {
        $token = $_COOKIE['auth_token'] ?? '';
        if (trim($token) === '') {
            return false;
        }
        try {
            $pdo = PdoFactory::fromEnv();
            $usuarioRepo = new UsuarioRepository($pdo);
            $refreshRepo = new RefreshTokenRepository($pdo);
            $auth = new AuthService($usuarioRepo, $refreshRepo);
            $payload = $auth->decodificarToken($token);
            $uuid = $payload->sub ?? '';
            if ($uuid === '') {
                return false;
            }
            $usuario = $usuarioRepo->buscarPorUuid($uuid);
            if (!$usuario) {
                return false;
            }
            return $usuario->getNivelAcesso() === 'admin_system';
        } catch (DomainException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
