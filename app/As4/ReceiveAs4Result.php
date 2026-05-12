<?php

declare(strict_types=1);

namespace App\As4;

use App\Models\As4Message;

final readonly class ReceiveAs4Result
{
    public function __construct(
        public string $responseXml,
        public int $httpStatus,
        public ?As4Message $message = null,
    ) {}
}
