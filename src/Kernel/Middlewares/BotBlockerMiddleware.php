<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\IpResolver;
use Src\Kernel\Support\ThreatScorer;

/**
 * Bloqueia bots maliciosos por User-Agent e threat score acumulado.
 *
 * Estratégias:
 *  1. Bloqueia User-Agents de scanners/exploit tools conhecidos (+50 pts)
 *  2. Bloqueia requisições de API sem User-Agent (+15 pts)
 *  3. Aplica delay progressivo baseado no threat score do IP
 *  4. Bloqueia IPs com score >= ThreatScorer::THRESHOLD_BLOCK
 *  5. Loga bloqueios em stderr (formato JSON para Fail2Ban)
 */
class BotBlockerMiddleware implements MiddlewareInterface
{
    /** Ferramentas que raramente fazem spoofing de UA — bloquear é seguro. */
    private const BLOCKED_UA_PATTERNS = [
        'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'zgrab2', 'nuclei',
        'dirbuster', 'gobuster', 'wfuzz', 'ffuf', 'feroxbuster',
        'hydra', 'medusa', 'burpsuite', 'burp suite',
        'acunetix', 'nessus', 'openvas', 'qualys',
        'havij', 'pangolin', 'jsql',
        'libwww-perl', 'lwp-trivial', 'scrapy',
    ];

    public function __construct(private ?ThreatScorer $scorer = null)
    {
        $this->scorer ??= new ThreatScorer();
    }

    public function handle(Request $request, callable $next): Response
    {
        $ip  = IpResolver::resolve();
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uri = $request->getUri();

        $scorer = $this->scorer;

        // Loopback em desenvolvimento nunca é bloqueado
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        $isLoopback = in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
                      || strncmp($ip, '127.', 4) === 0;
        if ($env !== 'production' && $isLoopback) {
            return $next($request);
        }

        // 1. Bloqueia por score acumulado (comportamento anterior)
        if ($scorer->shouldBlock($ip)) {
            $this->log('bot.blocked.score', $ip, $uri, $ua);
            return $this->blockResponse();
        }

        // 2. Bloqueia User-Agents de ferramentas conhecidas
        if ($this->isMaliciousUserAgent($ua)) {
            $scorer->add($ip, ThreatScorer::SCORE_MALICIOUS_UA);
            $this->log('bot.blocked.ua', $ip, $uri, $ua);
            return $this->blockResponse();
        }

        // 3. API sem User-Agent
        if (str_starts_with($uri, '/api/') && trim($ua) === '') {
            $scorer->add($ip, ThreatScorer::SCORE_NO_UA);
            $this->log('bot.blocked.no_ua', $ip, $uri, '');
            return $this->blockResponse();
        }

        // 4. Delay progressivo para IPs com score elevado mas abaixo do threshold de bloqueio
        $delay = $scorer->delaySeconds($ip);
        if ($delay > 0) {
            sleep($delay);
        }

        return $next($request);
    }

    private function isMaliciousUserAgent(string $ua): bool
    {
        if (trim($ua) === '') {
            return false;
        }
        $uaLower = strtolower($ua);
        foreach (self::BLOCKED_UA_PATTERNS as $pattern) {
            if (str_contains($uaLower, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function blockResponse(): Response
    {
        return Response::json(['error' => 'Forbidden'], 403);
    }

    private function log(string $event, string $ip, string $uri, string $ua): void
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        if ($env === 'testing') {
            return;
        }
        $line = json_encode([
            'timestamp'  => date('Y-m-d\TH:i:sP'),
            'type'       => 'BOT_BLOCKED',
            'event'      => $event,
            'ip'         => $ip,
            'uri'        => $uri,
            'user_agent' => substr($ua, 0, 300),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents('php://stderr', $line . PHP_EOL, FILE_APPEND);
    }
}
