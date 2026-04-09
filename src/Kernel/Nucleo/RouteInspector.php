<?php

namespace Src\Kernel\Nucleo;

/**
 * Inspeciona controllers para detectar campos esperados por cada rota.
 *
 * Estratégia:
 *  1. Lê o corpo do método do controller via ReflectionMethod
 *  2. Extrai campos de qualquer $var['campo'] no código-fonte
 *  3. Detecta parâmetros de rota via {param} na URI
 *  4. Detecta query params via $request->query('param') / $_GET['param']
 */
class RouteInspector
{
    // Nomes que são claramente variáveis de controle, não campos de formulário
    private const SKIP_NAMES = [
        'status', 'error', 'message', 'code', 'type', 'class', 'method',
        'action', 'controller', 'route', 'path', 'uri', 'host', 'version',
        'format', 'locale', 'lang', 'charset', 'encoding', 'access_token',
        'refresh_token', 'expires_in', 'token_type', 'user_agent', 'ip',
        'timestamp', 'created_at', 'updated_at', 'deleted_at',
    ];

    public static function inspect(string $method, string $uri, mixed $handler, array $middlewares = []): array
    {
        $fields      = [];
        $pathParams  = [];
        $queryParams = [];
        $bodyFields  = [];
        $description = '';

        // ── Parâmetros de rota (URI) ─────────────────────────────────
        preg_match_all('/\{(\w+)\}/', $uri, $m);
        $pathParams = $m[1];

        // ── Inspeção do controller ───────────────────────────────────
        if (is_array($handler) && count($handler) === 2) {
            [$class, $action] = $handler;
            if (is_string($class) && is_string($action) && class_exists($class)) {
                try {
                    $ref = new \ReflectionMethod($class, $action);

                    $doc = $ref->getDocComment();
                    if ($doc) {
                        preg_match('/\*\s+([^@\*\n][^\n]+)/', $doc, $dm);
                        $description = trim($dm[1] ?? '');
                    }

                    $src         = self::readMethodSource($ref);
                    $bodyFields  = self::extractBodyFields($src);
                    $queryParams = self::extractQueryParams($src);

                } catch (\Throwable) {
                    // Reflection falhou — continua sem campos
                }
            }
        }

        // ── Monta lista de campos ────────────────────────────────────
        foreach ($pathParams as $p) {
            $fields[] = ['name' => $p, 'in' => 'path', 'required' => true, 'type' => 'string'];
        }
        foreach ($queryParams as $q) {
            $fields[] = ['name' => $q, 'in' => 'query', 'required' => false, 'type' => 'string'];
        }
        foreach ($bodyFields as $b) {
            $fields[] = ['name' => $b['name'], 'in' => 'body', 'required' => $b['required'], 'type' => $b['type']];
        }

        // Colapsa aliases de body (login/identifier → removidos; password → senha)
        $bodyOnly  = array_filter($fields, fn($f) => $f['in'] === 'body');
        $otherOnly = array_filter($fields, fn($f) => $f['in'] !== 'body');
        $fields = array_values(array_merge(array_values($otherOnly), self::collapseAliases(array_values($bodyOnly))));

        $authType = self::detectAuth($middlewares);

        return [
            'method'      => strtoupper($method),
            'uri'         => $uri,
            'description' => $description,
            'auth'        => $authType,
            'fields'      => $fields,
            'path_params' => $pathParams,
            'query_params'=> $queryParams,
            'body_fields' => array_column($bodyFields, 'name'),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private static function readMethodSource(\ReflectionMethod $ref): string
    {
        static $cache = [];

        $file  = $ref->getFileName();
        $start = $ref->getStartLine();
        $end   = $ref->getEndLine();
        if (!$file || !$start || !$end) {
            return '';
        }

        $cacheKey = $file . ':' . $start . ':' . $end;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $lines = file($file);
        if (!$lines) {
            return $cache[$cacheKey] = '';
        }

        return $cache[$cacheKey] = implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * Extrai campos do body a partir do código-fonte do método.
     * Captura qualquer $var['campo'] — cobre $dados, $body, $input, $data, etc.
     * Exclui campos que aparecem apenas em blocos de resposta (return Response::json).
     */
    private static function extractBodyFields(string $src): array
    {
        $fields = [];
        $seen   = [];

        // Remove blocos de resposta para não capturar campos de output
        $srcInput = self::removeResponseBlocks($src);

        // Padrão amplo: qualquer $variavel['chave_string']
        preg_match_all(
            '/\$[a-zA-Z_]\w*\s*\[\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*\]/',
            $srcInput,
            $m
        );
        foreach ($m[1] as $name) {
            if (in_array($name, self::SKIP_NAMES, true)) continue;
            if (strlen($name) < 2 || strlen($name) > 60) continue;
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $fields[] = [
                    'name'     => $name,
                    'required' => self::isRequired($srcInput, $name),
                    'type'     => self::guessType($name),
                ];
            }
        }

        // ->input('campo') / ->get('campo') / ->post('campo')
        preg_match_all('/->(?:input|get|post)\s*\(\s*[\'"]([a-zA-Z_]\w*)[\'"]\s*\)/', $srcInput, $m);
        foreach ($m[1] as $name) {
            if (in_array($name, self::SKIP_NAMES, true)) continue;
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $fields[] = ['name' => $name, 'required' => self::isRequired($srcInput, $name), 'type' => self::guessType($name)];
            }
        }

        // trim($var['campo']) / (string)($var['campo']) / (int)($var['campo'])
        preg_match_all(
            '/(?:trim|intval|floatval|strval|\(string\)|\(int\)|\(float\)|\(bool\))\s*\(\s*\$\w+\s*\[\s*[\'"]([a-zA-Z_]\w*)[\'"]\s*\]/',
            $srcInput,
            $m
        );
        foreach ($m[1] as $name) {
            if (in_array($name, self::SKIP_NAMES, true)) continue;
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $fields[] = ['name' => $name, 'required' => self::isRequired($srcInput, $name), 'type' => self::guessType($name)];
            }
        }

        return $fields;
    }

