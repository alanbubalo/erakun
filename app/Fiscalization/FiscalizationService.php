<?php

declare(strict_types=1);

namespace App\Fiscalization;

interface FiscalizationService
{
    /**
     * @throws FiscalizationServiceException on validation or business rejection
     */
    public function fiscalize(string $signedRequestXml): FiscalizationResponse;

    public function lookupMatch(
        string $supplierOib,
        string $buyerOib,
        string $invoiceNumber,
    ): MatchReport;
}
