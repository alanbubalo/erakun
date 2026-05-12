<?php

declare(strict_types=1);

namespace App\As4;

use RuntimeException;

final class As4DeliveryException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $messageId,
        string $message,
        public readonly ?string $envelopeXml = null,
    ) {
        parent::__construct($message);
    }
}
