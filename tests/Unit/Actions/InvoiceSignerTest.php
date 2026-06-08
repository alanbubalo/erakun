<?php

declare(strict_types=1);

use App\Actions\InvoiceSigner;
use App\Actions\SignatureVerifier;
use App\Actions\UblGenerator;
use App\Exceptions\SignatureVerificationException;
use App\Models\Party;
use App\Pki\SigningCredential;
use Carbon\CarbonImmutable;
use Tests\Fixtures\InvoiceFixture;

beforeEach(function (): void {
    bootTestPki();
    $this->party = Party::factory()->create();
    $this->credential = issueTestCertificate($this->party)->toSigningCredential();
});

function signFixture(SigningCredential $credential): DOMDocument
{
    $unsigned = resolve(UblGenerator::class)->execute(InvoiceFixture::outbound());

    return resolve(InvoiceSigner::class)->execute($unsigned, $credential);
}

it('produces a real RSA-SHA256 XAdES-B signature, not a stub', function (): void {
    $signed = signFixture($this->credential);

    $xpath = new DOMXPath($signed);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');
    $xpath->registerNamespace('sac', 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2');

    $signature = $xpath->query('//sac:SignatureInformation/ds:Signature')->item(0);
    expect($signature)->not->toBeNull();

    $sigValue = (string) $xpath->query('.//ds:SignatureValue', $signature)->item(0)->textContent;
    expect(base64_decode(trim($sigValue)))->not->toBe('STUB-PHASE3')
        ->and(strlen(base64_decode(trim($sigValue))))->toBe(256); // RSA-2048 signature

    // Real certificate embedded, and XAdES SignedProperties referenced.
    expect($xpath->query('.//ds:KeyInfo/ds:X509Data/ds:X509Certificate', $signature)->length)->toBe(1)
        ->and($xpath->query('.//xades:SignedProperties/xades:SignedSignatureProperties/xades:SigningTime', $signature)->length)->toBe(1)
        ->and($xpath->query('.//ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]', $signature)->length)->toBe(1);
});

it('round-trips: a freshly signed document verifies', function (): void {
    $signed = signFixture($this->credential);

    resolve(SignatureVerifier::class)->verify($signed);
})->throwsNoExceptions();

it('detects tampering: altering the signed body fails verification', function (): void {
    $signed = signFixture($this->credential);

    // Flip a monetary amount after signing.
    $xpath = new DOMXPath($signed);
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $amount = $xpath->query('//cbc:PayableAmount')->item(0) ?? $xpath->query('//cbc:TaxInclusiveAmount')->item(0);
    expect($amount)->not->toBeNull();
    $amount->textContent = '999999.99';

    expect(fn () => resolve(SignatureVerifier::class)->verify($signed))
        ->toThrow(SignatureVerificationException::class);
});

it('rejects a signature whose certificate does not chain to a trusted CA', function (): void {
    // Sign with a self-signed cert that no test CA issued.
    $rogueKey = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => '11111111119'], $rogueKey, ['digest_alg' => 'sha256']);
    $rogueCert = openssl_csr_sign($csr, null, $rogueKey, 365, ['digest_alg' => 'sha256'], 99);
    openssl_x509_export($rogueCert, $certPem);
    openssl_pkey_export($rogueKey, $keyPem);

    $signed = signFixture(new SigningCredential($keyPem, $certPem));

    expect(fn () => resolve(SignatureVerifier::class)->verify($signed))
        ->toThrow(SignatureVerificationException::class, 'does not chain to a trusted CA');
});

it('rejects an expired certificate', function (): void {
    // Verify "in the future", past the cert's validity window.
    $signed = signFixture($this->credential);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addYears(10));

    expect(fn () => resolve(SignatureVerifier::class)->verify($signed))
        ->toThrow(SignatureVerificationException::class, 'validity window');

    CarbonImmutable::setTestNow();
});
