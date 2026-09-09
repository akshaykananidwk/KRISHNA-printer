<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Server-rendered SVG charts.
 *
 * Rendered on the server rather than by a charting library because the
 * Content-Security-Policy forbids third-party scripts, and because a dashboard
 * that draws itself in the initial HTML is readable before any JavaScript runs.
 * admin.js layers hover tooltips on top of the same markup.
 *
 * PALETTE
 * -------
 * Two categorical slots, in fixed order:
 *   slot 1  #2a78d6  blue    — the primary/first series (B&W pages, revenue)
 *   slot 2  #eb6834  orange  — the second series (colour pages)
 *
 * Validated against a white chart surface: adjacent-pair CVD ΔE 24.7 (protan),
 * normal-vision ΔE 33.6, both slots ≥ 3:1 contrast. Every chart with two series
 * also carries a legend and direct labels, so identity never rests on colour
 * alone.
 *
 * There is deliberately no dual-axis chart anywhere: revenue and job count are
 * different scales and get their own panels.
 */
final class Chart
{
    public const SERIES_1 = '#2a78d6';   // blue
    public const SERIES_2 = '#eb6834';   // orange

    private const GRID = '#e4eae8';
    private const AXIS_TEXT = '#5a6b64';
    private const AXIS_FAINT = '#8a9691';

    /**
     * Area chart over time, one series.
     *
     * @param array<int,array<string,mixed>> $series rows with 'label' and the value key
     */
    public static function area(
        array $series,
        string $valueKey,
        string $label,
        string $color = self::SERIES_1,
        string $valuePrefix = '',
        int $height = 190
    ): string {
        if ($series === []) {
            return self::empty('No data for this period.');
        }

        $width = 720;
        $padLeft = 52;
        $padRight = 12;
        $padTop = 14;
        $padBottom = 26;

        $plotWidth = $width - $padLeft - $padRight;
        $plotHeight = $height - $padTop - $padBottom;

        $values = array_map(static fn (array $row): float => (float) ($row[$valueKey] ?? 0), $series);
        $max = max($values);
        $niceMax = self::niceCeiling($max);
        $count = count($values);

        $x = static fn (int $i): float => $count === 1
            ? $padLeft + $plotWidth / 2
            : $padLeft + ($i / ($count - 1)) * $plotWidth;
        $y = static fn (float $value): float => $niceMax <= 0
            ? $padTop + $plotHeight
            : $padTop + $plotHeight - ($value / $niceMax) * $plotHeight;

        $svg = sprintf(
            '<svg viewBox="0 0 %d %d" role="img" aria-label="%s over time" class="js-chart" '
            . 'data-chart="area" preserveAspectRatio="none">',
            $width,
            $height,
            self::e($label)
        );

        // Horizontal grid + y labels. Four bands is enough to read a value off
        // without the grid competing with the data.
        for ($i = 0; $i <= 4; $i++) {
            $value = $niceMax * ($i / 4);
            $lineY = $y($value);
            $svg .= sprintf(
                '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="1"/>',
                $padLeft,
                $lineY,
                $width - $padRight,
                $lineY,
                self::GRID
            );
            $svg .= sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="end" font-size="10" fill="%s">%s</text>',
                $padLeft - 7,
                $lineY + 3.5,
                self::AXIS_FAINT,
                self::e($valuePrefix . self::compact($value))
            );
        }

        // Area fill, then the line on top.
        $areaPath = sprintf('M %.1f %.1f', $x(0), $y($values[0]));
        $linePath = $areaPath;

        for ($i = 1; $i < $count; $i++) {
            $segment = sprintf(' L %.1f %.1f', $x($i), $y($values[$i]));
            $areaPath .= $segment;
            $linePath .= $segment;
        }

        $areaPath .= sprintf(
            ' L %.1f %.1f L %.1f %.1f Z',
            $x($count - 1),
            $padTop + $plotHeight,
            $x(0),
            $padTop + $plotHeight
        );

        $svg .= sprintf('<path d="%s" fill="%s" fill-opacity="0.12"/>', $areaPath, $color);
        $svg .= sprintf(
            '<path d="%s" fill="none" stroke="%s" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>',
            $linePath,
            $color
        );

        // X labels: thin them out so they never collide on a narrow panel.
        $step = (int) max(1, ceil($count / 7));
        for ($i = 0; $i < $count; $i += $step) {
            $svg .= sprintf(
                '<text x="%.1f" y="%d" text-anchor="middle" font-size="10" fill="%s">%s</text>',
                $x($i),
                $height - 8,
                self::AXIS_FAINT,
                self::e((string) ($series[$i]['label'] ?? ''))
            );
        }

