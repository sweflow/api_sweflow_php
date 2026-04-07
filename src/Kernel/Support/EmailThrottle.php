<?php

namespace Src\Kernel\Support;

use PDO;

/**
 * Controla o throttle de envio de e-mails por tipo e endereço.
 * Evita disparos duplicados em menos de $cooldownSeconds segundos.
 *
 * Centraliza a lógica que estava duplicada em AuthController e UsuarioController.
 */
final class EmailThrottle
{
    private const PURGE_INTERVAL = 3600; // segundos

    public function __construct(private PDO $pdo) {}

    /**
     * Retorna true se o e-mail pode ser enviado (cooldown expirado ou sem registro).
     */
    public function canSend(string $type, string $email, int $cooldownSeconds = 120): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT sent_at FROM email_throttle WHERE type = :type AND email = :email"
            );
            $stmt->execute([':type' => $type, ':email' => strtolower(trim($email))]);
            $row = $stmt->fetch();
            if (!$row) {
                return true;
            }
            return (time() - strtotime((string) $row['sent_at'])) >= $cooldownSeconds;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Registra o envio e purga entradas antigas (> 1h).
     */
    public function record(string $type, string $email): void
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $normalizedEmail = strtolower(trim($email));

            if ($driver === 'pgsql') {
                $this->pdo->prepare(
                    "INSERT INTO email_throttle (type, email, sent_at) VALUES (:type, :email, NOW())
                     ON CONFLICT (type, email) DO UPDATE SET sent_at = NOW()"
                )->execute([':type' => $type, ':email' => $normalizedEmail]);
                $this->pdo->exec("DELETE FROM email_throttle WHERE sent_at < NOW() - INTERVAL '" . self::PURGE_INTERVAL . " seconds'");
            } else {
                $this->pdo->prepare(
                    "INSERT INTO email_throttle (type, email, sent_at) VALUES (:type, :email, NOW())
                     ON DUPLICATE KEY UPDATE sent_at = NOW()"
                )->execute([':type' => $type, ':email' => $normalizedEmail]);
                $this->pdo->exec("DELETE FROM email_throttle WHERE sent_at < DATE_SUB(NOW(), INTERVAL " . self::PURGE_INTERVAL . " SECOND)");
            }
        } catch (\Throwable $e) {
            error_log('[EmailThrottle] record failed: ' . $e->getMessage());
        }
    }
}
