<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Party;
use App\Pki\CertificateMetadata;
use App\Pki\SigningCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Registers a party's signing certificate. Accepts PEM or PKCS#12, normalises
 * to PEM, records metadata, writes the private key to the gitignored PKI disk,
 * and enforces the single-active invariant: uploading supersedes the previous
 * active certificate within one transaction.
 */
final class StoreCertificate
{
    /**
     * @param  string  $material  Raw PEM (cert, optionally bundled with the key) or PKCS#12 bytes.
     */
    public function execute(Party $party, string $material, ?string $password = null): Certificate
    {
        return $this->store($party, $this->normalize($material, $password));
    }

    public function store(Party $party, SigningCredential $credential): Certificate
    {
        $meta = CertificateMetadata::fromPem($credential->certificatePem);
        $disk = Storage::disk((string) config('pki.disk'));

        $base = "parties/{$party->oib}/{$meta->serialNumber}";
        $disk->put("{$base}.key", $credential->privateKeyPem);
        $disk->put("{$base}.crt", $credential->certificatePem);

        return DB::transaction(function () use ($party, $meta, $credential, $base): Certificate {
            $party->certificates()
                ->where('status', CertificateStatus::Active)
                ->update(['status' => CertificateStatus::Superseded]);

            return $party->certificates()->create([
                'status' => CertificateStatus::Active,
                'serial_number' => $meta->serialNumber,
                'subject' => $meta->subject,
                'issuer' => $meta->issuer,
                'valid_from' => $meta->validFrom,
                'valid_to' => $meta->validTo,
                'certificate_pem' => $credential->certificatePem,
                'private_key_path' => "{$base}.key",
                'fingerprint' => $meta->fingerprint,
            ]);
        });
    }

    private function normalize(string $material, ?string $password): SigningCredential
    {
        // PKCS#12 is binary (DER); a PEM bundle carries the -----BEGIN----- armour.
        if (! str_contains($material, '-----BEGIN')) {
            // @-suppressed: invalid material raises a warning we surface as a 422 below.
            if (! @openssl_pkcs12_read($material, $bundle, (string) $password)) {
                throw ValidationException::withMessages([
                    'certificate' => 'Unable to read PKCS#12: wrong password or malformed file.',
                ]);
            }

            return new SigningCredential(
                privateKeyPem: trim((string) $bundle['pkey']),
                certificatePem: trim((string) $bundle['cert']),
            );
        }

        $cert = @openssl_x509_read($material);
        if ($cert === false) {
            throw ValidationException::withMessages(['certificate' => 'Does not contain a valid X.509 certificate.']);
        }
        openssl_x509_export($cert, $certPem);

        $key = @openssl_pkey_get_private($material, (string) $password);
        if ($key === false) {
            throw ValidationException::withMessages(['certificate' => 'Does not contain a readable private key.']);
        }
        openssl_pkey_export($key, $keyPem);

        return new SigningCredential(privateKeyPem: $keyPem, certificatePem: $certPem);
    }
}