    /**
     * Remove blocos de resposta (Response::json([...]), return [...]) do código
     * para evitar capturar campos de output como se fossem campos de input.
     */
    private static function removeResponseBlocks(string $src): string
    {
        // Remove Response::json([...]) — substitui por espaços para manter posições
        $src = preg_replace('/Response\s*::\s*\w+\s*\(.*?\)\s*;/s', '', $src) ?? $src;
        // Remove return [...] arrays literais
        $src = preg_replace('/return\s*\[.*?\]\s*;/s', '', $src) ?? $src;
        // Remove linhas com 'expira_em', 'expires_in', 'token_type' que são campos de resposta
        $src = preg_replace('/[\'"](?:expira_em|expires_in|token_type|access_expira_em|refresh_expira_em)[\'"]/', '', $src) ?? $src;
        return $src;
    }

    private static function extractQueryParams(string $src): array
    {
        $params = [];
        $seen   = [];

        preg_match_all('/\$_GET\s*\[\s*[\'"]([a-zA-Z_]\w*)[\'"]\s*\]/', $src, $m);
        foreach ($m[1] as $name) {
            if (!isset($seen[$name])) { $seen[$name] = true; $params[] = $name; }
        }

        preg_match_all('/->query(?:Param)?\s*\(\s*[\'"]([a-zA-Z_]\w*)[\'"]\s*\)/', $src, $m);
        foreach ($m[1] as $name) {
            if (!isset($seen[$name])) { $seen[$name] = true; $params[] = $name; }
        }

        return $params;
    }

