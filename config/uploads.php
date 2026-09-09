<?php
declare(strict_types=1);

/**
 * Upload policy.
 *
 * An extension is only accepted when BOTH the extension and the sniffed MIME
 * type appear in this map for the same format. Neither alone is trusted:
 * a browser-supplied Content-Type is attacker-controlled, and an extension
 * says nothing about content.
 */
return [
    'max_file_bytes' => 25 * 1024 * 1024,       // 25 MB per file
    'max_batch_bytes' => 100 * 1024 * 1024,     // 100 MB per basket
    'max_files_per_batch' => 10,
    'max_pages_per_job' => 2000,

    'retention_hours' => 24,          // uploads are purged this long after creation
    'completed_retention_hours' => 6, // printed files are purged sooner

    // extension => list of acceptable MIME types reported by finfo
    'allowed' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => [
            'application/msword',
            'application/vnd.ms-office',
            'application/x-ole-storage',
            'application/CDFV2',
        ],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xls' => [
            'application/vnd.ms-excel',
            'application/vnd.ms-office',
            'application/x-ole-storage',
            'application/CDFV2',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'ppt' => [
            'application/vnd.ms-powerpoint',
            'application/vnd.ms-office',
            'application/x-ole-storage',
            'application/CDFV2',
        ],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
        ],
    ],

    // Formats whose page count can be determined exactly by parsing. Anything
    // else is estimated and the customer is shown the estimate before paying.
    'page_countable' => ['pdf', 'docx', 'pptx', 'jpg', 'jpeg', 'png'],

    // Office formats need conversion to PDF before they can be printed. The
    // agent does this with LibreOffice; the cloud never executes them.
    'requires_conversion' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],

    'scanning' => [
        // When a ClamAV daemon is reachable, every upload is scanned before it
        // becomes eligible for printing. Without one, uploads are still subject
        // to the structural checks in FileService (magic bytes, zip member
        // inspection, macro/JS detection) and are recorded as 'skipped'.
        'clamav_enabled' => false,
        'clamav_socket' => '/var/run/clamav/clamd.ctl',
        'clamav_host' => '127.0.0.1',
        'clamav_port' => 3310,
        'clamav_timeout' => 30,
        'block_on_scanner_error' => false,
        // Structural checks that run with or without ClamAV.
        'reject_office_macros' => true,
        'reject_pdf_javascript' => true,
        'reject_pdf_embedded_files' => true,
    ],
];
