<?php

declare(strict_types=1);

namespace App\Actions;

use App\As4\AmsClient;
use App\Models\Party;

/**
 * Publishes a newly onboarded party in the AMS, so other access points can
 * discover that we serve them: OIB → our MPS base URL. Best-effort — a missing
 * MPS base URL or an unreachable AMS leaves onboarding unaffected.
 */
final readonly class RegisterParticipant
{
    public function __construct(
        private AmsClient $ams,
        private string $mpsBaseUrl,
    ) {}

    public function execute(Party $party): bool
    {
        if ($this->mpsBaseUrl === '') {
            return false;
        }

        return $this->ams->register($party->oib, $this->mpsBaseUrl);
    }
}
