<?php

declare(strict_types=1);

namespace App\Pki;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * Resolves the access point's own transport-layer credential (the cert standing
 * in for an OpenPEPPOL-issued AP cert) from the PKI disk. There is exactly one
 * per IP, so unlike party certificates it lives as files rather than a DB row.
 */
final readonly class AccessPointCredential
{
    public function __construct(private Filesystem $disk) {}

    public function resolve(): SigningCredential
    {
        throw_unless(
            $this->disk->exists(TestPkiGenerator::AP_CERT),
            RuntimeException::class,
            'Missing access point certificate. Run `php artisan pki:generate`.',
        );

        return new SigningCredential(
            privateKeyPem: (string) $this->disk->get(TestPkiGenerator::AP_KEY),
            certificatePem: (string) $this->disk->get(TestPkiGenerator::AP_CERT),
        );
    }
}
