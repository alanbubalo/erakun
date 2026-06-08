<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * The result of attempting a one-way submission (AS4 delivery, fiscalization):
 * whether work was actually performed, the call was a no-op because the channel
 * is already in a terminal/accepted state, or there was nothing to submit.
 */
enum SubmissionOutcome
{
    case Submitted;
    case AlreadyTerminal;
    case Skipped;
}
