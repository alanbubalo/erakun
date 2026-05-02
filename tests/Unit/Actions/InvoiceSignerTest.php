<?php

use App\Actions\InvoiceSigner;
use App\Actions\UblGenerator;
use Tests\Fixtures\InvoiceFixture;

it('embeds a digest that matches the canonicalized invoice body', function () {
    $unsigned = app(UblGenerator::class)->execute(InvoiceFixture::outbound());
    $signed = app(InvoiceSigner::class)->execute($unsigned);

    $xpath = new DOMXPath($signed);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    $digestNode = $xpath->query('//ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestValue')?->item(0);
    expect($digestNode)->not->toBeNull();
    $embedded = trim((string) $digestNode->textContent);

    $signatures = $xpath->query('//ds:Signature');
    expect($signatures->length)->toBe(1);
    foreach (iterator_to_array($signatures) as $node) {
        $node->parentNode->removeChild($node);
    }

    $canonical = $signed->documentElement->C14N(exclusive: false, withComments: false);
    $recomputed = base64_encode(hash('sha256', $canonical, binary: true));

    expect($embedded)->toBe($recomputed);
});

it('places a structurally-real ds:Signature inside ext:UBLExtensions with stub key info', function () {
    $unsigned = app(UblGenerator::class)->execute(InvoiceFixture::outbound());
    $signed = app(InvoiceSigner::class)->execute($unsigned);

    $xpath = new DOMXPath($signed);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
    $xpath->registerNamespace('sac', 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2');

    $signature = $xpath->query('//ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent//sac:SignatureInformation/ds:Signature')?->item(0);
    expect($signature)->not->toBeNull();

    $sigValue = $xpath->query('.//ds:SignatureValue', $signature)?->item(0);
    expect(base64_decode((string) $sigValue->textContent))->toBe('STUB-PHASE3');

    $serial = $xpath->query('.//ds:KeyInfo//ds:X509SerialNumber', $signature)?->item(0);
    expect(trim((string) $serial->textContent))->toBe('00000000000000000001');
});
