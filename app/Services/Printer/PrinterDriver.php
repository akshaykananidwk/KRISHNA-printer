<?php
declare(strict_types=1);

namespace App\Services\Printer;

/**
 * Contract every printer transport implements.
 *
 * Deliberately narrow: probe reachability, read capabilities, submit a job,
 * query a submitted job. Anything a transport genuinely cannot do reports
 * PrinterCapabilities::unsupported() rather than pretending — a driver that
 * silently claims success is worse than one that refuses.
 */
interface PrinterDriver
{
    /** Machine name matching printer_connections.driver. */
    public function name(): string;

    /** Human-readable transport description for the admin UI. */
    public function description(): string;

    /**
     * Test reachability. Must perform real I/O; must never return a cached or
     * assumed result.
     */
    public function probe(PrinterTarget $target): PrinterStatus;

    /**
     * Ask the printer what it supports. Returns unsupported() when the
     * transport has no capability-reporting mechanism (RAW/9100 and LPD do
     * not), so the caller knows the answer is "unknown", not "nothing".
     */
    public function capabilities(PrinterTarget $target): PrinterCapabilities;

    /**
     * Submit a document.
     *
     * @param string $documentPath absolute path to a print-ready file
     */
    public function submit(PrinterTarget $target, PrintJobSpec $spec, string $documentPath): SubmitResult;

    /** Poll a previously submitted job, where the transport supports it. */
    public function jobStatus(PrinterTarget $target, string $remoteJobId): JobStatusResult;

    /**
     * Whether this transport can accept the given document format directly.
     * Used to refuse sending a PDF to a host-based printer that has no
     * interpreter for it.
     */
    public function acceptsFormat(PrinterTarget $target, string $extension): bool;
}
