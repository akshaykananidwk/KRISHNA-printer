<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Printer;
use App\Repositories\PrinterCapabilityRepository;
use App\Repositories\PrinterRepository;

/**
 * Owns the capability lifecycle, and the rule that gives requirement 21 its
 * teeth:
 *
 *   A capability is only offered to a customer once it has been VERIFIED —
 *   either the printer reported it over IPP ('probed'), or an operator ran a
 *   physical test print and confirmed it ('manual').
 *
 * A manufacturer's datasheet enters the system as 'declared'. Declared rows
 * seed the admin screen and tell an operator what to test, but they never
 * reach the customer's option list. This is what stops the application from
 * selling a colour print on a monochrome GM4070, or A3 on a printer whose
 * paper path stops at 215.9 mm.
 */
final class PrinterCapabilityService
{
    public function __construct(
        private PrinterCapabilityRepository $capabilities,
        private PrinterRepository $printers,
        private PrinterService $printerService,
        private AuditService $audit
    ) {
    }

    /**
     * Seed the declared capability set from the model profile. Called when a
     * printer is created or its profile changes.
     */
    public function seedFromProfile(Printer $printer): void
    {
        $profile = $printer->profile();
        if ($profile === []) {
            return;
        }

        $evidence = 'Declared by the model profile "' . $printer->string('capability_profile') . '". Source: '
            . (string) ($profile['source'] ?? 'built-in profile');

        $defaultSize = (string) ($profile['default_paper_size'] ?? 'A4');
        foreach ((array) ($profile['paper_sizes'] ?? []) as $size) {
            $this->capabilities->record(
                $printer->id(),
                'paper_size',
                (string) $size,
                true,
                'declared',
                (string) $size === $defaultSize,
                null,
                $evidence
            );
        }

        foreach (['bw', 'color'] as $mode) {
            $supported = in_array($mode, (array) ($profile['color_modes'] ?? ['bw']), true);
            $note = $evidence;
            if ($mode === 'color' && !$supported && isset($profile['color_requires_option'])) {
                $note .= ' Colour output requires the optional ' . $profile['color_requires_option']
                    . '; enable it only after fitting one and confirming a colour test print.';
            }
            $this->capabilities->record(
                $printer->id(),
                'color_mode',
                $mode,
                $supported,
                'declared',
                $mode === 'bw',
                null,
                $note
            );
        }

        $duplexSupported = (bool) ($profile['duplex'] ?? false);
        $this->capabilities->record($printer->id(), 'duplex', 'single', true, 'declared', true, null, $evidence);

        $duplexNote = $evidence;
        if ($duplexSupported && !empty($profile['duplex_paper_sizes'])) {
            $duplexNote .= ' Auto-duplex is documented only for: '
                . implode(', ', (array) $profile['duplex_paper_sizes']) . '.';
        }
        $this->capabilities->record(
            $printer->id(),
            'duplex',
            'double',
            $duplexSupported,
            'declared',
            false,
            null,
            $duplexNote
        );

        foreach ((array) ($profile['orientations'] ?? ['portrait', 'landscape']) as $orientation) {
            $this->capabilities->record(
                $printer->id(),
                'orientation',
                (string) $orientation,
                true,
                'declared',
                (string) $orientation === 'portrait',
                null,
                $evidence
            );
        }
    }

