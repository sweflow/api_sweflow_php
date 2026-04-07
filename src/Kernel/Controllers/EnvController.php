<?php

namespace Src\Kernel\Controllers;

use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * Lê e salva variáveis do .env pelo dashboard.
 * Apenas admin_system pode acessar (garantido pelos middlewares da rota).
 */
class EnvController
{
    private string $envPath;

    // Chaves do banco core — nunca editáveis pelo dashboard
    private const DB_CORE_KEYS = [
        'DB_CONEXAO', 'DB_HOST', 'DB_PORT', 'DB_NOME',
        'DB_USUARIO', 'DB_SENHA', 'DB_CHARSET', 'DB_COLLATION', 'DB_PREFIX',
    ];

    // Chaves sensíveis — mascaradas na leitura, só sobrescritas se o usuário digitar algo novo
    private const SENSITIVE_KEYS = [
        'JWT_SECRET', 'JWT_API_SECRET', 'JWT_SECRET_v1', 'JWT_SECRET_v2',
        'DB_SENHA', 'DB2_SENHA', 'MAILER_PASSWORD', 'MYSQL_ROOT_PASSWORD',
        'ADMIN_PASSWORD', 'REDIS_PASSWORD', 'API_KEY',
    ];

    public function __construct()
    {
        $this->envPath = realpath(dirname(__DIR__, 3) . '/.env') ?: '';
    }

    // ── GET /api/env ──────────────────────────────────────────────────────────
    public function index(): Response
    {
        if (!$this->envReadable()) {
            return Response::json(['error' => 'Arquivo .env não encontrado ou sem permissão de leitura.'], 500);
        }

        $vars = $this->parse();
        foreach ($vars as $key => &$val) {
            if ($this->isSensitive($key)) {
                $val = $val !== '' ? '••••••••' : '';
            }
        }
        unset($val);

        return Response::json(['vars' => $vars]);
    }

    // ── PUT /api/env ──────────────────────────────────────────────────────────
    public function update(Request $request): Response
    {
        if (!$this->envWritable()) {
            return Response::json(['error' => 'Arquivo .env sem permissão de escrita.'], 500);
        }

        $body    = $request->body ?? [];
        $updates = $body['vars'] ?? null;

        if (!is_array($updates) || empty($updates)) {
            return Response::json(['error' => 'Nenhuma variável enviada.'], 422);
        }

        // Valida chaves — apenas letras maiúsculas, números e underscore, começando com letra
        foreach (array_keys($updates) as $key) {
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', (string) $key)) {
                return Response::json(['error' => "Chave inválida: $key"], 422);
            }
        }

        // Bloqueia DB core — segurança crítica (dupla camada: frontend + backend)
        foreach (array_keys($updates) as $key) {
            if (in_array($key, self::DB_CORE_KEYS, true)) {
                return Response::json(['error' => "A chave '$key' não pode ser alterada pelo dashboard."], 403);
            }
        }

        // Campos sensíveis: mantém valor atual se o frontend enviou placeholder ou vazio
        $current = $this->parse();
        foreach ($updates as $key => $newVal) {
            if ($this->isSensitive($key)) {
                $trimmed = trim((string) $newVal);
                // Considera placeholder: vazio, bullets Unicode (••••••••),
                // pontos ASCII (........) ou asteriscos (********)
                $isPlaceholder = $trimmed === ''
                    || $trimmed === '••••••••'
                    || (strlen($trimmed) >= 4 && strlen($trimmed) === substr_count($trimmed, '.'))
                    || (strlen($trimmed) >= 4 && strlen($trimmed) === substr_count($trimmed, '*'));
                if ($isPlaceholder) {
                    $updates[$key] = $current[$key] ?? '';
                }
            }
        }

        // Sanitiza valores — remove caracteres de controle exceto tab
        foreach ($updates as $key => $val) {
            $updates[$key] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $val);
        }

        $this->save($updates);

        // Reflete imediatamente no processo atual
        foreach ($updates as $key => $val) {
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
            putenv("$key=$val");
        }

        return Response::json(['ok' => true, 'message' => 'Variáveis salvas com sucesso.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function envReadable(): bool
    {
        return $this->envPath !== '' && is_file($this->envPath) && is_readable($this->envPath);
    }

    private function envWritable(): bool
    {
        return $this->envReadable() && is_writable($this->envPath);
    }

    /** Lê o .env e retorna array chave => valor (sem comentários). */
    private function parse(): array
    {
        if (!$this->envReadable()) {
            return [];
        }

        $vars  = [];
        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // Remove aspas envolventes (simples ou duplas)
            if (strlen($val) >= 2) {
                $first = $val[0];
                $last  = $val[-1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, -1);
                    // Desescapa aspas duplas internas
                    if ($first === '"') {
                        $val = str_replace('\\"', '"', $val);
                    }
                }
            }

            if ($key !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                $vars[$key] = $val;
            }
        }

        return $vars;
    }

    /**
     * Salva variáveis no .env preservando comentários, ordem e linhas em branco.
     * Usa flock exclusivo para evitar race condition.
     */
    private function save(array $updates): void
    {
        if (!$this->envWritable()) {
            return;
        }

        $fp = fopen($this->envPath, 'r+');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_EX);

        $raw = stream_get_contents($fp);
        if ($raw === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }

        $rawLines = explode("\n", $raw);
        $newLines = [];

        foreach ($rawLines as $line) {
            $trimmed = rtrim($line);
            $stripped = ltrim($trimmed);

            // Preserva linhas vazias e comentários
            if ($stripped === '' || $stripped[0] === '#') {
                $newLines[] = $trimmed;
                continue;
            }

            $pos = strpos($stripped, '=');
            if ($pos === false) {
                $newLines[] = $trimmed;
                continue;
            }

            $key = trim(substr($stripped, 0, $pos));

            if (array_key_exists($key, $updates)) {
                $val = $updates[$key];
                $newLines[] = $key . '=' . $this->quoteValue($val);
            } else {
                $newLines[] = $trimmed;
            }
        }

        $content = implode("\n", $newLines);
        // Garante newline final
        if ($content !== '' && $content[-1] !== "\n") {
            $content .= "\n";
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $content);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Adiciona aspas ao valor se necessário para preservar espaços,
     * caracteres especiais ou valores que começam com #.
     */
    private function quoteValue(string $val): string
    {
        if ($val === '') {
            return '';
        }
        // Precisa de aspas se contém: espaço, #, aspas, $, \, ou começa/termina com espaço
        $needsQuotes = str_contains($val, ' ')
            || str_contains($val, '#')
            || str_contains($val, '$')
            || str_contains($val, '\\')
            || $val !== trim($val);

        if ($needsQuotes) {
            // Escapa aspas duplas internas e envolve em aspas duplas
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $val) . '"';
        }

        return $val;
    }

    private function isSensitive(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }
}
