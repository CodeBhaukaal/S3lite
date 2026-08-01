<?php
declare(strict_types=1);

namespace App\Http\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        private int $status,
        string $message,
        private string $errorCode = 'error',
        private array $details = []
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): array
    {
        return $this->details;
    }
}
