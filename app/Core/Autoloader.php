<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PSR-4 autoloader. Deliberately dependency-free so the application
 * can be deployed to shared hosting by upload alone (no composer required).
 */
final class Autoloader
{
    /** @var array<string,string> prefix => base directory */
    private array $prefixes = [];

    public function addNamespace(string $prefix, string $baseDir): void
    {
        $this->prefixes[trim($prefix, '\\') . '\\'] = rtrim($baseDir, '/\\') . '/';
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'load']);
    }

    public function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
}
