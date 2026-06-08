<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Certificate;
use App\Models\Party;
use App\Pki\TestPkiGenerator;

/**
 * Issues a fresh signing certificate for a party off the test FINA-like CA and
 * registers it as the party's active certificate. The convenience path for dev
 * and tests, where real FINA-issued material isn't available to upload.
 */
final readonly class IssuePartyCertificate
{
    public function __construct(
        private TestPkiGenerator $generator,
        private StoreCertificate $store,
    ) {}

    public function execute(Party $party): Certificate
    {
        $credential = $this->generator->issuePartyCertificate($party->oib, $party->name);

        return $this->store->store($party, $credential);
    }
}
