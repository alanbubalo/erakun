<?php

declare(strict_types=1);

namespace App\Fiscalization;

use Carbon\CarbonImmutable;

final readonly class SubmissionSnapshot
{
    public function __construct(
        public string $messageId,
        public string $reporterOib,
        public CarbonImmutable $receivedAt,
        public string $netAmount,
        public string $taxAmount,
        public string $totalAmount,
    ) {}
}
