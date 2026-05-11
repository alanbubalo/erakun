<?php

declare(strict_types=1);

use App\Actions\FiscalMessageBuilder;
use Tests\Fixtures\InvoiceFixture;

it('builds a fiscal message extract matching the canonical snapshot', function (): void {
    $xml = resolve(FiscalMessageBuilder::class)
        ->build(InvoiceFixture::outbound(), '22222222226')
        ->saveXML();

    expect($xml)->toMatchSnapshot();
});

it('produces XML that validates against fiscalization-request.xsd', function (): void {
    $dom = resolve(FiscalMessageBuilder::class)
        ->build(InvoiceFixture::outbound(), '22222222226');

    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $valid = $dom->schemaValidate(resource_path('schemas/fiscal/fiscalization-request.xsd'));

    $errors = collect(libxml_get_errors())
        ->map(fn ($e): string => trim($e->message).' (line '.$e->line.')')
        ->implode("\n");

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($valid)->toBeTrue('XSD validation failed: '.$errors);
});

it('uses the reporter OIB regardless of supplier OIB', function (): void {
    $dom = resolve(FiscalMessageBuilder::class)
        ->build(InvoiceFixture::outbound(), '99999999999');

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('f', 'urn:hr:erakun:fiscal:1.0');

    expect(trim((string) $xpath->query('//f:Reporter/f:Oib')?->item(0)?->textContent))->toBe('99999999999')
        ->and(trim((string) $xpath->query('//f:Supplier/f:Oib')?->item(0)?->textContent))->toBe('22222222226')
        ->and(trim((string) $xpath->query('//f:Buyer/f:Oib')?->item(0)?->textContent))->toBe('11111111119');
});