    /**
     * Ask the printer what it supports and record the answer as 'probed'.
     *
     * @return array{success:bool,message:string,recorded:int,capabilities:array<string,mixed>}
     */
    public function probe(Printer $printer, ?int $adminId = null): array
    {
        $capabilities = $this->printerService->capabilities($printer);

        if (!$capabilities->reported) {
            return [
                'success' => false,
                'message' => $capabilities->message
                    ?? 'This printer\'s transport cannot report capabilities.',
                'recorded' => 0,
                'capabilities' => [],
            ];
        }

        // Drop previous probe results first, so a capability the printer no
        // longer reports (a colour cartridge removed, a tray taken out)
        // disappears instead of lingering as a stale "verified" option.
        $this->capabilities->clearProbed($printer->id());

        $recorded = 0;
        $timestamp = gmdate('c');
        $evidence = 'Reported by the printer at ' . $timestamp
            . ($capabilities->makeAndModel !== null ? ' (' . $capabilities->makeAndModel . ')' : '');

        foreach ($capabilities->paperSizes as $size) {
            $this->capabilities->record(
                $printer->id(),
                'paper_size',
                $size,
                true,
                'probed',
                ($capabilities->defaults['paper_size'] ?? '') === $size,
                $adminId,
                $evidence . ' — media-supported'
            );
            $recorded++;
        }

        foreach ($capabilities->colorModes as $mode) {
            $this->capabilities->record(
                $printer->id(),
                'color_mode',
                $mode,
                true,
                'probed',
                ($capabilities->defaults['color_mode'] ?? '') === $mode,
                $adminId,
                $evidence . ' — print-color-mode-supported'
            );
            $recorded++;
        }

        foreach ($capabilities->duplexModes as $mode) {
            $this->capabilities->record(
                $printer->id(),
                'duplex',
                $mode,
                true,
                'probed',
                ($capabilities->defaults['duplex'] ?? '') === $mode,
                $adminId,
                $evidence . ' — sides-supported'
            );
            $recorded++;
        }

        foreach ($capabilities->orientations as $orientation) {
            $this->capabilities->record(
                $printer->id(),
                'orientation',
                $orientation,
                true,
                'probed',
                $orientation === 'portrait',
                $adminId,
                $evidence . ' — orientation-requested-supported'
            );
            $recorded++;
        }

        $this->printers->updateById($printer->id(), [
            'capabilities_verified_at' => gmdate('Y-m-d H:i:s'),
            'supports_color' => $capabilities->supportsColor() ? 1 : 0,
            'supports_duplex' => $capabilities->supportsDuplex() ? 1 : 0,
        ]);

        $this->audit->log(
            'printer.capabilities_probed',
            sprintf(
                'Probed capabilities for "%s": %d paper sizes, colour=%s, duplex=%s',
                $printer->string('name'),
                count($capabilities->paperSizes),
                $capabilities->supportsColor() ? 'yes' : 'no',
                $capabilities->supportsDuplex() ? 'yes' : 'no'
            ),
            'printer',
            (string) $printer->id(),
            'info',
            $capabilities->toArray()
        );

        return [
            'success' => true,
            'message' => sprintf('Recorded %d verified capabilities from the printer.', $recorded),
            'recorded' => $recorded,
            'capabilities' => $capabilities->toArray(),
        ];
    }

    /**
     * An operator confirms a capability by hand after a physical test print.
     * This is the escape hatch for transports that cannot self-report, and it
     * outranks a probe because a human watched paper come out.
     */
    public function recordManualVerification(
        Printer $printer,
        string $capability,
        string $value,
        bool $isSupported,
        int $adminId,
        string $note
    ): void {
        $this->capabilities->record(
            $printer->id(),
            $capability,
            $value,
            $isSupported,
            'manual',
            false,
            $adminId,
            'Operator test print at ' . gmdate('c') . ': ' . $note
        );

        if ($capability === 'color_mode' && $value === 'color') {
            $this->printers->updateById($printer->id(), ['supports_color' => $isSupported ? 1 : 0]);
        }
        if ($capability === 'duplex' && $value === 'double') {
            $this->printers->updateById($printer->id(), ['supports_duplex' => $isSupported ? 1 : 0]);
        }

        $this->refreshVerifiedFlag($printer);

        $this->audit->log(
            'printer.capability_verified',
            sprintf(
                'Operator %s %s=%s for printer "%s": %s',
                $isSupported ? 'confirmed' : 'ruled out',
                $capability,
                $value,
                $printer->string('name'),
                $note
            ),
            'printer',
            (string) $printer->id(),
            'notice'
        );
    }

