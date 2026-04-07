<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Support\ThreatScorer;

/**
 * Testes para ThreatScorer — sistema de pontuação de ameaças por IP.
 */
class ThreatScorerTest extends TestCase
{
    private string $storageDir = '';
    private ?ThreatScorer $scorer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir() . '/sweflow_threat_test_' . uniqid();
        mkdir($this->storageDir, 0750, true);
        // Injeta storage de teste isolado
        $this->scorer = new ThreatScorer(
            new \Src\Kernel\Support\Storage\FileRateLimitStorage($this->storageDir)
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storageDir);
        parent::tearDown();
    }

    public function test_score_inicial_e_zero(): void
    {
        $this->assertSame(0, $this->scorer->get('1.2.3.4'));
    }

    public function test_add_acumula_pontos(): void
    {
        $this->scorer->add('1.2.3.4', 30);
        $this->scorer->add('1.2.3.4', 20);
        $this->assertSame(50, $this->scorer->get('1.2.3.4'));
    }

    public function test_ips_diferentes_nao_interferem(): void
    {
        $this->scorer->add('1.1.1.1', 100);
        $this->assertSame(0, $this->scorer->get('2.2.2.2'));
    }

    public function test_should_block_abaixo_do_threshold(): void
    {
        $this->scorer->add('1.2.3.4', ThreatScorer::THRESHOLD_BLOCK - 1);
        $this->assertFalse($this->scorer->shouldBlock('1.2.3.4'));
    }

    public function test_should_block_no_threshold(): void
    {
        $this->scorer->add('1.2.3.4', ThreatScorer::THRESHOLD_BLOCK);
        $this->assertTrue($this->scorer->shouldBlock('1.2.3.4'));
    }

    public function test_should_block_acima_do_threshold(): void
    {
        $this->scorer->add('1.2.3.4', ThreatScorer::THRESHOLD_BLOCK + 50);
        $this->assertTrue($this->scorer->shouldBlock('1.2.3.4'));
    }

    public function test_honeypot_hit_causa_bloqueio_imediato(): void
    {
        // Um único hit no honeypot (100pts) + um login falho (30pts) = 130pts < 150 (ainda não bloqueia)
        // Dois honeypot hits = 200pts >= 150 (bloqueia)
        $this->scorer->add('5.5.5.5', ThreatScorer::SCORE_HONEYPOT);
        $this->scorer->add('5.5.5.5', ThreatScorer::SCORE_HONEYPOT);
        $this->assertTrue($this->scorer->shouldBlock('5.5.5.5'));
    }

    public function test_delay_zero_para_score_baixo(): void
    {
        $this->scorer->add('1.2.3.4', ThreatScorer::THRESHOLD_DELAY - 1);
        $this->assertSame(0, $this->scorer->delaySeconds('1.2.3.4'));
    }

    public function test_delay_2s_para_score_medio(): void
    {
        $this->scorer->add('1.2.3.4', ThreatScorer::THRESHOLD_DELAY);
        $this->assertSame(2, $this->scorer->delaySeconds('1.2.3.4'));
    }

    public function test_delay_5s_para_score_alto(): void
    {
        $this->scorer->add('1.2.3.4', 100);
        $this->assertSame(5, $this->scorer->delaySeconds('1.2.3.4'));
    }

    public function test_delay_10s_para_score_bloqueio(): void
    {
        $this->scorer->add('1.2.3.4', ThreatScorer::THRESHOLD_BLOCK);
        $this->assertSame(10, $this->scorer->delaySeconds('1.2.3.4'));
    }

    public function test_constantes_de_pontuacao_corretas(): void
    {
        $this->assertSame(100, ThreatScorer::SCORE_HONEYPOT);
        $this->assertSame(50,  ThreatScorer::SCORE_MALICIOUS_UA);
        $this->assertSame(30,  ThreatScorer::SCORE_LOGIN_FAIL);
        $this->assertSame(20,  ThreatScorer::SCORE_RATE_LIMIT);
        $this->assertSame(15,  ThreatScorer::SCORE_NO_UA);
        $this->assertSame(50,  ThreatScorer::THRESHOLD_DELAY);
        $this->assertSame(150, ThreatScorer::THRESHOLD_BLOCK);
    }

    public function test_add_retorna_score_total(): void
    {
        $score = $this->scorer->add('9.9.9.9', 30);
        $this->assertSame(30, $score);
        $score = $this->scorer->add('9.9.9.9', 20);
        $this->assertSame(50, $score);
    }
}
