<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Minimal router. Patterns may contain numeric placeholders such as {id} or
 * {itemId}, passed to the handler as named int arguments, and {token}
 * (URL-safe random string of 40-100 characters), passed as a string.
 * Access levels: 'guest' (anyone), 'auth' (logged in), 'admin'.
 */
final class Router
{
    /** @var list<array{string,string,array,string}> */
    private array $routes = [];

    public function get(string $pattern, array $handler, string $access = 'auth'): void
    {
        $this->routes[] = ['GET', $pattern, $handler, $access];
    }

    public function post(string $pattern, array $handler, string $access = 'auth'): void
    {
        $this->routes[] = ['POST', $pattern, $handler, $access];
    }

    /** @return array{array,string,array<string,int>} handler, access, params */
    public function match(string $method, string $path): array
    {
        $methodMismatch = false;
        foreach ($this->routes as [$m, $pattern, $handler, $access]) {
            $quoted = str_replace('\\{token\\}', '(?P<token>[A-Za-z0-9_-]{40,100})', preg_quote($pattern, '#'));
            $regex = '#^' . preg_replace('#\\\\\{([a-zA-Z]+)\\\\\}#', '(?P<$1>\d{1,10})', $quoted) . '$#';
            if (!preg_match($regex, $path, $mm)) {
                continue;
            }
            if ($m !== $method) {
                $methodMismatch = true;
                continue;
            }
            $params = [];
            foreach ($mm as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $k === 'token' ? $v : (int) $v;
                }
            }
            return [$handler, $access, $params];
        }
        throw new HttpException($methodMismatch ? 405 : 404, $methodMismatch ? 'Method not allowed.' : 'Page not found.');
    }
}
