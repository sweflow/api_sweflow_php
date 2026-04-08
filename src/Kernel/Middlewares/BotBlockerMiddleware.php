<?php

namespace Src\Kernel\Middlewares;

use Src\Kernel\Contracts\MiddlewareInterface;
use Src\Kernel\Http\Request\Request;
use Src\Kernel\Http\Response\Response;
use Src\Kernel\Support\IpResolver;
use Src\Kernel\Support\SecurityEventLogger;
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

    private string $env;

    public function __construct(private ?ThreatScorer $scorer = null)
    {
        $this->env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
    }

    public function handle(Request $request, callable $next): Response
    {
        $ip  = IpResolver::resolve();
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uri = $request->getUri();

        // Resolve o scorer uma vez por request — garante consistência entre add() e get()
        $scorer = $this->scorer ?? new ThreatScorer();

        $isLoopback = in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
                      || strncmp($ip, '127.', 4) === 0;

        // 1. Bloqueia User-Agents de ferramentas conhecidas (sempre, inclusive loopback)
        if ($this->isMaliciousUserAgent($ua)) {
            $scorer->add($ip, ThreatScorer::SCORE_MALICIOUS_UA);
            $this->logEvent('bot.blocked.ua', $ip, $uri, $ua, $scorer);
            return $this->blockResponse();
        }

        // 2. Requisições de API sem User-Agent (sempre, inclusive loopback)
        if (str_starts_with($uri, '/api/') && trim($ua) === '') {
            $scorer->add($ip, ThreatScorer::SCORE_NO_UA);
            $this->logEvent('bot.blocked.no_ua', $ip, $uri, '', $scorer);
            return $this->blockResponse();
        }

        // Em ambiente não-produção com IP loopback: ignora score acumulado e delay.
        // Evita que scores de testes bloqueiem o desenvolvimento local.
        if ($this->env !== 'production' && $isLoopback) {
            return $next($request);
        }

        // 3. Bloqueia por score acumulado
        if ($scorer->shouldBlock($ip)) {
            $this->logEvent('bot.blocked.score', $ip, $uri, $ua, $scorer);
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

    private function logEvent(string $event, string $ip, string $uri, string $ua, ThreatScorer $scorer): void
    {
        if ($this->env === 'testing') {
            return;
        }
        SecurityEventLogger::threat($event, $ip, [
            'uri'        => $uri,
            'user_agent' => substr($ua, 0, 300),
            'score'      => $scorer->get($ip),
        ]);
    }
}
