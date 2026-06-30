<?php

declare(strict_types=1);

namespace App\Pki;

use App\Exceptions\MissingSigningCertificateException;
use App\Models\Party;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Resolves the signing credential a party signs documents with: its single
 * active certificate's key + cert. Throws if the party has no active
 * certificate — a document must never go out unsigned.
 */
final class PartySigningCredentials
{
    public function for(Party $party): SigningCredential
    {
        $certificate = $party->activeCertificate()->first();

        throw_if($certificate === null, MissingSigningCertificateException::class, $party->oib);

        return $certificate->toSigningCredential();
    }

    public function forOib(string $oib): SigningCredential
    {
        $party = Party::query()->where('oib', $oib)->first();

        throw_if($party === null, ModelNotFoundException::class, "No party registered for OIB {$oib}.");

        return $this->for($party);
    }
}
