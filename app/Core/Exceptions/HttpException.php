<?php
declare(strict_types=1);

namespace App\Core\Exceptions;

class HttpException extends \RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(
        private int $statusCode,
        string $message = '',
        private array $headers = []
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($statusCode), $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    private static function defaultMessage(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            413 => 'Payload Too Large',
            419 => 'Session Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            503 => 'Service Unavailable',
            default => 'Server Error',
        };
    }
}
