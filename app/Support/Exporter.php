<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Report exporters.
 *
 * CSV   — RFC 4180 with a UTF-8 BOM so Excel opens it in the right encoding.
 * Excel — SpreadsheetML 2003 (.xls), a plain XML format Excel and LibreOffice
 *         both open natively. Chosen over .xlsx because it needs no zip
 *         assembly and no library, which keeps the app dependency-free.
 * PDF   — a minimal PDF writer using the built-in Helvetica font. Enough for a
 *         clean tabular report without pulling in a PDF library.
 */
final class Exporter
{
    /**
     * @param array<int,string> $headers
     * @param array<int,array<int,string|int|float>> $rows
     */
    public static function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            // A leading =, +, - or @ makes a spreadsheet treat the cell as a
            // formula. Prefixing a tab neutralises that without changing the
            // visible value.
            fputcsv($handle, array_map(self::neutraliseFormula(...), $row), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF" . $csv;
    }

    private static function neutraliseFormula(string|int|float $value): string
    {
        $string = (string) $value;
        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "\t" . $string;
        }
        return $string;
    }

    /**
     * @param array<int,string> $headers
     * @param array<int,array<int,string|int|float>> $rows
     * @param array<string,string> $meta
     */
    public static function excel(string $title, array $headers, array $rows, array $meta = []): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<?mso-application progid="Excel.Sheet"?>' . "\n"
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Styles>'
            . '<Style ss:ID="hdr"><Font ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="#1E6F5C" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="meta"><Font ss:Italic="1" ss:Color="#5A6B64"/></Style>'
            . '<Style ss:ID="num"><NumberFormat ss:Format="#,##0"/></Style>'
            . '<Style ss:ID="money"><NumberFormat ss:Format="#,##0.00"/></Style>'
            . '</Styles>'
            . '<Worksheet ss:Name="' . $e(substr(preg_replace('/[\\\\\/\[\]\*\?:]/', '', $title) ?: 'Report', 0, 31)) . '">'
            . '<Table>';

        foreach ($meta as $label => $value) {
            $xml .= '<Row><Cell ss:StyleID="meta"><Data ss:Type="String">' . $e((string) $label)
                . '</Data></Cell><Cell ss:StyleID="meta"><Data ss:Type="String">' . $e((string) $value)
                . '</Data></Cell></Row>';
        }
        if ($meta !== []) {
            $xml .= '<Row/>';
        }

        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell ss:StyleID="hdr"><Data ss:Type="String">' . $e($header) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($row as $cell) {
                // Numbers go out typed so Excel can sum them; everything else
                // is a string, which also stops formula injection.
                if (is_int($cell) || is_float($cell)) {
                    $style = is_float($cell) ? ' ss:StyleID="money"' : ' ss:StyleID="num"';
                    $xml .= '<Cell' . $style . '><Data ss:Type="Number">' . $cell . '</Data></Cell>';
                } else {
                    $xml .= '<Cell><Data ss:Type="String">' . $e((string) $cell) . '</Data></Cell>';
                }
            }
            $xml .= '</Row>';
        }

        return $xml . '</Table></Worksheet></Workbook>';
    }

    /**
     * Minimal PDF writer: A4 landscape, Helvetica, paginated table.
     *
     * @param array<int,string> $headers
     * @param array<int,array<int,string|int|float>> $rows
     * @param array<string,string> $meta
     */
    public static function pdf(string $title, array $headers, array $rows, array $meta = []): string
    {
        // A4 landscape in PostScript points.
        $pageWidth = 842.0;
        $pageHeight = 595.0;
        $margin = 36.0;
        $lineHeight = 14.0;

        $columnCount = max(1, count($headers));
        $usableWidth = $pageWidth - ($margin * 2);
        $columnWidth = $usableWidth / $columnCount;
        // ~1.9 points per character at 8pt Helvetica.
        $charsPerColumn = max(6, (int) floor($columnWidth / 4.6));

        $pages = [];
        $current = [];
        $y = $pageHeight - $margin;

        $startPage = static function () use (&$current, &$y, $pageHeight, $margin): void {
            $current = [];
            $y = $pageHeight - $margin;
        };

        $startPage();

        // Title block
        $current[] = self::pdfText($margin, $y, 14, self::pdfEscape($title), true);
        $y -= 20;

        foreach ($meta as $label => $value) {
            $current[] = self::pdfText($margin, $y, 8, self::pdfEscape($label . ': ' . $value));
            $y -= 11;
        }
        $y -= 6;

        $drawHeader = static function () use (&$current, &$y, $headers, $margin, $columnWidth, $charsPerColumn, $usableWidth): void {
            $x = $margin;
            foreach ($headers as $header) {
                $current[] = self::pdfText($x, $y, 8, self::pdfEscape(self::truncate($header, $charsPerColumn)), true);
                $x += $columnWidth;
            }
            $y -= 4;
            $current[] = sprintf(
                "0.6 w 0.12 0.44 0.36 RG %.2f %.2f m %.2f %.2f l S\n",
                $margin,
                $y,
                $margin + $usableWidth,
                $y
            );
            $y -= 12;
        };

        $drawHeader();

        foreach ($rows as $row) {
            if ($y < $margin + 30) {
                $pages[] = implode('', $current);
                $startPage();
                $drawHeader();
            }

            $x = $margin;
            foreach (array_values($row) as $index => $cell) {
                if ($index >= $columnCount) {
                    break;
                }
                $current[] = self::pdfText($x, $y, 8, self::pdfEscape(self::truncate((string) $cell, $charsPerColumn)));
                $x += $columnWidth;
            }
            $y -= $lineHeight;
        }

        $pages[] = implode('', $current);

        return self::assemblePdf($pages, $pageWidth, $pageHeight, $title);
    }

    private static function pdfText(float $x, float $y, float $size, string $text, bool $bold = false): string
    {
        return sprintf(
            "BT /%s %.1f Tf 0.08 0.15 0.12 rg %.2f %.2f Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            $size,
            $x,
            $y,
            $text
        );
    }

    private static function pdfEscape(string $text): string
    {
        // PDF's base encoding is not UTF-8; transliterate to Latin-1 so accented
        // characters degrade rather than corrupt the stream.
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $converted);
    }

    private static function truncate(string $text, int $length): string
    {
        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, max(1, $length - 1)) . '…';
    }

    /** @param array<int,string> $pageContents */
    private static function assemblePdf(array $pageContents, float $width, float $height, string $title): string
    {
        $objects = [];
        $pageCount = count($pageContents);

        // 1: Catalog, 2: Pages, 3: F1, 4: F2, then per page: content + page.
        $pageObjectIds = [];
        $contentObjectIds = [];
        $nextId = 5;

        foreach ($pageContents as $index => $content) {
            $contentObjectIds[$index] = $nextId++;
            $pageObjectIds[$index] = $nextId++;
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d >>",
            implode(' ', array_map(static fn (int $id): string => "$id 0 R", $pageObjectIds)),
            $pageCount
        );
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        foreach ($pageContents as $index => $content) {
            $objects[$contentObjectIds[$index]] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );
            $objects[$pageObjectIds[$index]] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                $width,
                $height,
                $contentObjectIds[$index]
            );
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxId = max(array_keys($objects));

        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 65535 f \n";
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info << /Title (%s) /Producer (Krishna Printer) >> >>\n"
            . "startxref\n%d\n%%%%EOF",
            $maxId + 1,
            self::pdfEscape($title),
            $xrefOffset
        );

        return $pdf;
    }
}