    private function refreshVerifiedFlag(Printer $printer): void
    {
        if ($this->capabilities->countVerified($printer->id()) > 0) {
            $this->printers->updateById($printer->id(), ['capabilities_verified_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    /**
     * The options a customer may actually be shown for this printer.
     *
     * Only verified capabilities are included. When nothing is verified yet the
     * lists come back EMPTY — the customer flow treats that as "this printer is
     * not ready to take jobs" rather than falling back to a guess.
     *
     * @return array{
     *   paper_sizes:array<int,array{value:string,label:string}>,
     *   color_modes:array<int,array{value:string,label:string}>,
     *   duplex_modes:array<int,array{value:string,label:string}>,
     *   orientations:array<int,array{value:string,label:string}>,
     *   duplex_paper_sizes:array<int,string>,
     *   defaults:array<string,string>,
     *   verified:bool,
     *   warnings:array<int,string>
     * }
     */
    public function customerOptions(Printer $printer): array
    {
        $paperSizes = $this->capabilities->verifiedValues($printer->id(), 'paper_size');
        $colorModes = $this->capabilities->verifiedValues($printer->id(), 'color_mode');
        $duplexModes = $this->capabilities->verifiedValues($printer->id(), 'duplex');
        $orientations = $this->capabilities->verifiedValues($printer->id(), 'orientation');

        $sizeMeta = (array) Config::get('printing.paper_sizes', []);
        $colorMeta = (array) Config::get('printing.color_modes', []);
        $duplexMeta = (array) Config::get('printing.duplex_modes', []);
        $orientationMeta = (array) Config::get('printing.orientations', []);

        $warnings = [];
        $profile = $printer->profile();

        // Auto-duplex is often restricted to a subset of paper sizes. The
        // profile records that restriction; without it, the UI could offer
        // Legal + double-sided on a printer that jams on exactly that.
        $duplexPaperSizes = (array) ($profile['duplex_paper_sizes'] ?? []);
        if ($duplexPaperSizes === []) {
            $duplexPaperSizes = $paperSizes;
        } else {
            $duplexPaperSizes = array_values(array_intersect($duplexPaperSizes, $paperSizes));
        }

        if ($paperSizes === []) {
            $warnings[] = 'No paper size has been verified for this printer yet.';
        }
        if ($colorModes === []) {
            $warnings[] = 'No colour mode has been verified for this printer yet.';
        }

        $order = array_keys($sizeMeta);
        usort($paperSizes, static fn (string $a, string $b): int =>
            (array_search($a, $order, true) ?: 99) <=> (array_search($b, $order, true) ?: 99));

        return [
            'paper_sizes' => array_map(
                static fn (string $s): array => ['value' => $s, 'label' => (string) ($sizeMeta[$s]['label'] ?? $s)],
                $paperSizes
            ),
            'color_modes' => array_map(
                static fn (string $m): array => ['value' => $m, 'label' => (string) ($colorMeta[$m]['label'] ?? $m)],
                $colorModes
            ),
            'duplex_modes' => array_map(
                static fn (string $d): array => ['value' => $d, 'label' => (string) ($duplexMeta[$d]['label'] ?? $d)],
                $duplexModes
            ),
            'orientations' => array_map(
                static fn (string $o): array => ['value' => $o, 'label' => (string) ($orientationMeta[$o]['label'] ?? $o)],
                $orientations !== [] ? $orientations : ['portrait']
            ),
            'duplex_paper_sizes' => $duplexPaperSizes,
            'defaults' => [
                'paper_size' => $this->capabilities->defaultValue($printer->id(), 'paper_size')
                    ?? ($paperSizes[0] ?? 'A4'),
                'color_mode' => $this->capabilities->defaultValue($printer->id(), 'color_mode')
                    ?? ($colorModes[0] ?? 'bw'),
                'duplex' => $this->capabilities->defaultValue($printer->id(), 'duplex')
                    ?? ($duplexModes[0] ?? 'single'),
                'orientation' => 'portrait',
            ],
            'verified' => $paperSizes !== [] && $colorModes !== [],
            'warnings' => $warnings,
        ];
    }

    /**
     * Reject a print configuration the printer has not been verified to
     * support. Called before pricing and again before the job is created, so a
     * tampered form cannot smuggle an unsupported option through.
     *
     * @return array<string,string> field => error, empty when acceptable
     */
    public function validateSelection(Printer $printer, array $selection): array
    {
        $errors = [];
        $options = $this->customerOptions($printer);

        $paperSize = (string) ($selection['paper_size'] ?? '');
        $colorMode = (string) ($selection['color_mode'] ?? '');
        $duplex = (string) ($selection['duplex'] ?? 'single');
        $orientation = (string) ($selection['orientation'] ?? 'portrait');

        $validSizes = array_column($options['paper_sizes'], 'value');
        $validColors = array_column($options['color_modes'], 'value');
        $validDuplex = array_column($options['duplex_modes'], 'value');
        $validOrientations = array_column($options['orientations'], 'value');

        if (!in_array($paperSize, $validSizes, true)) {
            $errors['paper_size'] = $validSizes === []
                ? 'This printer has no verified paper sizes yet, so it cannot accept jobs.'
                : 'This printer does not support ' . $paperSize . '. Supported: ' . implode(', ', $validSizes) . '.';
        }

        if (!in_array($colorMode, $validColors, true)) {
            $profile = $printer->profile();
            $hint = ($colorMode === 'color' && isset($profile['color_requires_option']))
                ? ' Colour needs the optional ' . $profile['color_requires_option'] . '.'
                : '';
            $errors['color_mode'] = 'This printer does not support '
                . ($colorMode === 'color' ? 'colour' : 'black & white') . ' printing.' . $hint;
        }

        if (!in_array($duplex, $validDuplex, true)) {
            $errors['duplex'] = $duplex === 'double'
                ? 'This printer does not support double-sided printing.'
                : 'This printer does not support the selected duplex mode.';
        } elseif ($duplex === 'double' && !in_array($paperSize, $options['duplex_paper_sizes'], true)) {
            $errors['duplex'] = sprintf(
                'Double-sided printing is not available on %s for this printer. Available on: %s.',
                $paperSize,
                implode(', ', $options['duplex_paper_sizes']) ?: 'no paper size'
            );
        }

        if (!in_array($orientation, $validOrientations, true)) {
            $errors['orientation'] = 'This printer does not support ' . $orientation . ' orientation.';
        }

        return $errors;
    }

    /**
     * The admin capability matrix: every value, with its source and evidence,
     * so an operator can see exactly what is verified and what is only claimed.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function adminMatrix(Printer $printer): array
    {
        $matrix = [];
        foreach (['paper_size', 'color_mode', 'duplex', 'orientation'] as $capability) {
            $matrix[$capability] = $this->capabilities->allValues($printer->id(), $capability);
        }
        return $matrix;
    }
}
