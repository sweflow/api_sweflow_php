<?php

namespace Tests\Unit;

use Tests\TestCase;
use Src\Kernel\Http\Response\Response;

class ResponseTest extends TestCase
{
    public function test_json_retorna_status_200_por_padrao(): void
    {
        $res = Response::json(['ok' => true]);
        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_json_retorna_status_customizado(): void
    {
        $res = Response::json(['error' => 'not found'], 404);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_json_define_content_type(): void
    {
        $res = Response::json([]);
        $headers = $res->getHeaders();
        $this->assertStringContainsString('application/json', $headers['Content-Type']);
    }

    public function test_body_array_e_preservado(): void
    {
        $data = ['status' => 'success', 'items' => [1, 2, 3]];
        $res  = Response::json($data);
        $this->assertSame($data, $res->getBody());
    }

    public function test_html_retorna_content_type_html(): void
    {
        $res = Response::html('<h1>Oi</h1>');
        $this->assertStringContainsString('text/html', $res->getHeaders()['Content-Type']);
    }

    public function test_with_header_e_imutavel(): void
    {
        $res1 = Response::json([]);
        $res2 = $res1->withHeader('X-Custom', 'value');
        $this->assertNotSame($res1, $res2);
        $this->assertArrayNotHasKey('X-Custom', $res1->getHeaders());
        $this->assertSame('value', $res2->getHeaders()['X-Custom']);
    }

    public function test_security_headers_presentes(): void
    {
        $res     = Response::json([]);
        $headers = $res->getHeaders();
        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertArrayHasKey('X-Frame-Options', $headers);
        $this->assertArrayHasKey('Content-Security-Policy', $headers);
    }
}
