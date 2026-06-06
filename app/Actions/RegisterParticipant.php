<?php

declare(strict_types=1);

namespace App\Actions;

use App\As4\AmsClient;
use App\Models\Taxpayer;

/**
 * Publishes a newly onboarded taxpayer in the AMS, so other access points can
 * discover that we serve them: OIB → our MPS base URL. Best-effort — a missing
 * MPS base URL or an unreachable AMS leaves onboarding unaffected.
 */
final readonly class RegisterParticipant
{
    public function __construct(
        private AmsClient $ams,
        private string $mpsBaseUrl,
    ) {}

    public function execute(Taxpayer $taxpayer): bool
    {
        if ($this->mpsBaseUrl === '') {
            return false;
        }

        return $this->ams->register($taxpayer->oib, $this->mpsBaseUrl);
    }
}
