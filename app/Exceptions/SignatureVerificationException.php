<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a document's signature fails verification — missing/invalid
 * signature, a reference digest that no longer matches (tampering), or a
 * certificate that does not chain to a trusted CA / has expired.
 */
final class SignatureVerificationException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function missingSignature(): self
    {
        return new self('missing_signature', 'The document carries no ds:Signature.');
    }

    public static function missingCertificate(): self
    {
        return new self('missing_certificate', 'The signature carries no X509Certificate in KeyInfo.');
    }

    public static function invalidSignature(): self
    {
        return new self('invalid_signature', 'The signature value does not verify against the signing certificate.');
    }

    public static function digestMismatch(string $uri): self
    {
        $where = $uri === '' ? 'the document body' : "reference {$uri}";

        return new self('digest_mismatch', "The signed content has been altered: digest mismatch for {$where}.");
    }

    public static function untrusted(): self
    {
        return new self('untrusted', 'The signing certificate does not chain to a trusted CA.');
    }

    public static function expired(): self
    {
        return new self('expired', 'The signing certificate is outside its validity window.');
    }
}
