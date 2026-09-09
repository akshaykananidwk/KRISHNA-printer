<?php
declare(strict_types=1);

namespace App\Services\Printer\Ipp;

final class IppException extends \RuntimeException
{
    public function __construct(string $message, private bool $permanent = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** True when retrying the same request would fail the same way. */
    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    public static function permanent(string $message): self
    {
        return new self($message, true);
    }
}
