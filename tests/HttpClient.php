<?php
declare(strict_types=1);

namespace Tests;

/**
 * A cookie-aware HTTP client so the tests exercise the application the way a
 * real browser does — through the front controller, the middleware stack, the
 * session and CSRF — rather than by calling controllers directly. A test that
 * bypasses the middleware would not prove the middleware works.
 */
final class HttpClient
{
    /** @var array<string,string> */
    private array $cookies = [];

    private string $csrfToken = '';

    /**
     * curl's own cookie jar.
     *
     * Needed rather than a static Cookie header because the application
     * regenerates the session id on sign-in (correct anti-fixation
     * behaviour). Only curl's cookie engine applies a Set-Cookie *during* a
     * redirect chain; a static header would keep sending the old, now-destroyed
     * session id to the redirect target — exactly what a browser would not do.
     */
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'kpms-cookies-');
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>,json:array<string,mixed>|null}
     */
    public function request(
        string $method,
        string $path,
        array|string|null $body = null,
        array $headers = [],
        bool $followRedirects = false
    ): array {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;

        $method = strtoupper($method);

        $curl = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 5,
        ];

        // Use CURLOPT_POST for POST rather than CURLOPT_CUSTOMREQUEST: a custom
        // method sticks across a redirect, so curl would re-POST to the
        // redirect target instead of following it with GET the way a browser
        // does. That difference silently breaks every post-redirect-get flow.
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        // Let curl own the cookie jar so Set-Cookie is honoured mid-redirect.
        $options[CURLOPT_COOKIEFILE] = $this->cookieJar;
        $options[CURLOPT_COOKIEJAR] = $this->cookieJar;

        if ($body !== null) {
            if (is_array($body)) {
                // Detect a multipart upload: any value that is a CURLFile.
                $isMultipart = false;
                array_walk_recursive($body, static function ($value) use (&$isMultipart): void {
                    if ($value instanceof \CURLFile) {
                        $isMultipart = true;
                    }
                });

                $options[CURLOPT_POSTFIELDS] = $isMultipart ? $body : http_build_query($body);
                if (!$isMultipart) {
                    $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
                }
            } else {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        if ($requestHeaders !== []) {
            $options[CURLOPT_HTTPHEADER] = $requestHeaders;
        }

        curl_setopt_array($curl, $options);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            return ['status' => 0, 'body' => 'cURL error: ' . $error, 'headers' => [], 'json' => null];
        }

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);

        $parsedHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if ($name === 'set-cookie') {
                if (preg_match('/^([^=]+)=([^;]*)/', $value, $m) === 1) {
                    if ($m[2] === '' || str_contains($value, 'Max-Age=0')) {
                        unset($this->cookies[$m[1]]);
                    } else {
                        $this->cookies[$m[1]] = $m[2];
                    }
                }
                $parsedHeaders['set-cookie'] = ($parsedHeaders['set-cookie'] ?? '') . $value . "\n";
                continue;
            }

            $parsedHeaders[$name] = $value;
        }

        // Keep the CSRF token current so form posts do not each need to scrape it.
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $responseBody, $m) === 1) {
            $this->csrfToken = html_entity_decode($m[1], ENT_QUOTES);
        } elseif (preg_match('/name="_csrf_token" value="([^"]+)"/', $responseBody, $m) === 1) {
            $this->csrfToken = html_entity_decode($m[1], ENT_QUOTES);
        }

        $json = null;
        $trimmed = ltrim($responseBody);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return ['status' => $status, 'body' => $responseBody, 'headers' => $parsedHeaders, 'json' => $json];
    }

    /** @return array{status:int,body:string,headers:array<string,string>,json:array<string,mixed>|null} */
    public function get(string $path, array $headers = [], bool $follow = false): array
    {
        return $this->request('GET', $path, null, $headers, $follow);
    }

    /**
     * POST a form, injecting the current CSRF token automatically.
     *
     * @param array<string,mixed> $fields
     */
    public function post(string $path, array $fields = [], array $headers = [], bool $follow = false): array
    {
        if (!isset($fields['_csrf_token']) && $this->csrfToken !== '') {
            $fields['_csrf_token'] = $this->csrfToken;
        }
        $headers['X-Requested-With'] = $headers['X-Requested-With'] ?? 'XMLHttpRequest';
        if ($this->csrfToken !== '') {
            $headers['X-CSRF-Token'] = $this->csrfToken;
        }
        return $this->request('POST', $path, $fields, $headers, $follow);
    }

    /** POST a JSON body. @param array<string,mixed> $payload */
    public function postJson(string $path, array $payload, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';
        $headers['X-Requested-With'] = 'XMLHttpRequest';
        if ($this->csrfToken !== '') {
            $headers['X-CSRF-Token'] = $this->csrfToken;
        }
        return $this->request('POST', $path, json_encode($payload, JSON_THROW_ON_ERROR), $headers);
    }

    /** POST a form the way a browser would (no XHR header), following redirects. */
    public function submitForm(string $path, array $fields = []): array
    {
        if (!isset($fields['_csrf_token']) && $this->csrfToken !== '') {
            $fields['_csrf_token'] = $this->csrfToken;
        }
        return $this->request('POST', $path, $fields, [], true);
    }

    /** @param array<string,mixed> $fields */
    public function upload(string $path, array $fields, array $headers = []): array
    {
        if (!isset($fields['_csrf_token']) && $this->csrfToken !== '') {
            $fields['_csrf_token'] = $this->csrfToken;
        }
        $headers['X-Requested-With'] = 'XMLHttpRequest';
        if ($this->csrfToken !== '') {
            $headers['X-CSRF-Token'] = $this->csrfToken;
        }
        return $this->request('POST', $path, $fields, $headers);
    }

    public function csrfToken(): string
    {
        return $this->csrfToken;
    }

    public function setCsrfToken(string $token): void
    {
        $this->csrfToken = $token;
    }

    /** Start a fresh visitor with no cookies — used to test session scoping. */
    public function reset(): void
    {
        $this->cookies = [];
        $this->csrfToken = '';
        @file_put_contents($this->cookieJar, '');
    }

    /** The jar path, for a raw curl call that must share this client's session. */
    public function cookieJar(): string
    {
        return $this->cookieJar;
    }

    /** @return array<string,string> */
    public function cookies(): array
    {
        return $this->cookies;
    }
}
