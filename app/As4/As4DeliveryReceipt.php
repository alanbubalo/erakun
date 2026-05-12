<?php

declare(strict_types=1);

namespace App\As4;

use Carbon\CarbonImmutable;

final readonly class As4DeliveryReceipt
{
    public function __construct(
        public string $messageId,
        public string $receiptMessageId,
        public CarbonImmutable $acknowledgedAt,
        public string $envelopeXml,
        public string $receiptXml,
    ) {}
}
