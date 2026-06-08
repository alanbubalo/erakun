<?php

declare(strict_types=1);

namespace App\Pki;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The fields we record about an X.509 certificate: enough to display it, to
 * enforce validity windows, and to render a ds:X509IssuerSerial — without ever
 * touching the private key.
 */
final readonly class CertificateMetadata
{
    public function __construct(
        public string $serialNumber,
        public string $subject,
        public string $issuer,
        public CarbonImmutable $validFrom,
        public CarbonImmutable $validTo,
        public string $fingerprint,
    ) {}

    /**
     * Parse a PEM certificate into its metadata. Subject and issuer are rendered
     * as RFC 4514-style DN strings (CN=…, O=…, C=HR) so they round-trip into the
     * signature's X509IssuerName.
     */
    public static function fromPem(string $certificatePem): self
    {
        $parsed = openssl_x509_parse($certificatePem);

        throw_if($parsed === false, RuntimeException::class, 'Unable to parse certificate.');

        return new self(
            serialNumber: (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            subject: self::formatDn((array) ($parsed['subject'] ?? [])),
            issuer: self::formatDn((array) ($parsed['issuer'] ?? [])),
            validFrom: CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t']),
            validTo: CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']),
            fingerprint: openssl_x509_fingerprint($certificatePem, 'sha256') ?: '',
        );
    }

    /**
     * @param  array<string, string|list<string>>  $parts
     */
    public static function formatDn(array $parts): string
    {
        $order = ['CN', 'OU', 'O', 'L', 'ST', 'C'];
        uksort($parts, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        $segments = [];
        foreach ($parts as $key => $value) {
            foreach ((array) $value as $single) {
                $segments[] = "{$key}={$single}";
            }
        }

        return implode(', ', $segments);
    }
}
