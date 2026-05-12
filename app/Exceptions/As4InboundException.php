<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class As4InboundException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $refToMessageId = null,
    ) {
        parent::__construct($message);
    }
}
