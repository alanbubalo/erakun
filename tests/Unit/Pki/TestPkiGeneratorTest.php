<?php

declare(strict_types=1);

use App\Pki\CertificateMetadata;
use App\Pki\TestPkiGenerator;
use Illuminate\Support\Facades\Storage;

function makeGenerator(): TestPkiGenerator
{
    return new TestPkiGenerator(
        disk: Storage::fake('pki'),
        opensslConfig: resource_path('pki/openssl.cnf'),
    );
}

it('mints two root CAs and an access point certificate on the pki disk', function (): void {
    $generator = makeGenerator();

    $generator->generate();

    Storage::disk('pki')->assertExists([
        TestPkiGenerator::FINA_CA_CERT,
        TestPkiGenerator::FINA_CA_KEY,
        TestPkiGenerator::PEPPOL_CA_CERT,
        TestPkiGenerator::PEPPOL_CA_KEY,
        TestPkiGenerator::AP_CERT,
        TestPkiGenerator::AP_KEY,
    ]);
});

it('issues an access point cert that chains to the OpenPEPPOL-like CA', function (): void {
    $generator = makeGenerator();
    $generator->generate();

    $apCert = (string) Storage::disk('pki')->get(TestPkiGenerator::AP_CERT);
    $peppolCa = $generator->caCertificatePem(TestPkiGenerator::PEPPOL_CA_CERT);
    $finaCa = $generator->caCertificatePem(TestPkiGenerator::FINA_CA_CERT);

    expect(openssl_x509_verify($apCert, $peppolCa))->toBe(1)
        ->and(openssl_x509_verify($apCert, $finaCa))->toBe(0);
});

it('issues a party signing cert that chains to the FINA-like CA with the OIB as CN', function (): void {
    $generator = makeGenerator();
    $generator->generate();

    $credential = $generator->issuePartyCertificate('12345678903', 'Acme d.o.o.');

    $finaCa = $generator->caCertificatePem(TestPkiGenerator::FINA_CA_CERT);
    expect(openssl_x509_verify($credential->certificatePem, $finaCa))->toBe(1);

    $meta = CertificateMetadata::fromPem($credential->certificatePem);
    expect($meta->subject)->toContain('CN=12345678903')
        ->and($meta->issuer)->toContain('eRakun Test FINA CA')
        ->and($meta->serialNumber)->not->toBe('')
        ->and($credential->privateKeyPem)->toContain('PRIVATE KEY');
});

it('does not regenerate existing CAs without --force', function (): void {
    $generator = makeGenerator();
    $generator->generate();
    $firstCa = (string) Storage::disk('pki')->get(TestPkiGenerator::FINA_CA_CERT);

    $generator->generate();
    expect((string) Storage::disk('pki')->get(TestPkiGenerator::FINA_CA_CERT))->toBe($firstCa);

    $generator->generate(force: true);
    expect((string) Storage::disk('pki')->get(TestPkiGenerator::FINA_CA_CERT))->not->toBe($firstCa);
});
