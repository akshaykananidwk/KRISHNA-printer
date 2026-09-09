<?php
declare(strict_types=1);

/**
 * Printing domain configuration: paper sizes, capability profiles and the
 * defaults used by the queue.
 *
 * ---------------------------------------------------------------------------
 * ON CAPABILITY PROFILES
 * ---------------------------------------------------------------------------
 * A profile is a *declaration* drawn from the manufacturer's published
 * specification. It is never presented to a customer as a supported option on
 * its own. PrinterCapabilityService seeds these rows with source='declared';
 * the customer-facing option list is built only from rows with
 * source='probed' (returned by the printer's own IPP attributes) or
 * source='manual' (an operator ran a physical test print and confirmed it).
 *
 * That distinction is the whole point of requirement 21: a spec sheet is a
 * claim, and a claim is not a verified capability.
 */
return [
    'paper_sizes' => [
        'A4' => ['label' => 'A4', 'width_mm' => 210.0, 'height_mm' => 297.0, 'pwg' => 'iso_a4_210x297mm'],
        'A5' => ['label' => 'A5', 'width_mm' => 148.0, 'height_mm' => 210.0, 'pwg' => 'iso_a5_148x210mm'],
        'A3' => ['label' => 'A3', 'width_mm' => 297.0, 'height_mm' => 420.0, 'pwg' => 'iso_a3_297x420mm'],
        'B5' => ['label' => 'B5 (JIS)', 'width_mm' => 182.0, 'height_mm' => 257.0, 'pwg' => 'jis_b5_182x257mm'],
        'Letter' => ['label' => 'Letter', 'width_mm' => 215.9, 'height_mm' => 279.4, 'pwg' => 'na_letter_8.5x11in'],
        'Legal' => ['label' => 'Legal', 'width_mm' => 215.9, 'height_mm' => 355.6, 'pwg' => 'na_legal_8.5x14in'],
    ],

    'color_modes' => [
        'bw' => ['label' => 'Black & White', 'ipp' => 'monochrome', 'cups' => 'ColorModel=Gray'],
        'color' => ['label' => 'Colour', 'ipp' => 'color', 'cups' => 'ColorModel=RGB'],
    ],

    'duplex_modes' => [
        'single' => ['label' => 'Single Side', 'ipp' => 'one-sided', 'cups' => 'sides=one-sided'],
        'double' => ['label' => 'Double Side', 'ipp' => 'two-sided-long-edge', 'cups' => 'sides=two-sided-long-edge'],
    ],

    'orientations' => [
        'portrait' => ['label' => 'Portrait', 'ipp' => 3],   // IPP orientation-requested: 3 = portrait
        'landscape' => ['label' => 'Landscape', 'ipp' => 4], // 4 = landscape
    ],

    'copy_presets' => [1, 2, 3, 5, 10],
    'max_copies' => 100,

    'defaults' => [
        'copies' => 1,
        'color_mode' => 'bw',
        'paper_size' => 'A4',
        'orientation' => 'portrait',
        'duplex' => 'single',
        'page_range' => 'all',
    ],

    'queue' => [
        'lease_seconds' => 180,          // a claimed job is re-offered after this if the worker dies
        'max_attempts' => 3,
        'retry_backoff_seconds' => [30, 120, 600],
        'stale_processing_seconds' => 900,
        'health_check_interval' => 60,   // seconds between background printer probes
        'health_stale_after' => 180,     // a printer unseen for this long is not "online"
        'offline_failure_threshold' => 3, // consecutive send failures before marking offline
    ],

    // ---------------------------------------------------------------------
    // Model capability profiles (DECLARED — see the note above)
    // ---------------------------------------------------------------------
    'profiles' => [
        /**
         * Canon PIXMA GM4070 — the initial target printer.
         *
         * Sourced from Canon's published specification (in.canon / asia.canon,
         * PIXMA GM4070 specification page) and Canon's network protocol
         * settings documentation.
         *
         * Findings that matter to this application:
         *
         *  - It is a MONOCHROME MegaTank printer. Colour output requires the
         *    optional CL-741/CL-741XL colour cartridge to be fitted. Colour is
         *    therefore declared UNSUPPORTED by default and must be enabled per
         *    unit, after an operator confirms a cartridge is installed and a
         *    colour test page printed correctly.
         *
         *  - Maximum media width is 215.9 mm. A3 (297 mm) DOES NOT FIT and is
         *    not offered. A4/A5/B5/Letter/Legal are within the rear-tray range.
         *
         *  - Auto duplex is supported, but Canon documents it for plain paper
         *    at A4/A5/B5/Letter only — NOT Legal. duplex_paper_sizes encodes
         *    that restriction so the UI cannot offer Legal + double-sided.
         *
         *  - Network protocols exposed by the printer: LPD, RAW (port 9100),
         *    IPP/IPPS, plus WSD and Bonjour discovery.
         *
         *  - CRITICAL: this is a host-based raster printer. Canon publishes no
         *    PCL or PostScript interpreter for the PIXMA GM series. Sending a
         *    PDF to port 9100 does NOT print the document — the printer has no
         *    interpreter for it and will emit garbage pages or reject the job.
         *    Rendering must therefore happen on a host that has the Canon or
         *    IPP-Everywhere driver, which is what the location print agent
         *    does via CUPS. 'requires_rasterisation' below makes the
         *    application refuse to send an unrendered document over a raw
         *    transport rather than silently wasting the customer's paper.
         */
        'canon_gm4070' => [
            'label' => 'Canon PIXMA GM4070',
            'manufacturer' => 'Canon',
            'model' => 'PIXMA GM4070',
            'paper_sizes' => ['A4', 'A5', 'B5', 'Letter', 'Legal'],
            'default_paper_size' => 'A4',
            'color_modes' => ['bw'],
            'color_requires_option' => 'CL-741 / CL-741XL colour cartridge',
            'duplex' => true,
            'duplex_paper_sizes' => ['A4', 'A5', 'B5', 'Letter'],
            'orientations' => ['portrait', 'landscape'],
            'resolution' => '600x1200',
            'protocols' => ['ipp', 'raw9100', 'lpd'],
            'preferred_driver' => 'agent',
            'requires_rasterisation' => true,
            'max_media_width_mm' => 215.9,
            'notes' => 'Monochrome MegaTank. Colour needs the optional CL-741 cartridge. '
                . 'No PCL/PostScript interpreter: documents must be rasterised by a CUPS '
                . 'host (the location agent) before printing. A3 exceeds the 215.9 mm media width.',
            'source' => 'Canon published specification (in.canon / asia.canon PIXMA GM4070), '
                . 'Canon network protocol settings documentation.',
        ],

        /**
         * Generic IPP Everywhere / AirPrint / Mopria printer. These accept
         * PDF or PWG-Raster directly over IPP and report their own
         * capabilities, so the probe fills in the real values.
         */
        'ipp_everywhere' => [
            'label' => 'Generic IPP Everywhere / AirPrint printer',
            'manufacturer' => null,
            'model' => null,
            'paper_sizes' => ['A4', 'A5', 'Letter', 'Legal'],
            'default_paper_size' => 'A4',
            'color_modes' => ['bw'],
            'duplex' => false,
            'duplex_paper_sizes' => [],
            'orientations' => ['portrait', 'landscape'],
            'protocols' => ['ipp'],
            'preferred_driver' => 'ipp',
            'requires_rasterisation' => false,
            'notes' => 'Capabilities are read from the printer with IPP Get-Printer-Attributes; '
                . 'the declared list here is only a conservative starting point.',
            'source' => 'PWG 5100.14 IPP Everywhere.',
        ],

        /**
         * Fallback for an unrecognised printer: the smallest set that is
         * almost universally true. Everything else must be probed.
         */
        'generic' => [
            'label' => 'Generic network printer',
            'manufacturer' => null,
            'model' => null,
            'paper_sizes' => ['A4'],
            'default_paper_size' => 'A4',
            'color_modes' => ['bw'],
            'duplex' => false,
            'duplex_paper_sizes' => [],
            'orientations' => ['portrait', 'landscape'],
            'protocols' => ['ipp', 'raw9100', 'lpd'],
            'preferred_driver' => 'agent',
            'requires_rasterisation' => true,
            'notes' => 'Nothing is assumed beyond A4 mono. Run a capability probe or an '
                . 'operator test print before enabling further options.',
            'source' => 'Conservative default.',
        ],
    ],

    'default_ports' => [
        'ipp' => 631,
        'ipps' => 443,
        'raw9100' => 9100,
        'lpd' => 515,
    ],
];
