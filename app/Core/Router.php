<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Route table with named parameters, per-route middleware and route groups.
 * Compiled patterns are cached per-process; with fewer than ~150 routes the
 * linear match is far cheaper than the cost of a compiled dispatcher.
 */
final class Router
{
    /** @var array<string,array<int,array{pattern:string,regex:string,params:array<int,string>,handler:mixed,middleware:array<int,string>,name:?string}>> */
    private array $routes = [];

    /** @var array<string,string> */
    private array $namedRoutes = [];

    /** @var array<int,array{prefix:string,middleware:array<int,string>}> */
    private array $groupStack = [];

    public function get(string $pattern, mixed $handler): RouteRegistration
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): RouteRegistration
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): RouteRegistration
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): RouteRegistration
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): RouteRegistration
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    /** @param array{prefix?:string,middleware?:array<int,string>} $attributes */
    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = [
            'prefix' => (string) ($attributes['prefix'] ?? ''),
            'middleware' => (array) ($attributes['middleware'] ?? []),
        ];
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $pattern, mixed $handler): RouteRegistration
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $middleware = array_merge($middleware, $group['middleware']);
        }

        $full = '/' . trim($prefix . $pattern, '/');
        if ($full !== '/') {
            $full = rtrim($full, '/');
        }

        [$regex, $params] = $this->compile($full);

        $route = [
            'pattern' => $full,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middleware' => $middleware,
            'name' => null,
        ];

        $this->routes[$method][] = $route;
        $index = array_key_last($this->routes[$method]);

        return new RouteRegistration($this, $method, (int) $index);
    }

    /**
     * Matches one `{name}` or `{name:constraint}` placeholder.
     *
     * The constraint alternation tolerates one level of braces so a quantifier
     * such as `{id:[a-f0-9]{32}}` is read as a whole rather than being cut at
     * the first closing brace.
     */
    private const PLACEHOLDER = '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})*))?\}/';

    /** @return array{0:string,1:array<int,string>} */
    private function compile(string $pattern): array
    {
        $params = [];
        $regex = '';
        $offset = 0;

        // Quote only the literal segments between placeholders.
        //
        // Quoting the whole pattern first — as this once did — also escapes the
        // metacharacters *inside* a constraint, so `{id:\d+}` became the regex
        // `(\\d\+\)`: invalid, never matching, and silently answering 404 for
        // every parameterised route. Splitting on placeholders keeps each
        // constraint verbatim.
        preg_match_all(self::PLACEHOLDER, $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $m) {
            $start = (int) $m[0][1];
            $regex .= preg_quote(substr($pattern, $offset, $start - $offset), '#');

            $params[] = $m[1][0];
            $constraint = isset($m[2]) && $m[2][1] !== -1 && $m[2][0] !== '' ? $m[2][0] : '[^/]+';
            $regex .= '(' . $constraint . ')';

            $offset = $start + strlen($m[0][0]);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');
        $regex = '#^' . $regex . '$#';

        // Fail at registration rather than at request time. A constraint that
        // does not compile, or that adds capturing groups of its own (which
        // would shift every parameter index), is a routing-table bug and must
        // not degrade into a mystery 404.
        if (@preg_match($regex, '') === false) {
            throw new \InvalidArgumentException(
                'Route pattern "' . $pattern . '" does not compile to a valid regular expression.'
            );
        }
        if (preg_match_all('/(?<!\\\\)\((?!\?)/', $regex) !== count($params)) {
            throw new \InvalidArgumentException(
                'Route pattern "' . $pattern . '" has a constraint containing a capturing group. '
                . 'Use a non-capturing group "(?:...)" so parameter positions stay aligned.'
            );
        }

        return [$regex, $params];
    }

    /** @internal used by RouteRegistration */
    public function nameRoute(string $method, int $index, string $name): void
    {
        $this->routes[$method][$index]['name'] = $name;
        $this->namedRoutes[$name] = $this->routes[$method][$index]['pattern'];
    }

    /** @internal used by RouteRegistration */
    public function addRouteMiddleware(string $method, int $index, array $middleware): void
    {
        $this->routes[$method][$index]['middleware'] = array_merge(
            $this->routes[$method][$index]['middleware'],
            $middleware
        );
    }

    /**
     * @return array{handler:mixed,params:array<string,string>,middleware:array<int,string>}
     * @throws HttpException 404 when nothing matches, 405 when only the method differs
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $candidates = $this->routes[$method] ?? [];

        foreach ($candidates as $route) {
            if (preg_match($route['regex'], $path, $matches) === 1) {
                array_shift($matches);
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? '';
                }
                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'middleware' => $route['middleware'],
                ];
            }
        }

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
            throw new HttpException(405, 'Method Not Allowed', ['Allow' => implode(', ', array_unique($allowed))]);
        }

        throw new HttpException(404, 'Not Found');
    }

    /** @param array<string,string|int> $params */
    public function route(string $name, array $params = []): string
    {
        $pattern = $this->namedRoutes[$name] ?? null;
        if ($pattern === null) {
            throw new \InvalidArgumentException('Unknown route name: ' . $name);
        }
        $url = preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $m) use ($params): string {
                if (!array_key_exists($m[1], $params)) {
                    throw new \InvalidArgumentException('Missing route parameter: ' . $m[1]);
                }
                return rawurlencode((string) $params[$m[1]]);
            },
            $pattern
        );
        return (string) $url;
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    public function all(): array
    {
        return $this->routes;
    }
}
