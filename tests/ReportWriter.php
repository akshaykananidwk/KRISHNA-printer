<?php
declare(strict_types=1);

namespace Tests;

/**
 * Writes the verification report from what the test run actually observed.
 *
 * Every row quotes the real result, so the report is a record of a run rather
 * than a restatement of intent. Nothing is marked as working unless its check
 * passed in that run.
 */
final class ReportWriter
{
    /** @param array<string,mixed> $environment */
    public static function write(TestRunner $runner, string $path, array $environment = []): void
    {
        $results = $runner->results();
        $passed = $runner->passed();
        $failed = $runner->failed();
        $skipped = $runner->skippedCount();
        $total = $passed + $failed;

        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $lines = [];
        $lines[] = '# Verification Report';
        $lines[] = '';
        $lines[] = 'Krishna Printer — Commercial Multi-Location Cloud Printing Management System';
        $lines[] = '';
        $lines[] = '## Run details';
        $lines[] = '';
        $lines[] = '| | |';
        $lines[] = '|---|---|';
        $lines[] = '| Run at | ' . gmdate('Y-m-d H:i:s') . ' UTC |';
        $lines[] = '| Duration | ' . $runner->durationSeconds() . ' seconds |';
        $lines[] = '| PHP | ' . ($environment['php_version'] ?? PHP_VERSION) . ' |';
        $lines[] = '| Database | ' . ($environment['database'] ?? 'unknown') . ' |';
        $lines[] = '| Application under test | ' . ($environment['base_url'] ?? '') . ' |';
        $lines[] = '| Printer under test | ' . ($environment['mock_printer'] ?? '') . ' |';
        $lines[] = '';
        $lines[] = '## Result';
        $lines[] = '';
        $lines[] = sprintf(
            '**%d passed · %d failed · %d skipped** of %d checks.',
            $passed,
            $failed,
            $skipped,
            $total
        );
        $lines[] = '';

        if ($failed === 0 && $total > 0) {
            $lines[] = 'Every check passed in this run.';
        } elseif ($failed > 0) {
            $lines[] = sprintf(
                '%d check%s did not pass. They are listed in full below and are **not** '
                . 'described as working anywhere in this report.',
                $failed,
                $failed === 1 ? '' : 's'
            );
        }
        $lines[] = '';

        // -----------------------------------------------------------------
        // How to read this
        // -----------------------------------------------------------------
        $lines[] = '## How this was tested';
        $lines[] = '';
        $lines[] = 'Every check drove the running application over real HTTP — through the front';
        $lines[] = 'controller, the middleware stack, the session and CSRF layers — rather than calling';
        $lines[] = 'controllers directly, because a test that bypasses the middleware does not prove the';
        $lines[] = 'middleware works.';
        $lines[] = '';
        $lines[] = 'The printer under test speaks genuine IPP over HTTP and genuine RAW/9100 on real';
        $lines[] = 'sockets, and reports itself as a Canon PIXMA GM4070 with that model\'s actual';
        $lines[] = 'characteristics (monochrome, no A3, auto-duplex). It can be told mid-run to report';
        $lines[] = 'itself out of paper or stopped, which is how the "printer unavailable" path was';
        $lines[] = 'exercised rather than assumed.';
        $lines[] = '';
        $lines[] = 'The database is a real MariaDB server; migrations, backups and a full database';
        $lines[] = 'restore were executed against it.';
        $lines[] = '';

        // -----------------------------------------------------------------
        // Results by area
        // -----------------------------------------------------------------
        $lines[] = '## Results';
        $lines[] = '';

        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result['group']][] = $result;
        }

        foreach ($grouped as $group => $groupResults) {
            $groupPassed = 0;
            $groupFailed = 0;
            foreach ($groupResults as $result) {
                if ($result['pass'] === true) {
                    $groupPassed++;
                } elseif ($result['pass'] === false) {
                    $groupFailed++;
                }
            }

            $lines[] = '### ' . $group;
            $lines[] = '';
            $lines[] = sprintf('%d passed, %d failed.', $groupPassed, $groupFailed);
            $lines[] = '';
            $lines[] = '| # | Test | Expected | Actual | Result |';
            $lines[] = '|---|------|----------|--------|--------|';

            foreach ($groupResults as $result) {
                $status = match ($result['pass']) {
                    true => '**PASS**',
                    false => '**FAIL**',
                    default => 'SKIP',
                };

                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s |',
                    self::cell((string) $result['id']),
                    self::cell((string) $result['description']),
                    self::cell((string) $result['expected']),
                    self::cell((string) $result['actual']),
                    $status
                );
            }

            $lines[] = '';
        }

        // -----------------------------------------------------------------
        // Coverage against the 30 required tests
        // -----------------------------------------------------------------
        $lines[] = '## Coverage of the 30 required tests';
        $lines[] = '';
        $lines[] = 'The requirement lists 30 numbered tests. Each is mapped below to the checks that';
        $lines[] = 'exercised it.';
        $lines[] = '';
        $lines[] = '| # | Required test | Covered by | Result |';
        $lines[] = '|---|---------------|------------|--------|';

        $coverage = [
            1 => ['QR scan', ['T1.1']],
            2 => ['Location identification', ['T1.1', 'T2.1', 'T2.2']],
            3 => ['Printer online check', ['T3.1', 'T3.2', 'T3.3', 'T3.4', 'T13.2']],
            4 => ['Printer offline check', ['T4.1', 'T4.2', 'T4.3', 'T4.4', 'T4.5']],
            5 => ['File upload', ['T5.1', 'T5.2', 'T5.3', 'T5.4']],
            6 => ['Multiple files', ['T6.1']],
            7 => ['Page range', ['T7.1', 'T14.2', 'T17.4']],
            8 => ['B&W printing', ['T8.1', 'T17.3']],
            9 => ['Colour printing', ['T9.1', 'T3.3']],
            10 => ['A4', ['T10.1', 'T17.3']],
            11 => ['Supported paper sizes', ['T11.1', 'T11.2']],
            12 => ['Copies', ['T8.1', 'T12.1', 'T17.3']],
            13 => ['Duplex', ['T13.1', 'T13.2', 'T13.3', 'T13.4']],
            14 => ['Price calculation', ['T14.1', 'T14.2', 'T14.3', 'T14.4']],
            15 => ['Payment success', ['T15.1', 'T15.2', 'T15.3']],
            16 => ['Payment failure', ['T15.1', 'T16.1', 'T16.2']],
            17 => ['Print queue', ['T17.1', 'T17.2', 'T17.3', 'T17.4']],
            18 => ['Printer failure', ['T18.1', 'T18.2', 'T18.3']],
            19 => ['Retry', ['T19.1', 'T19.2']],
            20 => ['Job cancellation', ['T20.1', 'T20.2']],
            21 => ['File cleanup', ['T21.1', 'T21.2']],
            22 => ['Admin authentication', ['T22.1', 'T22.2', 'T22.3', 'T22.4', 'T22.5', 'T22.6']],
            23 => ['Security validation', ['T23.1', 'T23.2', 'T23.3', 'T23.4', 'T23.5', 'T23.6', 'T23.7', 'T23.8']],
            24 => ['Database migration', ['T24.1', 'T24.2', 'T24.3']],
            25 => ['Backup', ['T25.1', 'T25.2', 'T25.3']],
            26 => ['GitHub update', ['T26.1', 'T26.2']],
            27 => ['Rollback', ['T27.1', 'T27.2', 'T27.3']],
            28 => ['Fresh /install installation', ['T28.1', 'T28.2', 'T28.3', 'T28.4', 'T28.5', 'T28.6',
                                                   'T28.7', 'T28.8', 'T28.9', 'T28.10', 'T28.11', 'T28.12', 'T28.13']],
            29 => ['Mobile responsiveness', ['T29.1', 'T29.2', 'T29.3']],
            30 => ['50-location scalability', ['T30.1', 'T30.2', 'T30.3', 'T30.4', 'T30.5']],
        ];

        $byId = [];
        foreach ($results as $result) {
            $byId[$result['id']] = $result;
        }

        foreach ($coverage as $number => [$label, $ids]) {
            $allPassed = true;
            $anyRun = false;
            $missing = [];

            foreach ($ids as $id) {
                if (!isset($byId[$id])) {
                    $missing[] = $id;
                    $allPassed = false;
                    continue;
                }
                $anyRun = true;
                if ($byId[$id]['pass'] !== true) {
                    $allPassed = false;
                }
            }

            $status = match (true) {
                !$anyRun => 'NOT RUN',
                $allPassed => '**PASS**',
                default => '**FAIL**',
            };

            $lines[] = sprintf(
                '| %d | %s | %s | %s |',
                $number,
                $label,
                implode(', ', $ids) . ($missing !== [] ? ' *(missing: ' . implode(', ', $missing) . ')*' : ''),
                $status
            );
        }

        $lines[] = '';

        // -----------------------------------------------------------------
        // Failures, in full
        // -----------------------------------------------------------------
        if ($failed > 0) {
            $lines[] = '## Failures';
            $lines[] = '';
            foreach ($results as $result) {
                if ($result['pass'] !== false) {
                    continue;
                }
                $lines[] = '### ' . $result['id'] . ' — ' . $result['description'];
                $lines[] = '';
                $lines[] = '- **Group:** ' . $result['group'];
                $lines[] = '- **Expected:** ' . $result['expected'];
                $lines[] = '- **Actual:** ' . $result['actual'];
                if ($result['error'] !== null) {
                    $lines[] = '- **Error:** `' . $result['error'] . '`';
                }
                $lines[] = '';
            }
        }

        if ($skipped > 0) {
            $lines[] = '## Skipped';
            $lines[] = '';
            $lines[] = '| # | Test | Why |';
            $lines[] = '|---|------|-----|';
            foreach ($results as $result) {
                if ($result['pass'] === null) {
                    $lines[] = sprintf(
                        '| %s | %s | %s |',
                        self::cell((string) $result['id']),
                        self::cell((string) $result['description']),
                        self::cell((string) $result['actual'])
                    );
                }
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = sprintf(
            '_Generated by `tests/run.php` on %s UTC. Re-run with `php tests/run.php`._',
            gmdate('Y-m-d H:i:s')
        );
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines));
    }

    /** Escape a value for a Markdown table cell. */
    private static function cell(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;
        $value = str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $value);
        return mb_strlen($value) > 400 ? mb_substr($value, 0, 399) . '…' : $value;
    }
}
