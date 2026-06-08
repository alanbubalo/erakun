<?php

declare(strict_types=1);

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Party;
use App\Pki\SigningCredential;
use App\Pki\TestPkiGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('pki');
    $this->generator = resolve(TestPkiGenerator::class);
    $this->generator->generate();
});

function pemBundle(SigningCredential $credential): string
{
    return $credential->certificatePem."\n".$credential->privateKeyPem;
}

it('accepts a PEM certificate upload and never returns the private key', function (): void {
    $party = Party::factory()->create();
    $credential = $this->generator->issuePartyCertificate($party->oib, $party->name);

    $response = $this->postJson("/api/parties/{$party->oib}/certificates", [
        'certificate_pem' => pemBundle($credential),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', CertificateStatus::Active->value)
        ->assertJsonPath('data.subject', fn (string $s): bool => str_contains($s, "CN={$party->oib}"));

    expect($response->json('data'))->not->toHaveKey('private_key_path')
        ->and($response->getContent())->not->toContain('PRIVATE KEY');

    $cert = $party->activeCertificate()->first();
    expect($cert)->not->toBeNull()
        ->and(Storage::disk('pki')->exists($cert->private_key_path))->toBeTrue();
});

it('accepts a PKCS#12 upload with a password', function (): void {
    $party = Party::factory()->create();
    $credential = $this->generator->issuePartyCertificate($party->oib, $party->name);

    $cert = openssl_x509_read($credential->certificatePem);
    $key = openssl_pkey_get_private($credential->privateKeyPem);
    openssl_pkcs12_export($cert, $p12, $key, 'sezam');

    $upload = UploadedFile::fake()->createWithContent('signing.p12', $p12);

    $response = $this->post("/api/parties/{$party->oib}/certificates", [
        'certificate' => $upload,
        'password' => 'sezam',
    ]);

    $response->assertStatus(201)->assertJsonPath('data.status', CertificateStatus::Active->value);
    expect($party->certificates()->active()->count())->toBe(1);
});

it('enforces the single-active invariant: a new upload supersedes the previous', function (): void {
    $party = Party::factory()->create();

    $first = $this->generator->issuePartyCertificate($party->oib, $party->name);
    $this->postJson("/api/parties/{$party->oib}/certificates", ['certificate_pem' => pemBundle($first)])->assertStatus(201);

    $second = $this->generator->issuePartyCertificate($party->oib, $party->name);
    $this->postJson("/api/parties/{$party->oib}/certificates", ['certificate_pem' => pemBundle($second)])->assertStatus(201);

    expect($party->certificates()->active()->count())->toBe(1)
        ->and(Certificate::where('status', CertificateStatus::Superseded)->count())->toBe(1);
});

it('rejects a malformed certificate', function (): void {
    $party = Party::factory()->create();

    $this->postJson("/api/parties/{$party->oib}/certificates", [
        'certificate_pem' => '-----BEGIN CERTIFICATE-----not a cert-----END CERTIFICATE-----',
    ])->assertStatus(422);
});

it('revokes a certificate on delete', function (): void {
    $party = Party::factory()->create();
    $credential = $this->generator->issuePartyCertificate($party->oib, $party->name);
    $this->postJson("/api/parties/{$party->oib}/certificates", ['certificate_pem' => pemBundle($credential)])->assertStatus(201);
    $cert = $party->activeCertificate()->first();

    $this->deleteJson("/api/parties/{$party->oib}/certificates/{$cert->id}")->assertNoContent();

    expect($cert->refresh()->status)->toBe(CertificateStatus::Revoked);
});
