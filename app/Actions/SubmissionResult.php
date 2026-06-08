<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Database\Eloquent\Model;

/**
 * Outcome of a submission action plus the message row it produced or found.
 * Lets HTTP callers map the outcome to a status code (200 vs 409) without
 * re-deriving the "already terminal" rule the action already enforces.
 */
final readonly class SubmissionResult
{
    public function __construct(
        public SubmissionOutcome $outcome,
        public ?Model $message = null,
    ) {}
}
