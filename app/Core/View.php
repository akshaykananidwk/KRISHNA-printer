<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP template renderer with layout inheritance.
 *
 * Every value reaching a template is escaped with e() at the point of output;
 * there is no "raw by default" path. Templates are stored outside the web root.
 */
final class View
{
    private static string $path = '';

    /** @var array<string,mixed> */
    private static array $shared = [];

    /** @var array<string,string> */
    private array $sections = [];

    private ?string $layout = null;

    /** @var array<int,string> */
    private array $sectionStack = [];

    public static function setPath(string $path): void
    {
        self::$path = rtrim($path, '/');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        return (new self())->doRender($template, $data);
    }

    /** @param array<string,mixed> $data */
    private function doRender(string $template, array $data): string
    {
        $content = $this->capture($template, $data);

        // Walk up the layout chain (a layout may itself extend another).
        $guard = 0;
        while ($this->layout !== null && $guard++ < 10) {
            $layout = $this->layout;
            $this->layout = null;
            $this->sections['content'] = $this->sections['content'] ?? $content;
            $content = $this->capture($layout, $data);
        }

        return $content;
    }

    /** @param array<string,mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = self::$path . '/' . str_replace(['..', '\\'], '', trim($template, '/')) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        $scope = array_merge(self::$shared, $data);
        extract($scope, EXTR_SKIP);
        /** @var View $view */
        $view = $this;

        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function start(string $section): void
    {
        $this->sectionStack[] = $section;
        ob_start();
    }

    public function end(): void
    {
        $section = array_pop($this->sectionStack);
        $buffer = (string) ob_get_clean();
        if ($section !== null) {
            $this->sections[$section] = $buffer;
        }
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && trim($this->sections[$name]) !== '';
    }

    /** Render a partial inside the current template. */
    public function include(string $template, array $data = []): string
    {
        return $this->capture($template, array_merge(self::$shared, $data));
    }

    /** Escape for HTML text and attribute contexts. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Escape for embedding inside a <script> block as a JS literal. */
    public static function js(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        return $json === false ? 'null' : $json;
    }

    /** Escape a value used inside a URL query string. */
    public static function url(mixed $value): string
    {
        return rawurlencode((string) ($value ?? ''));
    }
}
