<?php

namespace src\Routes;

class Route
{
    public static function get($uri, $handler, $middleware = null)
    {
        global $routes;
        $routes[] = [
            'method' => 'GET',
            'uri' => $uri,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }

    public static function post($uri, $handler, $middleware = null)
    {
        global $routes;
        $routes[] = [
            'method' => 'POST',
            'uri' => $uri,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }

    public static function put($uri, $handler, $middleware = null)
    {
        global $routes;
        $routes[] = [
            'method' => 'PUT',
            'uri' => $uri,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }

    public static function delete($uri, $handler, $middleware = null)
    {
        global $routes;
        $routes[] = [
            'method' => 'DELETE',
            'uri' => $uri,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }

    public static function patch($uri, $handler, $middleware = null)
    {
        global $routes;
        $routes[] = [
            'method' => 'PATCH',
            'uri' => $uri,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }
}
