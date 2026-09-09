<?php
declare(strict_types=1);

namespace Tests;

/**
 * A minimal test harness.
 *
 * Written rather than pulled in because the whole application is
 * dependency-free by design and must be deployable by upload; adding a
 * dev-only package manager just for the test suite would undercut that.
 *
 * It records what was actually observed for each check, so the verification
 * report can quote real output rather than a bare pass/fail.
 */
final class TestRunner
{
    /** @var array<int,array<string,mixed>> */
    private array $results = [];

    private string $currentGroup = '';

    private int $passed = 0;

    private int $failed = 0;

    private int $skipped = 0;

    private float $startedAt;

    public function __construct(private bool $verbose = true)
    {
        $this->startedAt = microtime(true);
    }

    public function group(string $name): void
    {
        $this->currentGroup = $name;
        if ($this->verbose) {
            $this->line('');
            $this->line("\033[1m── $name \033[0m" . str_repeat('─', max(0, 58 - strlen($name))));
        }
    }

    /**
     * Run one test.
     *
     * The callback returns either true/false, or an array
     * ['pass' => bool, 'actual' => string] so the report can show what
     * happened rather than only whether it matched.
     */
    public function test(string $id, string $description, string $expected, callable $callback): bool
    {
        $started = microtime(true);
        $actual = '';
        $pass = false;
        $error = null;

        try {
            $result = $callback();

            if (is_array($result)) {
                $pass = (bool) ($result['pass'] ?? false);
                $actual = (string) ($result['actual'] ?? '');
            } elseif (is_bool($result)) {
                $pass = $result;
                $actual = $pass ? 'As expected.' : 'Did not match.';
            } else {
                $pass = (bool) $result;
                $actual = (string) $result;
            }
        } catch (\Throwable $e) {
            $pass = false;
            $error = $e::class . ': ' . $e->getMessage();
            $actual = $error . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }

        $duration = (int) round((microtime(true) - $started) * 1000);

        $this->results[] = [
            'id' => $id,
            'group' => $this->currentGroup,
            'description' => $description,
            'expected' => $expected,
            'actual' => $actual,
            'pass' => $pass,
            'duration_ms' => $duration,
            'error' => $error,
        ];

        $pass ? $this->passed++ : $this->failed++;

        if ($this->verbose) {
            $mark = $pass ? "\033[0;32m PASS \033[0m" : "\033[0;31m FAIL \033[0m";
            $this->line(sprintf('%s %-6s %-52s %4dms', $mark, $id, $this->truncate($description, 52), $duration));
            if (!$pass) {
                $this->line(sprintf('       %-6s expected: %s', '', $this->truncate($expected, 100)));
                $this->line(sprintf('       %-6s actual:   %s', '', $this->truncate($actual, 300)));
            }
        }

        return $pass;
    }

    public function skip(string $id, string $description, string $reason): void
    {
        $this->results[] = [
            'id' => $id,
            'group' => $this->currentGroup,
            'description' => $description,
            'expected' => '—',
            'actual' => $reason,
            'pass' => null,
            'duration_ms' => 0,
            'error' => null,
        ];
        $this->skipped++;

        if ($this->verbose) {
            $this->line(sprintf("\033[0;33m SKIP \033[0m %-6s %-52s", $id, $this->truncate($description, 52)));
            $this->line(sprintf('       %-6s %s', '', $this->truncate($reason, 200)));
        }
    }

    /** @return array{pass:bool,actual:string} */
    public static function assertEquals(mixed $expected, mixed $actual, string $label = ''): array
    {
        $pass = $expected === $actual;
        return [
            'pass' => $pass,
            'actual' => $pass
                ? ($label !== '' ? $label . ': ' : '') . self::describe($actual)
                : sprintf('expected %s, got %s', self::describe($expected), self::describe($actual)),
        ];
    }

    /** @return array{pass:bool,actual:string} */
    public static function assertTrue(bool $condition, string $onPass, string $onFail): array
    {
        return ['pass' => $condition, 'actual' => $condition ? $onPass : $onFail];
    }

    /** @return array{pass:bool,actual:string} */
    public static function assertContains(string $needle, string $haystack, string $label = ''): array
    {
        $pass = str_contains($haystack, $needle);
        return [
            'pass' => $pass,
            'actual' => $pass
                ? ($label !== '' ? $label : 'Found "' . self::truncateStatic($needle, 60) . '".')
                : 'Did not find "' . self::truncateStatic($needle, 60) . '" in: '
                    . self::truncateStatic($haystack, 200),
        ];
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            is_array($value) => 'array(' . count($value) . ')',
            is_string($value) => '"' . self::truncateStatic($value, 120) . '"',
            default => (string) $value,
        };
    }

    /** @return array<int,array<string,mixed>> */
    public function results(): array
    {
        return $this->results;
    }

    public function passed(): int
    {
        return $this->passed;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function skippedCount(): int
    {
        return $this->skipped;
    }

    public function durationSeconds(): float
    {
        return round(microtime(true) - $this->startedAt, 2);
    }

    public function summary(): void
    {
        $total = $this->passed + $this->failed;

        $this->line('');
        $this->line(str_repeat('═', 74));
        $this->line(sprintf(
            "\033[1mResults:\033[0m \033[0;32m%d passed\033[0m, \033[0;31m%d failed\033[0m, "
            . "\033[0;33m%d skipped\033[0m of %d checks in %.2fs",
            $this->passed,
            $this->failed,
            $this->skipped,
            $total,
            $this->durationSeconds()
        ));
        $this->line(str_repeat('═', 74));

        if ($this->failed > 0) {
            $this->line('');
            $this->line("\033[0;31mFailures:\033[0m");
            foreach ($this->results as $result) {
                if ($result['pass'] === false) {
                    $this->line(sprintf('  %-6s %s', $result['id'], $result['description']));
                    $this->line(sprintf('         %s', $this->truncate((string) $result['actual'], 300)));
                }
            }
        }
    }

    private function line(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    private function truncate(string $text, int $length): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1) . '…';
    }

    private static function truncateStatic(string $text, int $length): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1) . '…';
    }
}
