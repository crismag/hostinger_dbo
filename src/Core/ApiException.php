<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** A safe API error with a stable machine-readable code. */
final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400,
    ) {
        parent::__construct($message);
    }
}
