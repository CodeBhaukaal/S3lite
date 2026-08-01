<?php
declare(strict_types=1);

namespace App\Http\Exceptions;

final class ValidationException extends HttpException
{
    public function __construct(array $errors, string $message = 'The given data was invalid.')
    {
        parent::__construct(422, $message, 'validation_failed', $errors);
    }

    public function errors(): array
    {
        return $this->details();
    }
}
