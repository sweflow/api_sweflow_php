<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Kernel\Contracts\AuthorizationInterface;
use Src\Kernel\Contracts\IdentityFactoryInterface;

/**
 * Testa que não há dependências circulares no container.
 * 
 * Previne regressão do bug de memory exhaustion causado por loop infinito
 * entre AuthorizationInterface → AuthContextInterface → IdentityFactoryInterface.
 */
final class ContainerCircularDependencyTest extends TestCase
{
    /**
     * Garante que DefaultIdentityFactory aceita Closure e resolve corretamente.
     * 
     * Este é o teste principal que previne a dependência circular.
     * A Closure permite lazy resolution, quebrando o ciclo.
     */
    public function testDefaultIdentityFactoryAceitaClosure(): void
    {
        $called = false;
        $mockAuthorization = $this->createMock(AuthorizationInterface::class);

        $closure = static function () use (&$called, $mockAuthorization) {
            $called = true;
            return $mockAuthorization;
        };

        $factory = new \Src\Kernel\Auth\DefaultIdentityFactory($closure);

        // Closure não deve ser chamada ainda (lazy)
        $this->assertFalse($called, 'Closure não deve ser chamada no construtor');

        // Cria um mock de payload
        $payload = $this->createMock(\Src\Kernel\Contracts\TokenPayloadInterface::class);
        $payload->method('getSubject')->willReturn('user-123');
        $payload->method('getRole')->willReturn('user');
        $payload->method('isSignedWithApiSecret')->willReturn(false);

        // Cria um mock de usuário ativo
        $user = new class {
            public function isAtivo(): bool { return true; }
            public function getAuthId(): string { return 'user-123'; }
            public function getAuthRole(): string { return 'user'; }
        };

        // Chama forUser — agora a Closure deve ser executada
        $identity = $factory->forUser($user, $payload);

        $this->assertTrue($called, 'Closure deve ser chamada em forUser()');
        $this->assertInstanceOf(\Src\Kernel\Contracts\AuthIdentityInterface::class, $identity);
    }

    /**
     * Garante que DefaultIdentityFactory cacheia o resultado da Closure.
     * 
     * Evita múltiplas resoluções do container, melhorando performance.
     */
    public function testDefaultIdentityFactoryCacheiaResultadoClosure(): void
    {
        $callCount = 0;
        $mockAuthorization = $this->createMock(AuthorizationInterface::class);

        $closure = static function () use (&$callCount, $mockAuthorization) {
            $callCount++;
            return $mockAuthorization;
        };

        $factory = new \Src\Kernel\Auth\DefaultIdentityFactory($closure);

        $payload = $this->createMock(\Src\Kernel\Contracts\TokenPayloadInterface::class);
        $payload->method('getSubject')->willReturn('user-123');
        $payload->method('getRole')->willReturn('user');
        $payload->method('isSignedWithApiSecret')->willReturn(false);

        $user = new class {
            public function isAtivo(): bool { return true; }
            public function getAuthId(): string { return 'user-123'; }
            public function getAuthRole(): string { return 'user'; }
        };

        // Chama forUser 3 vezes
        $factory->forUser($user, $payload);
        $factory->forUser($user, $payload);
        $factory->forUser($user, $payload);

        // Closure deve ser chamada apenas 1 vez (cache interno)
        $this->assertSame(1, $callCount, 'Closure deve ser chamada apenas uma vez (cache)');
    }

    /**
     * Garante que DefaultIdentityFactory ainda aceita AuthorizationInterface diretamente.
     * 
     * Mantém compatibilidade com código existente.
     */
    public function testDefaultIdentityFactoryAceitaAuthorizationInterface(): void
    {
        $mockAuthorization = $this->createMock(AuthorizationInterface::class);
        $factory = new \Src\Kernel\Auth\DefaultIdentityFactory($mockAuthorization);

        $payload = $this->createMock(\Src\Kernel\Contracts\TokenPayloadInterface::class);
        $payload->method('getSubject')->willReturn('user-123');
        $payload->method('getRole')->willReturn('user');
        $payload->method('isSignedWithApiSecret')->willReturn(false);

        $user = new class {
            public function isAtivo(): bool { return true; }
            public function getAuthId(): string { return 'user-123'; }
            public function getAuthRole(): string { return 'user'; }
        };

        $identity = $factory->forUser($user, $payload);

        $this->assertInstanceOf(\Src\Kernel\Contracts\AuthIdentityInterface::class, $identity);
    }

    /**
     * Garante que DefaultIdentityFactory aceita null.
     * 
     * Permite criar factory sem authorization quando não necessário.
     */
    public function testDefaultIdentityFactoryAceitaNull(): void
    {
        $factory = new \Src\Kernel\Auth\DefaultIdentityFactory(null);

        $payload = $this->createMock(\Src\Kernel\Contracts\TokenPayloadInterface::class);
        $payload->method('getSubject')->willReturn('user-123');
        $payload->method('getRole')->willReturn('user');
        $payload->method('isSignedWithApiSecret')->willReturn(false);

        $user = new class {
            public function isAtivo(): bool { return true; }
            public function getAuthId(): string { return 'user-123'; }
            public function getAuthRole(): string { return 'user'; }
        };

        $identity = $factory->forUser($user, $payload);

        $this->assertInstanceOf(\Src\Kernel\Contracts\AuthIdentityInterface::class, $identity);
    }

    /**
     * Garante que Closure que retorna null não causa erro.
     */
    public function testClosureQueRetornaNullNaoCausaErro(): void
    {
        $closure = static fn() => null;
        $factory = new \Src\Kernel\Auth\DefaultIdentityFactory($closure);

        $payload = $this->createMock(\Src\Kernel\Contracts\TokenPayloadInterface::class);
        $payload->method('getSubject')->willReturn('user-123');
        $payload->method('getRole')->willReturn('user');
        $payload->method('isSignedWithApiSecret')->willReturn(false);

        $user = new class {
            public function isAtivo(): bool { return true; }
            public function getAuthId(): string { return 'user-123'; }
            public function getAuthRole(): string { return 'user'; }
        };

        $identity = $factory->forUser($user, $payload);

        $this->assertInstanceOf(\Src\Kernel\Contracts\AuthIdentityInterface::class, $identity);
    }
}