        // Invisible hit targets, one per point, carrying the tooltip data.
        // Wider than the marks so a hover on a phone still lands.
        $hitWidth = $count > 1 ? $plotWidth / ($count - 1) : $plotWidth;
        for ($i = 0; $i < $count; $i++) {
            $svg .= sprintf(
                '<rect class="js-hit" x="%.1f" y="%d" width="%.1f" height="%d" fill="transparent" '
                . 'data-x="%.1f" data-y="%.1f" data-label="%s" data-value="%s"/>',
                $x($i) - $hitWidth / 2,
                $padTop,
                $hitWidth,
                $plotHeight,
                $x($i),
                $y($values[$i]),
                self::e((string) ($series[$i]['label'] ?? '')),
                self::e($valuePrefix . number_format($values[$i], $valuePrefix !== '' ? 2 : 0))
            );
        }

        // Only mark the peak: a dot on every point is noise, but the highest
        // day is the one an operator looks for.
        $peak = array_search($max, $values, true);
        if ($peak !== false && $max > 0) {
            $svg .= sprintf(
                '<circle cx="%.1f" cy="%.1f" r="4" fill="%s" stroke="#fff" stroke-width="2"/>',
                $x((int) $peak),
                $y($max),
                $color
            );
        }

        return $svg . '</svg>';
    }

    /**
     * Vertical bars over time, one series.
     *
     * @param array<int,array<string,mixed>> $series
     */
    public static function bars(
        array $series,
        string $valueKey,
        string $label,
        string $color = self::SERIES_1,
        int $height = 190
    ): string {
        if ($series === []) {
            return self::empty('No data for this period.');
        }

        $width = 720;
        $padLeft = 44;
        $padRight = 12;
        $padTop = 14;
        $padBottom = 26;

        $plotWidth = $width - $padLeft - $padRight;
        $plotHeight = $height - $padTop - $padBottom;

        $values = array_map(static fn (array $row): float => (float) ($row[$valueKey] ?? 0), $series);
        $niceMax = self::niceCeiling(max($values));
        $count = count($values);

        // A 2px gap between adjacent bars keeps them from reading as one block.
        $slot = $plotWidth / max(1, $count);
        $barWidth = max(2.0, $slot - 2);

        $svg = sprintf(
            '<svg viewBox="0 0 %d %d" role="img" aria-label="%s" class="js-chart" data-chart="bars" '
            . 'preserveAspectRatio="none">',
            $width,
            $height,
            self::e($label)
        );

        for ($i = 0; $i <= 4; $i++) {
            $value = $niceMax * ($i / 4);
            $lineY = $niceMax <= 0
                ? $padTop + $plotHeight
                : $padTop + $plotHeight - ($value / $niceMax) * $plotHeight;
            $svg .= sprintf(
                '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="1"/>',
                $padLeft,
                $lineY,
                $width - $padRight,
                $lineY,
                self::GRID
            );
            $svg .= sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="end" font-size="10" fill="%s">%s</text>',
                $padLeft - 7,
                $lineY + 3.5,
                self::AXIS_FAINT,
                self::e(self::compact($value))
            );
        }

        foreach ($values as $i => $value) {
            $barHeight = $niceMax <= 0 ? 0 : ($value / $niceMax) * $plotHeight;
            $barX = $padLeft + $i * $slot + ($slot - $barWidth) / 2;
            $barY = $padTop + $plotHeight - $barHeight;

            if ($barHeight > 0) {
                // 4px rounded top, square foot: the bar stays anchored to the
                // baseline rather than floating.
                $radius = min(4.0, $barWidth / 2, $barHeight);
                $svg .= sprintf(
                    '<path d="M %.1f %.1f v %.1f a %.1f %.1f 0 0 1 %.1f -%.1f h %.1f a %.1f %.1f 0 0 1 %.1f %.1f v %.1f Z" fill="%s"/>',
                    $barX,
                    $padTop + $plotHeight,
                    -($barHeight - $radius),
                    $radius,
                    $radius,
                    $radius,
                    $radius,
                    $barWidth - 2 * $radius,
                    $radius,
                    $radius,
                    $radius,
                    $radius,
                    $barHeight - $radius,
                    $color
                );
            }

            $svg .= sprintf(
                '<rect class="js-hit" x="%.1f" y="%d" width="%.1f" height="%d" fill="transparent" '
                . 'data-x="%.1f" data-y="%.1f" data-label="%s" data-value="%s"/>',
                $padLeft + $i * $slot,
                $padTop,
                $slot,
                $plotHeight,
                $barX + $barWidth / 2,
                $barY,
                self::e((string) ($series[$i]['label'] ?? '')),
                self::e(number_format($value))
            );
        }

        $step = (int) max(1, ceil($count / 7));
        for ($i = 0; $i < $count; $i += $step) {
            $svg .= sprintf(
                '<text x="%.1f" y="%d" text-anchor="middle" font-size="10" fill="%s">%s</text>',
                $padLeft + $i * $slot + $slot / 2,
                $height - 8,
                self::AXIS_FAINT,
                self::e((string) ($series[$i]['label'] ?? ''))
            );
        }

        return $svg . '</svg>';
    }

    /**
     * Two-series stacked bars over time — used for the colour/mono page split.
     *
     * @param array<int,array<string,mixed>> $series
     */
    public static function stacked(
        array $series,
        string $keyA,
        string $keyB,
        string $labelA,
        string $labelB,
        int $height = 190
    ): string {
        if ($series === []) {
            return self::empty('No data for this period.');
        }

        $width = 720;
        $padLeft = 44;
        $padRight = 12;
        $padTop = 14;
        $padBottom = 26;

        $plotWidth = $width - $padLeft - $padRight;
        $plotHeight = $height - $padTop - $padBottom;

        $totals = array_map(
            static fn (array $row): float => (float) ($row[$keyA] ?? 0) + (float) ($row[$keyB] ?? 0),
            $series
        );
        $niceMax = self::niceCeiling(max($totals));
        $count = count($series);

        $slot = $plotWidth / max(1, $count);
        $barWidth = max(2.0, $slot - 2);

        $svg = sprintf(
            '<svg viewBox="0 0 %d %d" role="img" aria-label="%s and %s per day" class="js-chart" '
            . 'data-chart="stacked" preserveAspectRatio="none">',
            $width,
            $height,
            self::e($labelA),
            self::e($labelB)
        );

        for ($i = 0; $i <= 4; $i++) {
            $value = $niceMax * ($i / 4);
            $lineY = $niceMax <= 0
                ? $padTop + $plotHeight
                : $padTop + $plotHeight - ($value / $niceMax) * $plotHeight;
            $svg .= sprintf(
                '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="1"/>',
                $padLeft, $lineY, $width - $padRight, $lineY, self::GRID
            );
            $svg .= sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="end" font-size="10" fill="%s">%s</text>',
                $padLeft - 7, $lineY + 3.5, self::AXIS_FAINT, self::e(self::compact($value))
            );
        }

        foreach ($series as $i => $row) {
            $a = (float) ($row[$keyA] ?? 0);
            $b = (float) ($row[$keyB] ?? 0);

            $heightA = $niceMax <= 0 ? 0 : ($a / $niceMax) * $plotHeight;
            $heightB = $niceMax <= 0 ? 0 : ($b / $niceMax) * $plotHeight;

            $barX = $padLeft + $i * $slot + ($slot - $barWidth) / 2;
            $baseY = $padTop + $plotHeight;

            if ($heightA > 0) {
                $svg .= sprintf(
                    '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>',
                    $barX, $baseY - $heightA, $barWidth, $heightA, self::SERIES_1
                );
            }
            if ($heightB > 0) {
                // 2px surface gap between the two segments so the boundary is
                // legible without relying on the colour difference.
                $gap = $heightA > 0 ? 2 : 0;
                $svg .= sprintf(
                    '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>',
                    $barX,
                    $baseY - $heightA - $heightB - $gap,
                    $barWidth,
                    max(0, $heightB),
                    self::SERIES_2
                );
            }

            $svg .= sprintf(
                '<rect class="js-hit" x="%.1f" y="%d" width="%.1f" height="%d" fill="transparent" '
                . 'data-x="%.1f" data-y="%.1f" data-label="%s" data-value="%s"/>',
                $padLeft + $i * $slot, $padTop, $slot, $plotHeight,
                $barX + $barWidth / 2,
                $baseY - $heightA - $heightB,
                self::e((string) ($row['label'] ?? '')),
                self::e(sprintf('%s: %s · %s: %s', $labelA, number_format($a), $labelB, number_format($b)))
            );
        }

        $step = (int) max(1, ceil($count / 7));
        for ($i = 0; $i < $count; $i += $step) {
            $svg .= sprintf(
                '<text x="%.1f" y="%d" text-anchor="middle" font-size="10" fill="%s">%s</text>',
                $padLeft + $i * $slot + $slot / 2, $height - 8, self::AXIS_FAINT,
                self::e((string) ($series[$i]['label'] ?? ''))
            );
        }

        return $svg . '</svg>';
    }

    /**
     * A two-part split shown as a single proportional bar with direct labels.
     *
     * A donut would put the two numbers on opposite sides of a ring and make
     * them harder to compare; a single bar reads as a proportion at a glance,
     * which is the actual question ("how much of our volume is colour?").
     */
    public static function split(
        int $valueA,
        int $valueB,
        string $labelA,
        string $labelB
    ): string {
        $total = $valueA + $valueB;

        if ($total === 0) {
            return self::empty('No pages printed in this period.');
        }

        $percentA = ($valueA / $total) * 100;
        $percentB = 100 - $percentA;

        $html = '<div class="a-split">';
        $html .= '<div class="a-split__bar">';

        if ($valueA > 0) {
            $html .= sprintf(
                '<span class="a-split__seg" style="width:%.2f%%;background:%s" title="%s"></span>',
                $percentA,
                self::SERIES_1,
                self::e(sprintf('%s: %s pages', $labelA, number_format($valueA)))
            );
        }
        if ($valueB > 0) {
            $html .= sprintf(
                '<span class="a-split__seg" style="width:%.2f%%;background:%s" title="%s"></span>',
                $percentB,
                self::SERIES_2,
                self::e(sprintf('%s: %s pages', $labelB, number_format($valueB)))
            );
        }

        $html .= '</div>';

        // Direct labels: identity never rests on the colour alone.
        $html .= '<div class="a-split__labels">';
        $html .= sprintf(
            '<div class="a-split__label"><span class="a-chart__swatch" style="background:%s"></span>'
            . '<span class="a-split__name">%s</span>'
            . '<strong class="a-split__value">%s</strong>'
            . '<span class="a-split__pct">%.1f%%</span></div>',
            self::SERIES_1,
            self::e($labelA),
            number_format($valueA),
            $percentA
        );
        $html .= sprintf(
            '<div class="a-split__label"><span class="a-chart__swatch" style="background:%s"></span>'
            . '<span class="a-split__name">%s</span>'
            . '<strong class="a-split__value">%s</strong>'
            . '<span class="a-split__pct">%.1f%%</span></div>',
            self::SERIES_2,
            self::e($labelB),
            number_format($valueB),
            $percentB
        );
        $html .= '</div></div>';

        return $html;
    }

    /**
     * Ranked horizontal bars. One measure, so one hue — magnitude is carried by
     * length, and colour would add nothing.
     *
     * @param array<int,array{name:string,value:int|float,meta?:string}> $rows
     */
    public static function ranked(array $rows, string $valuePrefix = '', int $limit = 8): string
    {
        $rows = array_slice($rows, 0, $limit);

        if ($rows === []) {
            return self::empty('No activity in this period.');
        }

        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, (float) $row['value']);
        }

        $html = '<div class="a-bars">';

        foreach ($rows as $row) {
            $value = (float) $row['value'];
            $percent = $max > 0 ? ($value / $max) * 100 : 0;

            $html .= '<div class="a-bar">';
            $html .= '<div class="a-bar__head">';
            $html .= '<span class="a-bar__name">' . self::e((string) $row['name']) . '</span>';
            $html .= '<span class="a-bar__value">' . self::e($valuePrefix . self::formatValue($value)) . '</span>';
            $html .= '</div>';
            $html .= sprintf(
                '<div class="a-bar__track"><div class="a-bar__fill" style="width:%.2f%%;background:%s"></div></div>',
                max($percent, $value > 0 ? 1.5 : 0),   // keep a non-zero value visible
                self::SERIES_1
            );
            if (!empty($row['meta'])) {
                $html .= '<div class="a-bar__meta a-small a-muted">' . self::e((string) $row['meta']) . '</div>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /** A legend for a multi-series chart. @param array<int,array{label:string,color:string}> $keys */
    public static function legend(array $keys): string
    {
        $html = '<div class="a-chart__legend">';
        foreach ($keys as $key) {
            $html .= sprintf(
                '<span class="a-chart__key"><span class="a-chart__swatch" style="background:%s"></span>%s</span>',
                self::e($key['color']),
                self::e($key['label'])
            );
        }
        return $html . '</div>';
    }

    private static function empty(string $message): string
    {
        return '<p class="a-chart__empty">' . self::e($message) . '</p>';
    }

    /** Round an axis maximum up to a readable number. */
    private static function niceCeiling(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $magnitude = 10 ** floor(log10($max));
        $normalised = $max / $magnitude;

        $nice = match (true) {
            $normalised <= 1 => 1,
            $normalised <= 2 => 2,
            $normalised <= 2.5 => 2.5,
            $normalised <= 5 => 5,
            default => 10,
        };

        return $nice * $magnitude;
    }

    private static function compact(float $value): string
    {
        if ($value >= 10000000) {
            return round($value / 10000000, 1) . 'Cr';
        }
        if ($value >= 100000) {
            return round($value / 100000, 1) . 'L';
        }
        if ($value >= 1000) {
            return round($value / 1000, 1) . 'k';
        }
        return (string) round($value, $value < 10 && $value !== floor($value) ? 1 : 0);
    }

    private static function formatValue(float $value): string
    {
        return $value == floor($value)
            ? number_format($value)
            : number_format($value, 2);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
