<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Src\Kernel\Http\Request\Request;

abstract class TestCase extends BaseTestCase
{
    protected function makeRequest(
        string $method = 'GET',
        string $path = '/',
        array $body = [],
        array $query = [],
        array $headers = [],
        array $attributes = []
    ): Request {
        $request = new Request($body, $query, $headers, $method, $path);
        foreach ($attributes as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }
        return $request;
    }

    protected function assertJsonStatus(mixed $response, string $status): void
    {
        $body = $response->getBody();
        $data = is_array($body) ? $body : json_decode(json_encode($body), true);
        $this->assertSame($status, $data['status'] ?? null, "Expected status '{$status}', got: " . json_encode($data));
    }

    protected function assertStatusCode(mixed $response, int $code): void
    {
        $this->assertSame($code, $response->getStatusCode(), "Expected HTTP {$code}, got {$response->getStatusCode()}");
    }
}
