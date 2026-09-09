<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Determines how many pages a document has.
 *
 * The page count is what the customer is billed on, so it must be either
 * exact or clearly labelled as an estimate. Every method returns both the
 * number and how it was arrived at:
 *
 *   parsed    — read from the document's own structure. Exact.
 *   estimated — derived from size/heuristics. Shown to the customer as an
 *               estimate, and the job is priced on it only after they confirm.
 *   declared  — the customer told us (used for formats with no page concept).
 *   unknown   — could not be determined; the flow blocks rather than guessing.
 */
final class DocumentService
{
    /**
     * @return array{pages:int,source:string,detail:string}
     */
    public function countPages(string $path, string $extension): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return $this->result(0, 'unknown', 'The file could not be read.');
        }

        return match (strtolower($extension)) {
            'pdf' => $this->countPdfPages($path),
            'docx' => $this->countOoxmlPages($path, 'docx'),
            'pptx' => $this->countOoxmlPages($path, 'pptx'),
            'xlsx' => $this->countOoxmlPages($path, 'xlsx'),
            'jpg', 'jpeg', 'png' => $this->result(1, 'parsed', 'An image prints as a single page.'),
            'doc', 'xls', 'ppt' => $this->countLegacyOffice($path, $extension),
            default => $this->result(0, 'unknown', 'Page counting is not supported for this format.'),
        };
    }

    // -----------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------

    /**
     * PDF page counting, in order of reliability.
     *
     * 1. `pdfinfo` (poppler), when the server has it — authoritative.
     * 2. The page tree's /Count on the root /Pages node.
     * 3. Counting /Type /Page objects, including those inside compressed
     *    object streams (PDF 1.5+ stores most objects there, so a naive
     *    string search finds nothing on modern PDFs).
     *
     * @return array{pages:int,source:string,detail:string}
     */
    private function countPdfPages(string $path): array
    {
        $viaTool = $this->countPdfWithPdfinfo($path);
        if ($viaTool !== null) {
            return $this->result($viaTool, 'parsed', 'Read with pdfinfo.');
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return $this->result(0, 'unknown', 'The PDF could not be read.');
        }

        if (!str_starts_with($content, '%PDF-')) {
            return $this->result(0, 'unknown', 'The file does not have a PDF header.');
        }

        // Encrypted PDFs cannot be parsed for structure and usually cannot be
        // printed without the password either.
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $content) === 1) {
            return $this->result(
                0,
                'unknown',
                'This PDF is password-protected. Please upload an unprotected copy.'
            );
        }

        $fromTree = $this->pdfCountFromPageTree($content);
        if ($fromTree > 0) {
            return $this->result($fromTree, 'parsed', 'Read from the PDF page tree.');
        }

        $fromObjects = $this->pdfCountPageObjects($content);
        if ($fromObjects > 0) {
            return $this->result($fromObjects, 'parsed', 'Counted the PDF page objects.');
        }

        return $this->result(0, 'unknown', 'The PDF structure could not be interpreted.');
    }

    private function countPdfWithPdfinfo(string $path): ?int
    {
        if (!$this->canExec()) {
            return null;
        }
        $binary = $this->findBinary('pdfinfo');
        if ($binary === null) {
            return null;
        }

        $output = [];
        $exitCode = 0;
        @exec(escapeshellcmd($binary) . ' ' . escapeshellarg($path) . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            return null;
        }
        foreach ($output as $line) {
            if (preg_match('/^Pages:\s*(\d+)/i', $line, $m) === 1) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * Find the root page tree node's /Count. A PDF may contain several /Count
     * entries (one per intermediate node); the root's is the largest, and it
     * appears on a node with /Type /Pages that is not itself a kid.
     */
    private function pdfCountFromPageTree(string $content): int
    {
        $best = 0;

        // Match a /Pages dictionary carrying a /Count, in either attribute order.
        if (preg_match_all('/\/Type\s*\/Pages\b[^>]{0,600}?\/Count\s+(\d+)/s', $content, $matches) > 0) {
            foreach ($matches[1] as $count) {
                $best = max($best, (int) $count);
            }
        }
        if (preg_match_all('/\/Count\s+(\d+)[^>]{0,600}?\/Type\s*\/Pages\b/s', $content, $matches) > 0) {
            foreach ($matches[1] as $count) {
                $best = max($best, (int) $count);
            }
        }

        // Linearised PDFs put the page count in /N in the first-page dictionary.
        if ($best === 0 && preg_match('/\/Linearized\b[^>]{0,300}?\/N\s+(\d+)/s', $content, $m) === 1) {
            $best = (int) $m[1];
        }

        return $best;
    }

    /**
     * Count /Type /Page objects, including inside compressed object streams.
     *
     * PDF 1.5 and later pack most indirect objects into FlateDecode'd /ObjStm
     * streams, so page dictionaries are invisible to a plain text search. This
     * inflates each object stream and searches the decompressed bytes.
     */
    private function pdfCountPageObjects(string $content): int
    {
        // /Type /Page not followed by 's' — excludes /Type /Pages nodes.
        $count = preg_match_all('/\/Type\s*\/Page(?![sA-Za-z])/', $content);
        $count = $count === false ? 0 : $count;

        if (!function_exists('gzuncompress')) {
            return $count;
        }

        $offset = 0;
        $guard = 0;

        while (($position = strpos($content, 'stream', $offset)) !== false && $guard++ < 5000) {
            // Only inspect streams whose dictionary declares an object stream.
            $dictStart = max(0, $position - 800);
            $dictionary = substr($content, $dictStart, $position - $dictStart);

            if (!str_contains($dictionary, '/ObjStm')) {
                $offset = $position + 6;
                continue;
            }

            $dataStart = $position + 6;
            // The keyword is followed by CRLF or LF.
            if (substr($content, $dataStart, 2) === "\r\n") {
                $dataStart += 2;
            } elseif (($content[$dataStart] ?? '') === "\n" || ($content[$dataStart] ?? '') === "\r") {
                $dataStart += 1;
            }

            $endPosition = strpos($content, 'endstream', $dataStart);
            if ($endPosition === false) {
                break;
            }

            $raw = substr($content, $dataStart, $endPosition - $dataStart);
            $inflated = @gzuncompress($raw);
            if ($inflated === false) {
                // Some producers use a raw deflate stream with no zlib header.
                $inflated = @gzinflate($raw);
            }

            if (is_string($inflated) && $inflated !== '') {
                $inner = preg_match_all('/\/Type\s*\/Page(?![sA-Za-z])/', $inflated);
                if ($inner !== false) {
                    $count += $inner;
                }
            }

            $offset = $endPosition + 9;
        }

        return $count;
    }

    // -----------------------------------------------------------------
    // Office Open XML
    // -----------------------------------------------------------------

    /**
     * OOXML files are zip archives. Word and PowerPoint record a page/slide
     * count in docProps/app.xml; Excel does not, because an Excel sheet has no
     * intrinsic page count — pagination depends on print settings, column
     * widths and the paper size, and is only decided at render time.
     *
     * @return array{pages:int,source:string,detail:string}
     */
    private function countOoxmlPages(string $path, string $type): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return $this->result(0, 'unknown', 'The zip extension is required to inspect Office files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return $this->result(0, 'unknown', 'The Office file could not be opened.');
        }

        try {
            $appXml = $zip->getFromName('docProps/app.xml');

            if (is_string($appXml) && $appXml !== '') {
                if ($type === 'docx' && preg_match('#<Pages>(\d+)</Pages>#', $appXml, $m) === 1) {
                    $pages = (int) $m[1];
                    if ($pages > 0) {
                        return $this->result(
                            $pages,
                            'parsed',
                            'Read from the document properties. The final count can differ slightly if the '
                            . 'printer substitutes a font.'
                        );
                    }
                }
                if ($type === 'pptx' && preg_match('#<Slides>(\d+)</Slides>#', $appXml, $m) === 1) {
                    $slides = (int) $m[1];
                    if ($slides > 0) {
                        return $this->result($slides, 'parsed', 'One page per slide.');
                    }
                }
            }

            // PowerPoint: fall back to counting slide parts.
            if ($type === 'pptx') {
                $slides = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1) {
                        $slides++;
                    }
                }
                if ($slides > 0) {
                    return $this->result($slides, 'parsed', 'Counted the slides in the presentation.');
                }
            }

            if ($type === 'xlsx') {
                $sheets = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                        $sheets++;
                    }
                }
                return $this->result(
                    max(1, $sheets),
                    'estimated',
                    sprintf(
                        'A spreadsheet has no fixed page count — it depends on column widths and print '
                        . 'settings. Estimated as one page per sheet (%d found). The final count is '
                        . 'confirmed when the document is rendered for printing.',
                        $sheets
                    )
                );
            }

            // Word without an app.xml page count: estimate from the body text.
            if ($type === 'docx') {
                $documentXml = $zip->getFromName('word/document.xml');
                if (is_string($documentXml) && $documentXml !== '') {
                    return $this->estimateWordPages($documentXml);
                }
            }
        } finally {
            $zip->close();
        }

        return $this->result(0, 'unknown', 'The page count could not be read from this Office file.');
    }

    /**
     * Estimate Word pages from explicit page breaks and text volume. Used only
     * when the file carries no recorded page count (some generators omit it).
     *
     * @return array{pages:int,source:string,detail:string}
     */
    private function estimateWordPages(string $documentXml): array
    {
        $explicitBreaks = preg_match_all('/w:br\s[^>]*w:type="page"/', $documentXml);
        $explicitBreaks = $explicitBreaks === false ? 0 : $explicitBreaks;

        $text = strip_tags($documentXml);
        $characters = mb_strlen(trim($text));

        // ~1,800 characters per A4 page at 11pt with normal margins.
        $fromText = (int) max(1, ceil($characters / 1800));
        $pages = max($fromText, $explicitBreaks + 1);

        return $this->result(
            $pages,
            'estimated',
            sprintf(
                'This document does not record a page count, so it was estimated from %s characters of '
                . 'text and %d explicit page break%s. The exact count is confirmed when the document is '
                . 'rendered for printing.',
                number_format($characters),
                $explicitBreaks,
                $explicitBreaks === 1 ? '' : 's'
            )
        );
    }

    /**
     * Legacy binary Office formats (.doc/.xls/.ppt) are OLE compound files.
     * Their page count lives in a SummaryInformation property stream whose
     * layout is not worth parsing here, and the value is stale whenever the
     * document was edited by anything other than Word.
     *
     * The honest answer is that the count is not known until the agent renders
     * the file with LibreOffice, which it does before printing.
     *
     * @return array{pages:int,source:string,detail:string}
     */
    private function countLegacyOffice(string $path, string $extension): array
    {
        $size = (int) (@filesize($path) ?: 0);
        // Rough: a legacy .doc carries substantial per-file overhead plus
        // roughly 3 KB per page of content.
        $estimate = (int) max(1, round(max(0, $size - 12000) / 3000) + 1);

        return $this->result(
            $estimate,
            'estimated',
            sprintf(
                'The page count for legacy .%s files cannot be read reliably before rendering. This is a '
                . 'rough estimate; the exact count is measured when the document is converted to PDF for '
                . 'printing, and you will be charged on that figure.',
                $extension
            )
        );
    }

    // -----------------------------------------------------------------

    /** @return array{pages:int,source:string,detail:string} */
    private function result(int $pages, string $source, string $detail): array
    {
        $max = (int) Config::get('uploads.max_pages_per_job', 2000);
        if ($pages > $max) {
            Logger::warning('Document exceeds the page limit', ['pages' => $pages, 'max' => $max]);
        }
        return ['pages' => $pages, 'source' => $source, 'detail' => $detail];
    }

    public function canExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    /** Locate a helper binary without invoking a shell on untrusted input. */
    public function findBinary(string $name): ?string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            return null;
        }
        foreach (['/usr/bin/', '/usr/local/bin/', '/bin/', '/opt/homebrew/bin/'] as $dir) {
            if (is_executable($dir . $name)) {
                return $dir . $name;
            }
        }
        if ($this->canExec()) {
            $output = [];
            $code = 0;
            @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $output, $code);
            if ($code === 0 && isset($output[0]) && is_executable($output[0])) {
                return $output[0];
            }
        }
        return null;
    }
}
