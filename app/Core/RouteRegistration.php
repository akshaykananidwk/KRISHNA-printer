<?php
declare(strict_types=1);

namespace App\Core;

/** Fluent handle returned by Router::get()/post()/... for naming and middleware. */
final class RouteRegistration
{
    public function __construct(
        private Router $router,
        private string $method,
        private int $index
    ) {
    }

    public function name(string $name): self
    {
        $this->router->nameRoute($this->method, $this->index, $name);
        return $this;
    }

    /** @param string|array<int,string> $middleware */
    public function middleware(string|array $middleware): self
    {
        $this->router->addRouteMiddleware($this->method, $this->index, (array) $middleware);
        return $this;
    }
}
