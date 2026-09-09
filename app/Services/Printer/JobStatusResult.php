<?php
declare(strict_types=1);

namespace App\Services\Printer;

/** Status of a job previously submitted to a printer. */
final class JobStatusResult
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const ABORTED = 'aborted';
    public const CANCELLED = 'cancelled';
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $state,
        public readonly string $message,
        public readonly bool $known = true,
        public readonly ?int $ippState = null,
    ) {
    }

    public static function of(string $state, string $message = '', ?int $ippState = null): self
    {
        return new self($state, $message, true, $ippState);
    }

    /** The transport has no way to report job state (RAW/9100, LPD). */
    public static function unknown(string $message): self
    {
        return new self(self::UNKNOWN, $message, false);
    }

    /** Map IPP job-state (RFC 8011 §5.3.7) to our vocabulary. */
    public static function fromIppState(int $ippState): self
    {
        return match ($ippState) {
            3 => self::of(self::PENDING, 'Pending', $ippState),
            4 => self::of(self::PENDING, 'Pending, held', $ippState),
            5 => self::of(self::PROCESSING, 'Processing', $ippState),
            6 => self::of(self::PROCESSING, 'Processing, stopped', $ippState),
            7 => self::of(self::CANCELLED, 'Cancelled', $ippState),
            8 => self::of(self::ABORTED, 'Aborted by the printer', $ippState),
            9 => self::of(self::COMPLETED, 'Completed', $ippState),
            default => self::of(self::UNKNOWN, 'Unrecognised job state: ' . $ippState, $ippState),
        };
    }

    public function isFinished(): bool
    {
        return in_array($this->state, [self::COMPLETED, self::ABORTED, self::CANCELLED], true);
    }

    public function isSuccessful(): bool
    {
        return $this->state === self::COMPLETED;
    }
}
