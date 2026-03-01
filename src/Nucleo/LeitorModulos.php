<?php
namespace Src\Nucleo;

class LeitorModulos
{
    public function ler(): array
    {
        $modulos = [];
        $diretorioModulos = __DIR__ . '/../Modules';
        if (is_dir($diretorioModulos)) {
            foreach (scandir($diretorioModulos) as $modulo) {
                if ($modulo === '.' || $modulo === '..') continue;
                $rotas = [];
                $arquivoRotas = $diretorioModulos . "/$modulo/Routes/web.php";
                if (file_exists($arquivoRotas)) {
                    $conteudo = file_get_contents($arquivoRotas);
                    preg_match_all('/Route::(get|post|put|delete|patch)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*\[(.*?)\](?:,\s*(.*?))?\s*\)/', $conteudo, $matches, PREG_SET_ORDER);
                    $adicionados = [];
                    foreach ($matches as $match) {
                        $metodo = strtoupper($match[1]);
                        $uri = $match[2];
                        $middlewares = '';
                        if (isset($match[4]) && !empty($match[4])) {
                            $middlewares = $match[4];
                        } elseif (isset($match[3]) && !empty($match[3])) {
                            $middlewares = $match[3];
                        }
                        if (preg_match('/^\s*\/\//', $match[0])) continue;
                        $privada = false;
                        if ((strpos($middlewares, 'AuthMiddleware::class') !== false || strpos($middlewares, 'RouteProtectionMiddleware::class') !== false) && strpos($middlewares, '/*') === false && strpos($middlewares, '*/') === false) {
                            $privada = true;
                        }
                        $chave = $metodo . $uri;
                        if (!isset($adicionados[$chave])) {
                            $rotas[] = [
                                'metodo' => $metodo,
                                'uri' => $uri,
                                'tipo' => $privada ? 'privada' : 'publica'
                            ];
                            $adicionados[$chave] = true;
                        }
                    }
                }
                $modulos[] = [
                    'nome' => $modulo,
                    'rotas' => $rotas
                ];
            }
        }
        return $modulos;
    }
}
