<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Money;

final class PrintJob extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAYMENT_PENDING = 'payment_pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PRINTING = 'printing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAYMENT_PENDING,
        self::STATUS_PAID,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_PRINTING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Legal status transitions. The queue enforces this map, so a job can
     * never jump from, say, `pending` straight to `completed` — every state
     * change is auditable and no unpaid job can reach a printer.
     *
     * @var array<string,array<int,string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_PAYMENT_PENDING, self::STATUS_QUEUED, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_PAYMENT_PENDING => [self::STATUS_PAID, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_PAID => [self::STATUS_QUEUED, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_QUEUED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED, self::STATUS_FAILED],
        self::STATUS_PROCESSING => [self::STATUS_PRINTING, self::STATUS_QUEUED, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_PRINTING => [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [self::STATUS_QUEUED, self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function status(): string
    {
        return $this->string('status', self::STATUS_PENDING);
    }

    public function jobNumber(): string
    {
        return $this->string('job_number');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status(), [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status(), [
            self::STATUS_PENDING,
            self::STATUS_PAYMENT_PENDING,
            self::STATUS_PAID,
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
        ], true);
    }

    public function isRetryable(): bool
    {
        return $this->status() === self::STATUS_FAILED
            && $this->int('attempts') < $this->int('max_attempts', 3);
    }

    /** A job may only reach a printer once payment is settled or not required. */
    public function isPrintable(): bool
    {
        $payment = $this->string('payment_status', 'not_required');
        return in_array($payment, ['not_required', 'paid'], true);
    }

    public function totalRupees(): string
    {
        return Money::format($this->int('total_paise'));
    }

    public function colorLabel(): string
    {
        return $this->string('color_mode') === 'color' ? 'Colour' : 'Black & White';
    }

    public function duplexLabel(): string
    {
        return $this->string('duplex') === 'double' ? 'Double Side' : 'Single Side';
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PAYMENT_PENDING => 'Awaiting Payment',
            self::STATUS_PAID => 'Paid',
            self::STATUS_QUEUED => 'Queued',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_PRINTING => 'Printing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($this->status()),
        };
    }

    public function statusTone(): string
    {
        return match ($this->status()) {
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'muted',
            self::STATUS_PRINTING, self::STATUS_PROCESSING => 'info',
            self::STATUS_PAYMENT_PENDING => 'warning',
            default => 'neutral',
        };
    }
}
