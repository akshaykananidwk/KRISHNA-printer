<?php
declare(strict_types=1);

namespace App\Services\Printer;

/**
 * What a printer told us it can do.
 *
 * `$reported` distinguishes "the printer answered and this is its answer" from
 * "this transport cannot ask". That distinction is what keeps the application
 * from treating silence as a capability claim.
 */
final class PrinterCapabilities
{
    /**
     * @param array<int,string> $paperSizes application paper-size keys (A4, Letter, ...)
     * @param array<int,string> $colorModes bw|color
     * @param array<int,string> $duplexModes single|double
     * @param array<int,string> $orientations portrait|landscape
     * @param array<int,string> $documentFormats MIME types the printer accepts
     * @param array<string,string> $defaults capability => default value
     * @param array<string,mixed> $raw the untranslated attributes, for the audit trail
     */
    private function __construct(
        public readonly bool $reported,
        public readonly array $paperSizes = [],
        public readonly array $colorModes = [],
        public readonly array $duplexModes = [],
        public readonly array $orientations = [],
        public readonly array $documentFormats = [],
        public readonly array $defaults = [],
        public readonly array $raw = [],
        public readonly ?string $message = null,
        public readonly ?string $makeAndModel = null,
    ) {
    }

    /**
     * @param array<int,string> $paperSizes
     * @param array<int,string> $colorModes
     * @param array<int,string> $duplexModes
     * @param array<int,string> $orientations
     * @param array<int,string> $documentFormats
     * @param array<string,string> $defaults
     * @param array<string,mixed> $raw
     */
    public static function reported(
        array $paperSizes,
        array $colorModes,
        array $duplexModes,
        array $orientations = ['portrait', 'landscape'],
        array $documentFormats = [],
        array $defaults = [],
        array $raw = [],
        ?string $makeAndModel = null
    ): self {
        return new self(
            true,
            array_values(array_unique($paperSizes)),
            array_values(array_unique($colorModes)),
            array_values(array_unique($duplexModes)),
            array_values(array_unique($orientations)),
            array_values(array_unique($documentFormats)),
            $defaults,
            $raw,
            null,
            $makeAndModel
        );
    }

    /**
     * The transport cannot ask the printer what it supports.
     *
     * RAW/9100 is a byte pipe with no query channel, and LPD's only status
     * command returns a free-text queue listing, not a capability set. Callers
     * must treat this as "unknown" and fall back to a probe over IPP or to an
     * operator's manual verification — never to an assumption.
     */
    public static function unsupported(string $message): self
    {
        return new self(false, message: $message);
    }

    public function supportsColor(): bool
    {
        return in_array('color', $this->colorModes, true);
    }

    public function supportsDuplex(): bool
    {
        return in_array('double', $this->duplexModes, true);
    }

    public function acceptsFormat(string $mime): bool
    {
        return $this->documentFormats === [] || in_array($mime, $this->documentFormats, true);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'reported' => $this->reported,
            'paper_sizes' => $this->paperSizes,
            'color_modes' => $this->colorModes,
            'duplex_modes' => $this->duplexModes,
            'orientations' => $this->orientations,
            'document_formats' => $this->documentFormats,
            'defaults' => $this->defaults,
            'make_and_model' => $this->makeAndModel,
            'message' => $this->message,
        ];
    }
}
