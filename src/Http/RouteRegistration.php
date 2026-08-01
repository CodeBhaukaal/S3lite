<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Fluent handle returned by Router::get()/post()/... for chaining.
 */
final class RouteRegistration
{
    public function __construct(
        private Router $router,
        private string $method,
        private string $index
    ) {
    }

    public function middleware(string ...$middleware): self
    {
        $this->router->attachMiddleware($this->method, $this->index, $middleware);

        return $this;
    }

    public function name(string $name): self
    {
        $this->router->nameRoute($this->method, $this->index, $name);

        return $this;
    }
}
