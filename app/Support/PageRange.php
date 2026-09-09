<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Parses and normalises page-range expressions such as "1-5", "8-10" or
 * "2,4,7-9". Used for both billing (how many sheets the customer pays for)
 * and for the actual page-ranges IPP attribute sent to the printer.
 */
final class PageRange
{
    public const ALL = 'all';

    /** Cheap syntax check used by the validator. */
    public static function isValid(string $expression): bool
    {
        $expression = trim($expression);
        if ($expression === '' || strcasecmp($expression, self::ALL) === 0) {
            return true;
        }
        if (!preg_match('/^\s*\d+\s*(-\s*\d+\s*)?(,\s*\d+\s*(-\s*\d+\s*)?)*$/', $expression)) {
            return false;
        }
        foreach (self::segments($expression) as [$start, $end]) {
            if ($start < 1 || $end < $start) {
                return false;
            }
        }
        return true;
    }

    /**
     * Split into inclusive [start, end] pairs without any document context.
     *
     * @return array<int,array{0:int,1:int}>
     */
    public static function segments(string $expression): array
    {
        $out = [];
        foreach (explode(',', $expression) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '-')) {
                [$start, $end] = array_map('trim', explode('-', $part, 2));
                $out[] = [(int) $start, (int) $end];
            } else {
                $out[] = [(int) $part, (int) $part];
            }
        }
        return $out;
    }

    /**
     * Resolve an expression against a known document length and return the
     * distinct 1-based page numbers, ordered and de-duplicated.
     *
     * Out-of-document pages are clipped rather than rejected so a customer who
     * asks for 1-100 of a 12-page file is billed for 12, not 100.
     *
     * @return array<int,int>
     */
    public static function resolve(string $expression, int $totalPages): array
    {
        $totalPages = max(0, $totalPages);
        if ($totalPages === 0) {
            return [];
        }
        $expression = trim($expression);
        if ($expression === '' || strcasecmp($expression, self::ALL) === 0) {
            return range(1, $totalPages);
        }

        $pages = [];
        foreach (self::segments($expression) as [$start, $end]) {
            $start = max(1, $start);
            $end = min($totalPages, $end);
            for ($p = $start; $p <= $end; $p++) {
                $pages[$p] = true;
            }
        }
        $list = array_keys($pages);
        sort($list, SORT_NUMERIC);
        return $list;
    }

    /** How many pages the range actually selects within a document. */
    public static function count(string $expression, int $totalPages): int
    {
        return count(self::resolve($expression, $totalPages));
    }

    /**
     * Render the resolved pages back into the compact "1-3,7,10-12" form that
     * IPP's page-ranges attribute and CUPS's -o page-ranges option expect.
     */
    public static function normalise(string $expression, int $totalPages): string
    {
        $pages = self::resolve($expression, $totalPages);
        if ($pages === []) {
            return '';
        }
        if (count($pages) === $totalPages) {
            return self::ALL;
        }

        $parts = [];
        $start = $prev = $pages[0];
        foreach (array_slice($pages, 1) as $page) {
            if ($page === $prev + 1) {
                $prev = $page;
                continue;
            }
            $parts[] = $start === $prev ? (string) $start : "$start-$prev";
            $start = $prev = $page;
        }
        $parts[] = $start === $prev ? (string) $start : "$start-$prev";
        return implode(',', $parts);
    }

    /**
     * IPP page-ranges is a set of rangeOfInteger values; convert to that shape.
     *
     * @return array<int,array{lower:int,upper:int}>
     */
    public static function toIppRanges(string $expression, int $totalPages): array
    {
        $normalised = self::normalise($expression, $totalPages);
        if ($normalised === '' || $normalised === self::ALL) {
            return [];
        }
        $ranges = [];
        foreach (self::segments($normalised) as [$start, $end]) {
            $ranges[] = ['lower' => $start, 'upper' => $end];
        }
        return $ranges;
    }
}