    private static function isRequired(string $src, string $name): bool
    {
        // Se tem ?? após o campo, é opcional
        if (preg_match('/[\'"]' . preg_quote($name, '/') . '[\'"]\s*\]\s*\?\?/', $src)) {
            return false;
        }
        // Se tem isset() ou empty() em volta, é opcional
        if (preg_match('/(?:isset|empty)\s*\([^)]*[\'"]' . preg_quote($name, '/') . '[\'"]/', $src)) {
            return false;
        }
        return true;
    }

    private static function guessType(string $name): string
    {
        $n = strtolower($name);
        return self::resolveTypeFromMap($n) ?? self::resolveTypeFromPatterns($n) ?? 'string';
    }

    private static function resolveTypeFromMap(string $n): ?string
    {
        $exactMap = [
            'email'          => 'email',
            'e_mail'         => 'email',
            'email_address'  => 'email',
            'senha'          => 'password',
            'password'       => 'password',
            'pass'           => 'password',
            'pwd'            => 'password',
            'nova_senha'     => 'password',
            'telefone'       => 'phone',
            'phone'          => 'phone',
            'celular'        => 'phone',
            'fone'           => 'phone',
            'ativo'          => 'boolean',
            'enabled'        => 'boolean',
            'active'         => 'boolean',
            'verificado'     => 'boolean',
        ];
        return $exactMap[$n] ?? null;
    }

    private static function resolveTypeFromPatterns(string $n): ?string
    {
        if (str_contains($n, 'uuid') || str_ends_with($n, '_id'))   return 'uuid';
        if (str_contains($n, 'url')  || str_contains($n, 'link'))   return 'url';
        if (str_contains($n, 'data') || str_contains($n, 'date'))   return 'date';
        if (str_contains($n, 'nivel') || str_contains($n, 'role') || str_contains($n, 'acesso')) return 'enum';
        if (str_contains($n, 'limit') || str_contains($n, 'page')
            || str_contains($n, 'count') || str_contains($n, 'total')
            || str_contains($n, 'offset'))                           return 'integer';
        return null;
    }

    /**
     * Colapsa grupos de campos que são aliases do mesmo input,
     * mantendo apenas o nome canônico mais descritivo.
     * Ex: login/identifier/email/username → username + email (separados)
     */
    private static function collapseAliases(array $fields): array
    {
        // Grupos de aliases: o primeiro nome da lista é o canônico preferido
        $aliasGroups = [
            ['login', 'identifier'],          // ambos são "identificador de login" — remover, pois email/username já cobrem
        ];

        // Nomes a remover completamente (são aliases internos, não campos reais de API)
        $removeNames = ['login', 'identifier'];

        // password é alias de senha — manter apenas 'senha'
        $passwordAliases = ['password'];

        $result = [];
        $seenNames = [];

        foreach ($fields as $field) {
            $name = $field['name'];

            // Remove aliases internos que não devem aparecer no JSON
            if (in_array($name, $removeNames, true)) continue;

            // Colapsa 'password' → 'senha'
            if (in_array($name, $passwordAliases, true)) {
                if (!isset($seenNames['senha'])) {
                    $seenNames['senha'] = true;
                    $result[] = array_merge($field, ['name' => 'senha', 'type' => 'password']);
                }
                continue;
            }

            if (!isset($seenNames[$name])) {
                $seenNames[$name] = true;
                $result[] = $field;
            }
        }

        return $result;
    }

    private static function detectAuth(array $middlewares): string
    {
        $flat = [];
        foreach ($middlewares as $mw) {
            $def = is_array($mw) ? ($mw[0] ?? '') : $mw;
            if (is_string($def)) {
                $flat[] = basename(str_replace('\\', '/', $def));
            }
        }
        if (in_array('AdminOnlyMiddleware', $flat, true))  return 'admin';
        if (in_array('AuthHybridMiddleware', $flat, true)) return 'jwt';
        if (in_array('AuthCookieMiddleware', $flat, true)) return 'cookie';
        if (in_array('ApiTokenMiddleware', $flat, true))   return 'api_token';
        return 'none';
    }
}
