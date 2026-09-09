<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Dependency-free daily-rotating file logger with automatic redaction of
 * anything that looks like a secret. Never logs raw passwords or tokens.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    private static ?string $directory = null;

    private static string $channel = 'app';

    /** @var array<string,int> */
    private const LEVELS = [
        self::DEBUG => 10,
        self::INFO => 20,
        self::WARNING => 30,
        self::ERROR => 40,
        self::CRITICAL => 50,
    ];

    private const REDACT_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'api_token', 'access_token', 'github_token', 'secret',
        'api_secret', 'key_secret', 'webhook_secret', 'signature',
        'authorization', 'razorpay_signature', 'private_key', 'passwd', 'pwd',
        'shared_secret', 'app_key',
    ];

    public static function configure(string $directory, string $channel = 'app'): void
    {
        self::$directory = rtrim($directory, '/');
        self::$channel = $channel;
        if (!is_dir(self::$directory)) {
            @mkdir(self::$directory, 0750, true);
        }
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function log(string $level, string $message, array $context = []): void
    {
        $minimum = (string) Config::get('app.log_level', self::INFO);
        if ((self::LEVELS[$level] ?? 20) < (self::LEVELS[$minimum] ?? 20)) {
            return;
        }

        $directory = self::$directory ?? (defined('BASE_PATH') ? BASE_PATH . '/logs' : sys_get_temp_dir());
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        $line = sprintf(
            "[%s] %s.%s: %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            self::$channel,
            strtoupper($level),
            $message,
            $context === [] ? '' : json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        $file = $directory . '/' . self::$channel . '-' . gmdate('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        @chmod($file, 0640);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            $isSecret = false;
            foreach (self::REDACT_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } else {
                $out[$key] = '[' . get_debug_type($value) . ']';
            }
        }
        return $out;
    }

    /** Delete log files older than $days. Returns the number removed. */
    public static function prune(int $days): int
    {
        $directory = self::$directory ?? (defined('BASE_PATH') ? BASE_PATH . '/logs' : null);
        if ($directory === null || !is_dir($directory)) {
            return 0;
        }
        $cutoff = time() - ($days * 86400);
        $removed = 0;
        foreach (glob($directory . '/*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
