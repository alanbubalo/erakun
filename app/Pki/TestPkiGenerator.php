<?php

declare(strict_types=1);

namespace App\Pki;

use Illuminate\Contracts\Filesystem\Filesystem;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Mints eRakun's fictional test PKI: two root CAs (standing in for FINA and
 * OpenPEPPOL), the access point's own transport certificate, and per-party
 * document-signing certificates issued off the FINA-like CA.
 *
 * Everything here is real cryptography — RSA-2048 keys, SHA-256 signatures,
 * proper X.509 extensions — only the trust anchors are self-generated. Keys are
 * written to the PKI disk (gitignored); the CA certificates double as the trust
 * anchors the verifier checks chains against.
 */
final readonly class TestPkiGenerator
{
    public const string FINA_CA_KEY = 'ca/fina.key';

    public const string FINA_CA_CERT = 'ca/fina.crt';

    public const string PEPPOL_CA_KEY = 'ca/peppol.key';

    public const string PEPPOL_CA_CERT = 'ca/peppol.crt';

    public const string AP_KEY = 'ap/ap.key';

    public const string AP_CERT = 'ap/ap.crt';

    public function __construct(
        private Filesystem $disk,
        private string $opensslConfig,
        private int $caDays = 3650,
        private int $leafDays = 730,
    ) {}

    /**
     * Produce the full test hierarchy. Idempotent unless $force is set: existing
     * CA / AP material is left untouched so re-running never invalidates keys
     * already embedded in issued party certificates.
     */
    public function generate(bool $force = false): void
    {
        $this->ensureCa(self::FINA_CA_KEY, self::FINA_CA_CERT, (string) config('pki.ca.fina.cn'), $force);
        $this->ensureCa(self::PEPPOL_CA_KEY, self::PEPPOL_CA_CERT, (string) config('pki.ca.peppol.cn'), $force);

        if ($force || ! $this->disk->exists(self::AP_CERT)) {
            $ap = $this->issueLeaf(
                self::PEPPOL_CA_KEY,
                self::PEPPOL_CA_CERT,
                ['commonName' => (string) config('pki.access_point.cn'), 'organizationName' => 'eRakun', 'countryName' => 'HR'],
            );
            $this->disk->put(self::AP_KEY, $ap->privateKeyPem);
            $this->disk->put(self::AP_CERT, $ap->certificatePem);
        }
    }

    /**
     * Issue a fresh document-signing certificate for a party off the FINA-like
     * CA. The CN is the party's OIB — the qualified certificate is tied to the
     * tax identifier, as a real FINA cert would be.
     */
    public function issuePartyCertificate(string $oib, string $name): SigningCredential
    {
        return $this->issueLeaf(
            self::FINA_CA_KEY,
            self::FINA_CA_CERT,
            ['commonName' => $oib, 'organizationName' => $name, 'countryName' => 'HR'],
        );
    }

    public function caCertificatePem(string $certPath): string
    {
        throw_unless($this->disk->exists($certPath), RuntimeException::class, "Missing CA certificate {$certPath}. Run `php artisan pki:generate`.");

        return (string) $this->disk->get($certPath);
    }

    private function ensureCa(string $keyPath, string $certPath, string $cn, bool $force): void
    {
        if (! $force && $this->disk->exists($certPath)) {
            return;
        }

        $key = $this->newKey();
        $dn = ['commonName' => $cn, 'organizationName' => 'eRakun', 'countryName' => 'HR'];
        $csr = openssl_csr_new($dn, $key, $this->conf());

        throw_if($csr === false, RuntimeException::class, 'Failed to build CA CSR.');

        $cert = openssl_csr_sign($csr, null, $key, $this->caDays, $this->conf('v3_ca'), 1);

        throw_if($cert === false, RuntimeException::class, 'Failed to self-sign CA certificate.');

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, $this->conf());

        $this->disk->put($keyPath, $keyPem);
        $this->disk->put($certPath, $certPem);
    }

    private function issueLeaf(string $caKeyPath, string $caCertPath, array $dn): SigningCredential
    {
        $caKey = openssl_pkey_get_private((string) $this->disk->get($caKeyPath));
        $caCert = openssl_x509_read((string) $this->disk->get($caCertPath));

        throw_if($caKey === false || $caCert === false, RuntimeException::class, 'Unable to load issuing CA material.');

        $key = $this->newKey();
        $csr = openssl_csr_new($dn, $key, $this->conf());

        throw_if($csr === false, RuntimeException::class, 'Failed to build leaf CSR.');

        $cert = openssl_csr_sign($csr, $caCert, $caKey, $this->leafDays, $this->conf('v3_leaf'), random_int(1000, PHP_INT_MAX));

        throw_if($cert === false, RuntimeException::class, 'Failed to sign leaf certificate.');

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, $this->conf());

        return new SigningCredential(privateKeyPem: $keyPem, certificatePem: $certPem);
    }

    private function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new($this->conf() + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        throw_if($key === false, RuntimeException::class, 'Failed to generate RSA key pair.');

        return $key;
    }

    /**
     * @return array<string, string>
     */
    private function conf(?string $extensions = null): array
    {
        $conf = ['digest_alg' => 'sha256', 'config' => $this->opensslConfig];

        if ($extensions !== null) {
            $conf['x509_extensions'] = $extensions;
        }

        return $conf;
    }
}
