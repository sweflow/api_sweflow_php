<?php

namespace Src\Kernel\Controllers;

use PDO;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;

/**
 * API de logs de auditoria para o dashboard.
 * Todos os endpoints exigem admin_system.
 */
class AuditLogController
{
    public function __construct(private PDO $pdo) {}

    /** GET /api/audit/logs */
    public function listar(Request $request): Response
    {
        try {
            $this->ensureTable();

            $q      = $request->query;
            $page   = max(1, (int) ($q['page']  ?? 1));
            $limit  = min(100, max(10, (int) ($q['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            [$where, $params] = $this->buildWhere($q);
            $whereClause = $where ? " WHERE $where" : '';

            $stmtC = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs" . $whereClause);
            $stmtC->execute($params);
            $total = (int) $stmtC->fetchColumn();

            $sql = "SELECT id, evento, usuario_uuid, contexto, ip, user_agent, endpoint, criado_em
                    FROM audit_logs"
                 . $whereClause
                 . " ORDER BY criado_em DESC"
                 . " LIMIT :limit OFFSET :offset";

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                if (isset($row['contexto']) && is_string($row['contexto'])) {
                    $row['contexto'] = json_decode($row['contexto'], true) ?? [];
                }
            }
            unset($row);

            return Response::json([
                'logs'      => $rows,
                'total'     => $total,
                'page'      => $page,
                'limit'     => $limit,
                'last_page' => max(1, (int) ceil($total / $limit)),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Erro ao carregar logs: ' . $e->getMessage()], 500);
        }
    }

    /** GET /api/audit/stats */
    public function stats(Request $request): Response
    {
        try {
            $this->ensureTable();

            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $since  = $driver === 'pgsql'
                ? "NOW() - INTERVAL '24 hours'"
                : "DATE_SUB(NOW(), INTERVAL 24 HOUR)";

            $row = $this->pdo->query("
                SELECT
                    SUM(CASE WHEN evento LIKE 'auth.%'        THEN 1 ELSE 0 END) AS auth,
                    SUM(CASE WHEN evento LIKE 'user.%'        THEN 1 ELSE 0 END) AS usuarios,
                    SUM(CASE WHEN evento LIKE 'bot.%'
                              OR evento LIKE 'rate_limit.%'
                              OR evento LIKE 'brute_force.%'
                              OR evento LIKE 'honeypot.%'
                              OR evento LIKE 'http.%'         THEN 1 ELSE 0 END) AS seguranca,
                    SUM(CASE WHEN evento LIKE 'module.%'
                              OR evento LIKE 'admin.%'        THEN 1 ELSE 0 END) AS admin,
                    COUNT(*)                                                      AS total
                FROM audit_logs
                WHERE criado_em > $since
            ")->fetch(PDO::FETCH_ASSOC) ?: [];

            $top = $this->pdo->query("
                SELECT evento, COUNT(*) as total
                FROM audit_logs
                WHERE criado_em > $since
                GROUP BY evento
                ORDER BY total DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);

            $topIps = $this->pdo->query("
                SELECT ip, COUNT(*) as total
                FROM audit_logs
                WHERE criado_em > $since
                  AND (evento LIKE 'auth.login.failed%'
                    OR evento LIKE 'bot.%'
                    OR evento LIKE 'rate_limit.%'
                    OR evento LIKE 'brute_force.%'
                    OR evento LIKE 'http.forbidden%'
                    OR evento LIKE 'http.unauthorized%')
                  AND ip != ''
                GROUP BY ip
                ORDER BY total DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);

            $seriesSql = $driver === 'pgsql'
                ? "SELECT DATE_TRUNC('hour', criado_em) AS hora, COUNT(*) AS total
                   FROM audit_logs
                   WHERE criado_em > NOW() - INTERVAL '24 hours'
                   GROUP BY hora ORDER BY hora ASC"
                : "SELECT DATE_FORMAT(criado_em, '%Y-%m-%d %H:00:00') AS hora, COUNT(*) AS total
                   FROM audit_logs
                   WHERE criado_em > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   GROUP BY hora ORDER BY hora ASC";
            $series = $this->pdo->query($seriesSql)->fetchAll(PDO::FETCH_ASSOC);

            return Response::json([
                'contagens'   => $row,
                'top_eventos' => $top,
                'top_ips'     => $topIps,
                'serie_24h'   => $series,
            ]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Erro ao carregar stats: ' . $e->getMessage()], 500);
        }
    }

    /** DELETE /api/audit/logs */
    public function limpar(Request $request): Response
    {
        try {
            $dias   = max(1, (int) ($request->body['dias'] ?? 90));
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $cutoff = $driver === 'pgsql'
                ? "NOW() - INTERVAL '$dias days'"
                : "DATE_SUB(NOW(), INTERVAL $dias DAY)";

            $stmt = $this->pdo->prepare("DELETE FROM audit_logs WHERE criado_em < $cutoff");
            $stmt->execute();

            return Response::json([
                'status'  => 'success',
                'deleted' => $stmt->rowCount(),
                'message' => $stmt->rowCount() . " registro(s) removido(s) com mais de $dias dias.",
            ]);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id           BIGSERIAL    PRIMARY KEY,
                    evento       VARCHAR(100) NOT NULL,
                    usuario_uuid UUID         NULL,
                    contexto     JSONB        NOT NULL DEFAULT '{}',
                    ip           VARCHAR(45)  NOT NULL DEFAULT '',
                    user_agent   VARCHAR(512) NOT NULL DEFAULT '',
                    endpoint     VARCHAR(255) NOT NULL DEFAULT '',
                    criado_em    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_evento    ON audit_logs (evento)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_ip        ON audit_logs (ip)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_criado_em ON audit_logs (criado_em DESC)");
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    evento       VARCHAR(100) NOT NULL,
                    usuario_uuid CHAR(36)     NULL,
                    contexto     JSON         NOT NULL,
                    ip           VARCHAR(45)  NOT NULL DEFAULT '',
                    user_agent   VARCHAR(512) NOT NULL DEFAULT '',
                    endpoint     VARCHAR(255) NOT NULL DEFAULT '',
                    criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_evento    (evento),
                    INDEX idx_ip        (ip),
                    INDEX idx_criado_em (criado_em)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    private function buildWhere(array $q): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($q['evento'])) {
            $conditions[] = 'evento LIKE :evento';
            $params[':evento'] = '%' . $q['evento'] . '%';
        }
        if (!empty($q['ip'])) {
            $conditions[] = 'ip LIKE :ip';
            $params[':ip'] = '%' . $q['ip'] . '%';
        }
        if (!empty($q['usuario_uuid'])) {
            $conditions[] = 'usuario_uuid = :uuid';
            $params[':uuid'] = $q['usuario_uuid'];
        }
        if (!empty($q['desde'])) {
            $conditions[] = 'criado_em >= :desde';
            $params[':desde'] = $q['desde'];
        }
        if (!empty($q['ate'])) {
            $conditions[] = 'criado_em <= :ate';
            $params[':ate'] = $q['ate'];
        }
        if (!empty($q['q'])) {
            $conditions[] = '(evento LIKE :q OR ip LIKE :q OR endpoint LIKE :q)';
            $params[':q'] = '%' . $q['q'] . '%';
        }
        if (!empty($q['categoria'])) {
            $prefixMap = [
                'auth'      => ['auth.%'],
                'usuarios'  => ['user.%'],
                'seguranca' => ['bot.%', 'rate_limit.%', 'brute_force.%', 'honeypot.%', 'http.%'],
                'admin'     => ['module.%', 'admin.%'],
            ];
            $cat = $q['categoria'];
            if (isset($prefixMap[$cat])) {
                $likes = [];
                foreach ($prefixMap[$cat] as $i => $p) {
                    $key          = ':cat' . $i;
                    $likes[]      = "evento LIKE $key";
                    $params[$key] = $p;
                }
                $conditions[] = '(' . implode(' OR ', $likes) . ')';
            }
        }

        return [implode(' AND ', $conditions), $params];
    }
}
