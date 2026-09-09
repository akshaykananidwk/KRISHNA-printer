<?php
declare(strict_types=1);

namespace App\Services\Printer;

use App\Core\Config;
use App\Support\PageRange;

/** The print options for one job, in transport-neutral terms. */
final class PrintJobSpec
{
    public function __construct(
        public readonly string $jobNumber,
        public readonly int $copies = 1,
        public readonly string $colorMode = 'bw',
        public readonly string $paperSize = 'A4',
        public readonly string $orientation = 'portrait',
        public readonly string $duplex = 'single',
        public readonly string $pageRange = 'all',
        public readonly int $documentPages = 0,
        public readonly string $documentName = 'document',
        public readonly string $mimeType = 'application/pdf',
        public readonly string $userName = 'kpms',
    ) {
    }

    /** @param array<string,mixed> $job a print_jobs row */
    public static function fromJobRow(array $job): self
    {
        return new self(
            jobNumber: (string) ($job['job_number'] ?? 'unknown'),
            copies: max(1, (int) ($job['copies'] ?? 1)),
            colorMode: (string) ($job['color_mode'] ?? 'bw'),
            paperSize: (string) ($job['paper_size'] ?? 'A4'),
            orientation: (string) ($job['orientation'] ?? 'portrait'),
            duplex: (string) ($job['duplex'] ?? 'single'),
            pageRange: (string) ($job['page_range'] ?? 'all'),
            documentPages: (int) ($job['document_pages'] ?? 0),
            documentName: (string) ($job['original_name'] ?? 'document'),
            mimeType: (string) ($job['mime_type'] ?? 'application/pdf'),
        );
    }

    /**
     * Translate to IPP job-template attributes (RFC 8011 §5.2).
     *
     * @return array<string,mixed>
     */
    public function toIppAttributes(): array
    {
        $sizes = (array) Config::get('printing.paper_sizes', []);
        $colors = (array) Config::get('printing.color_modes', []);
        $duplexes = (array) Config::get('printing.duplex_modes', []);
        $orientations = (array) Config::get('printing.orientations', []);

        $attributes = [
            'copies' => $this->copies,
            'print-color-mode' => (string) ($colors[$this->colorMode]['ipp'] ?? 'monochrome'),
            'sides' => (string) ($duplexes[$this->duplex]['ipp'] ?? 'one-sided'),
            'orientation-requested' => (int) ($orientations[$this->orientation]['ipp'] ?? 3),
            'media' => (string) ($sizes[$this->paperSize]['pwg'] ?? 'iso_a4_210x297mm'),
        ];

        $ranges = PageRange::toIppRanges($this->pageRange, $this->documentPages);
        if ($ranges !== []) {
            $attributes['page-ranges'] = $ranges;
        }

        return $attributes;
    }

    /**
     * Translate to CUPS `lp -o` options, used by the location agent.
     *
     * @return array<int,string>
     */
    public function toCupsOptions(): array
    {
        $sizes = (array) Config::get('printing.paper_sizes', []);
        $options = [
            'media=' . ((string) ($sizes[$this->paperSize]['pwg'] ?? 'iso_a4_210x297mm')),
            'sides=' . ($this->duplex === 'double' ? 'two-sided-long-edge' : 'one-sided'),
            'print-color-mode=' . ($this->colorMode === 'color' ? 'color' : 'monochrome'),
            'orientation-requested=' . ($this->orientation === 'landscape' ? '4' : '3'),
            'ColorModel=' . ($this->colorMode === 'color' ? 'RGB' : 'Gray'),
        ];

        $normalised = PageRange::normalise($this->pageRange, $this->documentPages);
        if ($normalised !== '' && $normalised !== PageRange::ALL) {
            $options[] = 'page-ranges=' . $normalised;
        }

        return $options;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'job_number' => $this->jobNumber,
            'copies' => $this->copies,
            'color_mode' => $this->colorMode,
            'paper_size' => $this->paperSize,
            'orientation' => $this->orientation,
            'duplex' => $this->duplex,
            'page_range' => $this->pageRange,
            'document_pages' => $this->documentPages,
            'document_name' => $this->documentName,
            'mime_type' => $this->mimeType,
            'ipp_attributes' => $this->toIppAttributes(),
            'cups_options' => $this->toCupsOptions(),
        ];
    }
}
