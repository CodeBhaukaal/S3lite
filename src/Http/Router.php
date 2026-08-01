<?php
declare(strict_types=1);

namespace App\Http;

use App\Http\Exceptions\HttpException;

/**
 * Regex-compiled router with group prefixes and per-route middleware.
 */
final class Router
{
    /** @var array<string, list<array{pattern:string, regex:string, params:list<string>, handler:mixed, middleware:list<string>, name:?string}>> */
    private array $routes = [];
    private array $named = [];
    private array $groupStack = [];

    public function get(string $path, mixed $handler): RouteRegistration { return $this->add('GET', $path, $handler); }
    public function post(string $path, mixed $handler): RouteRegistration { return $this->add('POST', $path, $handler); }
    public function put(string $path, mixed $handler): RouteRegistration { return $this->add('PUT', $path, $handler); }
    public function patch(string $path, mixed $handler): RouteRegistration { return $this->add('PATCH', $path, $handler); }
    public function delete(string $path, mixed $handler): RouteRegistration { return $this->add('DELETE', $path, $handler); }
    public function options(string $path, mixed $handler): RouteRegistration { return $this->add('OPTIONS', $path, $handler); }

    public function any(array $methods, string $path, mixed $handler): RouteRegistration
    {
        $registration = null;
        foreach ($methods as $method) {
            $registration = $this->add(strtoupper($method), $path, $handler);
        }

        return $registration ?? new RouteRegistration($this, 'GET', $path);
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $path, mixed $handler): RouteRegistration
    {
        $prefix = '';
        $middleware = [];

        foreach ($this->groupStack as $group) {
            $prefix .= rtrim((string) ($group['prefix'] ?? ''), '/');
            $middleware = array_merge($middleware, (array) ($group['middleware'] ?? []));
        }

        $full = $prefix . '/' . trim($path, '/');
        $full = '/' . trim($full, '/');
        if ($full === '/') {
            $full = '/';
        }

        [$regex, $params] = $this->compile($full);

        $this->routes[$method][] = [
            'pattern'    => $full,
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
            'name'       => null,
        ];

        return new RouteRegistration($this, $method, (string) array_key_last($this->routes[$method]));
    }

    /**
     * @return array{0:string, 1:list<string>}
     */
    private function compile(string $path): array
    {
        $params = [];

        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';

                return '(' . $constraint . ')';
            },
            $path
        ) ?? $path;

        return ['#^' . $regex . '$#u', $params];
    }

    public function attachMiddleware(string $method, string $index, array $middleware): void
    {
        if (isset($this->routes[$method][(int) $index])) {
            $this->routes[$method][(int) $index]['middleware'] = array_merge(
                $this->routes[$method][(int) $index]['middleware'],
                $middleware
            );
        }
    }

    public function nameRoute(string $method, string $index, string $name): void
    {
        if (isset($this->routes[$method][(int) $index])) {
            $this->routes[$method][(int) $index]['name'] = $name;
            $this->named[$name] = $this->routes[$method][(int) $index]['pattern'];
        }
    }

    public function route(string $name, array $params = []): string
    {
        $pattern = $this->named[$name] ?? '/';

        foreach ($params as $key => $value) {
            $pattern = preg_replace('#\{' . preg_quote((string) $key, '#') . '(?::[^}]+)?\}#', (string) $value, $pattern) ?? $pattern;
        }

        return $pattern;
    }

    /**
     * @return array{handler:mixed, params:array<string,string>, middleware:list<string>}
     */
    public function match(string $method, string $path): array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                array_shift($matches);

                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? '';
                }

                return [
                    'handler'    => $route['handler'],
                    'params'     => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }

        // Distinguish "no such path" from "wrong verb".
        $allowed = [];
        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $otherMethod;
                    break;
                }
            }
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'Method Not Allowed', 'method_not_allowed', ['allow' => implode(', ', array_unique($allowed))]);
        }

        throw new HttpException(404, 'Not Found', 'not_found');
    }

    public function all(): array
    {
        return $this->routes;
    }
}
