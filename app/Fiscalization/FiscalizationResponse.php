<?php

declare(strict_types=1);

namespace App\Fiscalization;

use App\Enums\MatchStatus;
use Carbon\CarbonImmutable;

final readonly class FiscalizationResponse
{
    public function __construct(
        public string $messageId,
        public CarbonImmutable $receivedAt,
        public MatchStatus $matchStatus,
    ) {}
}
