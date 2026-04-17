<?php

namespace Src\Kernel\Support;

use PDO;

/**
 * Controla o throttle de envio de e-mails por tipo e endereço.
 * Evita disparos duplicados em menos de $cooldownSeconds segundos.
 *
 * Centraliza a lógica que estava duplicada em AuthController e UsuarioController.
 *
 * A operação canSend + record é atômica via INSERT condicional — elimina a
 * race condition entre verificação e registro.
 */
final class EmailThrottle
{
    private const PURGE_INTERVAL = 3600; // segundos

    public function __construct(private PDO $pdo) {}

    /**
     * Tenta registrar o envio de forma atômica.
     * Retorna true se o registro foi feito (cooldown respeitado), false se bloqueado.
     *
     * Elimina a race condition entre canSend() e record() — use este método
     * em vez de chamar canSend() + record() separadamente.
     */
    public function tryRecord(string $type, string $email, int $cooldownSeconds = 120): bool
    {
        try {
            $driver          = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $normalizedEmail = strtolower(trim($email));

            if ($driver === 'pgsql') {
                // INSERT condicional: só insere/atualiza se o cooldown expirou
                $stmt = $this->pdo->prepare(
                    "INSERT INTO email_throttle (type, email, sent_at)
                     VALUES (:type, :email, NOW())
                     ON CONFLICT (type, email) DO UPDATE
                       SET sent_at = NOW()
                     WHERE email_throttle.sent_at < NOW() - make_interval(secs => :cooldown)"
                );
                $stmt->execute([':type' => $type, ':email' => $normalizedEmail, ':cooldown' => $cooldownSeconds]);
            } else {
                // MySQL: INSERT IGNORE se não existe, UPDATE condicional se expirou
                $stmt = $this->pdo->prepare(
                    "INSERT INTO email_throttle (type, email, sent_at)
                     VALUES (:type, :email, NOW())
                     ON DUPLICATE KEY UPDATE
                       sent_at = IF(sent_at < DATE_SUB(NOW(), INTERVAL :cooldown SECOND), NOW(), sent_at)"
                );
                $stmt->execute([':type' => $type, ':email' => $normalizedEmail, ':cooldown' => $cooldownSeconds]);
            }

            // rowCount = 1 (INSERT) ou 2 (UPDATE no MySQL) = cooldown respeitado e registrado
            // rowCount = 0 = ainda no cooldown, não atualizou
            $affected = $stmt->rowCount();
            if ($affected === 0) {
                return false;
            }

            $this->purgeOld();
            return true;
        } catch (\Throwable $e) {
            error_log('[EmailThrottle] tryRecord failed: ' . $e->getMessage());
            return true; // fail-open: não bloqueia envio em caso de erro de DB
        }
    }

    private function purgeOld(): void
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $this->pdo->prepare(
                    "DELETE FROM email_throttle WHERE sent_at < NOW() - make_interval(secs => :seconds)"
                )->execute([':seconds' => self::PURGE_INTERVAL]);
            } else {
                $this->pdo->prepare(
                    "DELETE FROM email_throttle WHERE sent_at < DATE_SUB(NOW(), INTERVAL :seconds SECOND)"
                )->execute([':seconds' => self::PURGE_INTERVAL]);
            }
        } catch (\Throwable) {}
    }
}
