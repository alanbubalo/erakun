<?php

declare(strict_types=1);

namespace App\Fiscalization;

use RuntimeException;

final class FiscalizationServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
