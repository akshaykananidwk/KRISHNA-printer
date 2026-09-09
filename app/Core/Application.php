<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Http\Middleware\MiddlewareInterface;

/**
 * Application kernel: boots configuration, resolves the route, runs the
 * middleware pipeline and converts anything thrown into a proper HTTP response.
 */
final class Application
{
    private Router $router;

    private Container $container;

    /** @var array<string,class-string<MiddlewareInterface>> */
    private array $middlewareAliases = [];

    /** @var array<int,class-string<MiddlewareInterface>> */
    private array $globalMiddleware = [];

    private bool $booted = false;

    public function __construct(private string $basePath)
    {
        $this->container = Container::instance();
        $this->router = new Router();
        $this->container->set(Router::class, $this->router);
        $this->container->set(self::class, $this);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        Config::load($this->basePath . '/config');
        Config::set('app.base_path', $this->basePath);

        Logger::configure((string) Config::get('app.log_path', $this->basePath . '/logs'));
        View::setPath((string) Config::get('app.view_path', $this->basePath . '/resources/views'));

        date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));

        $this->container->bind(Database::class, static fn () => Database::instance());
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(string $append = ''): string
    {
        return $this->basePath . ($append === '' ? '' : '/' . ltrim($append, '/'));
    }

    /** @param array<string,class-string<MiddlewareInterface>> $aliases */
    public function registerMiddleware(array $aliases): void
    {
        $this->middlewareAliases = array_merge($this->middlewareAliases, $aliases);
    }

    /** @param array<int,class-string<MiddlewareInterface>> $middleware */
    public function setGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    public function handle(Request $request): Response
    {
        $this->boot();

        try {
            $match = $this->router->match($request->method(), $request->path());
            $request->setRouteParams($match['params']);

            $stack = array_merge($this->globalMiddleware, $this->resolveAliases($match['middleware']));

            $handler = function (Request $request) use ($match): Response {
                return $this->invoke($match['handler'], $request);
            };

            foreach (array_reverse($stack) as $middlewareClass) {
                $next = $handler;
                $handler = function (Request $request) use ($middlewareClass, $next): Response {
                    /** @var MiddlewareInterface $middleware */
                    $middleware = $this->container->get($middlewareClass);
                    return $middleware->handle($request, $next);
                };
            }

            return $handler($request);
        } catch (\Throwable $e) {
            return $this->renderException($request, $e);
        }
    }

    /** @param array<int,string> $aliases @return array<int,class-string<MiddlewareInterface>> */
    private function resolveAliases(array $aliases): array
    {
        $resolved = [];
        foreach ($aliases as $alias) {
            $class = $this->middlewareAliases[$alias] ?? $alias;
            if (!class_exists($class)) {
                throw new \RuntimeException('Unknown middleware: ' . $alias);
            }
            $resolved[] = $class;
        }
        return $resolved;
    }

    private function invoke(mixed $handler, Request $request): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request);
        } elseif (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $controller = $this->container->get($class);
            if (!method_exists($controller, $method)) {
                throw new \RuntimeException("Controller action not found: $class@$method");
            }
            $result = $controller->{$method}($request);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = is_object($class) ? $class : $this->container->get($class);
            $result = $controller->{$method}($request);
        } else {
            throw new \RuntimeException('Invalid route handler.');
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }

    private function renderException(Request $request, \Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->statusCode() : 500;
        $headers = $e instanceof HttpException ? $e->headers() : [];

        if ($status >= 500) {
            Logger::error('Unhandled exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'path' => $request->path(),
                'trace' => Config::get('app.debug', false) ? $e->getTraceAsString() : null,
            ]);
        }

        $debug = (bool) Config::get('app.debug', false);
        $message = $status >= 500 && !$debug
            ? 'An unexpected error occurred. Please try again.'
            : $e->getMessage();

        if ($request->isAjax() || str_starts_with($request->path(), '/api/')) {
            $payload = ['success' => false, 'error' => $message, 'status' => $status];
            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }
            if ($debug && $status >= 500) {
                $payload['debug'] = [
                    'exception' => $e::class,
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ];
            }
            $response = Response::json($payload, $status);
        } else {
            try {
                $html = View::render('errors/error', [
                    'status' => $status,
                    'message' => $message,
                    'debug' => $debug ? $e : null,
                ]);
            } catch (\Throwable) {
                $html = '<!doctype html><meta charset="utf-8"><title>Error ' . $status . '</title>'
                    . '<h1>Error ' . $status . '</h1><p>' . View::e($message) . '</p>';
            }
            $response = Response::html($html, $status);
        }

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }
}
