<?php
declare(strict_types=1);

namespace App\Services\Printer;

/** Result of a health probe. */
final class PrinterStatus
{
    public const ONLINE = 'online';
    public const OFFLINE = 'offline';
    public const UNKNOWN = 'unknown';
    public const ERROR = 'error';

    /** @param array<int,string> $stateReasons */
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?int $latencyMs = null,
        public readonly array $stateReasons = [],
        public readonly ?string $driver = null,
        public readonly bool $acceptingJobs = true,
    ) {
    }

    /** @param array<int,string> $stateReasons */
    public static function online(
        string $message = 'Printer responded.',
        ?int $latencyMs = null,
        array $stateReasons = [],
        ?string $driver = null,
        bool $acceptingJobs = true
    ): self {
        return new self(self::ONLINE, $message, $latencyMs, $stateReasons, $driver, $acceptingJobs);
    }

    public static function offline(string $message, ?int $latencyMs = null, ?string $driver = null): self
    {
        return new self(self::OFFLINE, $message, $latencyMs, [], $driver, false);
    }

    /** @param array<int,string> $stateReasons */
    public static function error(string $message, array $stateReasons = [], ?string $driver = null): self
    {
        return new self(self::ERROR, $message, null, $stateReasons, $driver, false);
    }

    public static function unknown(string $message, ?string $driver = null): self
    {
        return new self(self::UNKNOWN, $message, null, [], $driver, false);
    }

    public function isOnline(): bool
    {
        return $this->status === self::ONLINE;
    }

    /**
     * Whether a customer may be offered this printer right now.
     *
     * "Online" alone is not enough: a printer that answers the network but
     * reports media-empty or a cover open will not produce the document, so we
     * refuse the upload rather than take money for paper that never comes out.
     */
    public function isUsable(): bool
    {
        return $this->isOnline() && $this->acceptingJobs && !$this->hasBlockingReason();
    }

    public function hasBlockingReason(): bool
    {
        foreach ($this->stateReasons as $reason) {
            $normalised = strtolower(trim($reason));
            // IPP printer-state-reasons values that mean "will not print now".
            foreach (self::BLOCKING_REASONS as $blocking) {
                if (str_starts_with($normalised, $blocking)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** IPP printer-state-reasons prefixes that block printing (RFC 8011 §5.4.12). */
    private const BLOCKING_REASONS = [
        'media-empty',
        'media-jam',
        'media-needed',
        'toner-empty',
        'marker-supply-empty',
        'cover-open',
        'door-open',
        'input-tray-missing',
        'output-tray-missing',
        'output-area-full',
        'shutdown',
        'stopped-partly',
        'paused',
        'spool-area-full',
        'offline',
        'timed-out',
    ];

    /** Reasons worth showing an operator even though printing can continue. */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->stateReasons as $reason) {
            $normalised = strtolower(trim($reason));
            if ($normalised === 'none' || $normalised === '') {
                continue;
            }
            if (str_ends_with($normalised, '-warning') || str_ends_with($normalised, '-low')) {
                $warnings[] = $reason;
            }
        }
        return $warnings;
    }

    public function reasonSummary(): ?string
    {
        $meaningful = array_values(array_filter(
            $this->stateReasons,
            static fn (string $r): bool => strtolower(trim($r)) !== 'none' && trim($r) !== ''
        ));
        return $meaningful === [] ? null : implode(', ', $meaningful);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'latency_ms' => $this->latencyMs,
            'state_reasons' => $this->stateReasons,
            'driver' => $this->driver,
            'accepting_jobs' => $this->acceptingJobs,
            'usable' => $this->isUsable(),
        ];
    }
}
