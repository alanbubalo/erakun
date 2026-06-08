<?php

declare(strict_types=1);

namespace App\Pki;

/**
 * A private key + its certificate, ready to sign with.
 *
 * The private key PEM must never cross the application boundary — it is not
 * serialised into API responses, logs, or persisted columns. Resolve one of
 * these only at the moment of signing.
 */
final readonly class SigningCredential
{
    public function __construct(
        public string $privateKeyPem,
        public string $certificatePem,
    ) {}
}
