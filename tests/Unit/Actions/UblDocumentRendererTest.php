<?php

declare(strict_types=1);

use App\Actions\SignatureVerifier;
use App\Actions\UblDocumentRenderer;
use App\Exceptions\InvoiceValidationException;
use App\Models\Invoice;
use Tests\Fixtures\InvoiceFixture;

it('returns signed bytes whose signature verifies after a verbatim byte round-trip', function (): void {
    $invoice = InvoiceFixture::outbound();

    $xml = resolve(UblDocumentRenderer::class)->signed($invoice);

    // The crux invariant: the signature was computed over the in-memory DOM, so
    // reloading the *exact returned bytes* (default preserveWhiteSpace) must still
    // verify. Re-serialising or pretty-printing anywhere upstream would break this.
    $reloaded = new DOMDocument;
    expect($reloaded->loadXML($xml))->toBeTrue();

    expect(fn () => resolve(SignatureVerifier::class)->verify($reloaded))
        ->not->toThrow(Exception::class);
});

it('throws InvoiceValidationException without storing when the document is invalid', function (): void {
    $invoice = InvoiceFixture::outbound();
    Invoice::query()->whereKey($invoice->id)->update(['net_amount' => '999.00']);

    expect(fn () => resolve(UblDocumentRenderer::class)->signed($invoice->fresh()))
        ->toThrow(InvoiceValidationException::class);
});

it('renders an unsigned draft without a signature', function (): void {
    $invoice = InvoiceFixture::outbound();

    $xml = resolve(UblDocumentRenderer::class)->draft($invoice);

    expect($xml)->toContain('<cbc:ID>RN-2026-00001</cbc:ID>')
        ->and($xml)->not->toContain('<ds:Signature');
});
