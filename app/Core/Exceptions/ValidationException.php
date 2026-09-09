<?php
declare(strict_types=1);

namespace App\Core\Exceptions;

final class ValidationException extends HttpException
{
    /** @param array<string,string> $errors */
    public function __construct(private array $errors, string $message = 'The submitted data is invalid.')
    {
        parent::__construct(422, $message);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        return (string) (reset($this->errors) ?: $this->getMessage());
    }
}
