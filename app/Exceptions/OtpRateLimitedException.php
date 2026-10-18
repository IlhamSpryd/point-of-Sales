<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OtpRateLimitedException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        string $message,
        public int $retryAfterSeconds = 60,
        int $code = 429,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return 429;
    }

    public function getHeaders(): array
    {
        return [
            'Retry-After' => (string) $this->retryAfterSeconds,
        ];
    }
}
