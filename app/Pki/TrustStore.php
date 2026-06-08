<?php

declare(strict_types=1);

namespace App\Pki;

use Closure;

/**
 * The set of CA certificates eRakun trusts. A leaf is trusted when its
 * signature verifies against one of these anchors. In production these would be
 * the real FINA and OpenPEPPOL roots; here they are the self-generated test CAs.
 *
 * The anchors are loaded lazily — only when a signature is actually verified —
 * so resolving the verifier never fails on a system that hasn't generated its
 * PKI yet (e.g. a request that gets rejected before verification runs).
 */
final class TrustStore
{
    /** @var list<string>|null */
    private ?array $caCertificatePems = null;

    /**
     * @param  Closure():list<string>  $loader
     */
    public function __construct(private readonly Closure $loader) {}

    /**
     * True when $leafPem was issued (signed) by one of the trusted CAs. This is
     * pure signature verification — validity windows are checked separately so
     * callers can distinguish "untrusted" from "expired".
     */
    public function isIssuedByTrustedCa(string $leafPem): bool
    {
        return array_any($this->trustAnchors(), fn ($ca): bool => openssl_x509_verify($leafPem, $ca) === 1);
    }

    public function isWithinValidityWindow(string $leafPem, ?int $at = null): bool
    {
        $parsed = openssl_x509_parse($leafPem);

        if ($parsed === false) {
            return false;
        }

        $at ??= time();

        return $parsed['validFrom_time_t'] <= $at && $at <= $parsed['validTo_time_t'];
    }

    /**
     * @return list<string>
     */
    private function trustAnchors(): array
    {
        return $this->caCertificatePems ??= ($this->loader)();
    }
}
