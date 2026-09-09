<?php
declare(strict_types=1);

namespace App\Services\Printer;

/** Outcome of handing a document to a transport. */
final class SubmitResult
{
    /** @param array<string,mixed> $context */
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $remoteJobId,
        public readonly string $message,
        public readonly bool $retryable = true,
        public readonly ?string $errorCode = null,
        public readonly array $context = [],
        /**
         * True only when the transport confirmed the printer took the job.
         * A byte-pipe transport (RAW/9100) can report `accepted` because the
         * socket write succeeded, but cannot report `confirmed` — nothing came
         * back. The queue uses this to decide whether "printing" is a fact or
         * an assumption.
         */
        public readonly bool $confirmed = false,
    ) {
    }

    /** @param array<string,mixed> $context */
    public static function accepted(
        ?string $remoteJobId,
        string $message = 'Job accepted by printer.',
        bool $confirmed = true,
        array $context = []
    ): self {
        return new self(true, $remoteJobId, $message, true, null, $context, $confirmed);
    }

    /** A failure worth retrying: transient network trouble, printer busy. */
    public static function transientFailure(string $message, ?string $errorCode = null, array $context = []): self
    {
        return new self(false, null, $message, true, $errorCode, $context);
    }

    /**
     * A failure that will not fix itself: unsupported format, bad credentials,
     * a document the printer cannot interpret. Retrying only wastes paper and
     * time, so the queue fails the job immediately.
     */
    public static function permanentFailure(string $message, ?string $errorCode = null, array $context = []): self
    {
        return new self(false, null, $message, false, $errorCode, $context);
    }
}
