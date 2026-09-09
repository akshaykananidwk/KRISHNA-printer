<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish view over the incoming HTTP request. All superglobal access in
 * the application funnels through this object so input handling is consistent
 * and testable.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $attributes = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,mixed> $server
     * @param array<string,mixed> $cookies
     * @param array<string,mixed> $files
     */
    public function __construct(
        private array $query = [],
        private array $body = [],
        private array $server = [],
        private array $cookies = [],
        private array $files = [],
        private string $rawBody = ''
    ) {
    }

    public static function capture(): self
    {
        $raw = '';
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $body = $_POST;

        if (str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && empty($_POST)) {
            $raw = (string) file_get_contents('php://input');
        }

        return new self($_GET, $body, $_SERVER, $_COOKIE, $_FILES, $raw);
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        // Support method spoofing from HTML forms (_method=PUT/PATCH/DELETE).
        if ($method === 'POST') {
            $spoofed = strtoupper((string) ($this->body['_method'] ?? ''));
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }
        return $method;
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        // Strip a sub-directory install prefix so the app works in /subdir too.
        $base = $this->basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** Directory the front controller is mounted under, e.g. "" or "/print-app". */
    public function basePath(): string
    {
        $script = (string) ($this->server['SCRIPT_NAME'] ?? '');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return $dir === '/' ? '' : $dir;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<int,mixed> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        // CONTENT_TYPE / CONTENT_LENGTH are not HTTP_-prefixed in CGI.
        $bare = strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$bare]) ? (string) $this->server[$bare] : $default;
    }

    public function bearerToken(): ?string
    {
        $header = (string) $this->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? $default;
        return $value === null ? null : (string) $value;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        return isset($this->files[$key]) && is_array($this->files[$key]) ? $this->files[$key] : null;
    }

    /**
     * Normalise PHP's awkward multi-file $_FILES shape into a flat list.
     *
     * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public function normalisedFiles(string $key): array
    {
        $entry = $this->file($key);
        if ($entry === null) {
            return [];
        }
        if (!is_array($entry['name'])) {
            return [[
                'name' => (string) $entry['name'],
                'type' => (string) ($entry['type'] ?? ''),
                'tmp_name' => (string) ($entry['tmp_name'] ?? ''),
                'error' => (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($entry['size'] ?? 0),
            ]];
        }
        $out = [];
        foreach (array_keys($entry['name']) as $i) {
            $out[] = [
                'name' => (string) $entry['name'][$i],
                'type' => (string) ($entry['type'][$i] ?? ''),
                'tmp_name' => (string) ($entry['tmp_name'][$i] ?? ''),
                'error' => (int) ($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($entry['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest'
            || str_contains((string) $this->header('Accept', ''), 'application/json');
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($this->server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        // Trusted only when the deployment declares it sits behind a proxy.
        if (Config::get('app.trust_proxy', false)) {
            $proto = strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($proto === 'https') {
                return true;
            }
        }
        return false;
    }

    public function ip(): string
    {
        if (Config::get('app.trust_proxy', false)) {
            $forwarded = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost');
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @param array<string,mixed> $params */
    public function setRouteParams(array $params): void
    {
        $this->attributes['route_params'] = $params;
    }

    public function routeParam(string $key, mixed $default = null): mixed
    {
        $params = $this->attributes['route_params'] ?? [];
        return is_array($params) ? ($params[$key] ?? $default) : $default;
    }
}
