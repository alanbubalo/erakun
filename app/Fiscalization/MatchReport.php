<?php

declare(strict_types=1);

namespace App\Fiscalization;

use App\Enums\MatchStatus;

final readonly class MatchReport
{
    /**
     * @param  list<string>  $mismatchFields
     */
    public function __construct(
        public ?SubmissionSnapshot $supplierSubmission,
        public ?SubmissionSnapshot $buyerSubmission,
        public MatchStatus $matchStatus,
        public array $mismatchFields,
    ) {}
}
