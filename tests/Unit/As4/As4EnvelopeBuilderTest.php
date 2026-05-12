<?php

declare(strict_types=1);

use App\As4\As4EnvelopeBuilder;

function loadUblFixture(): string
{
    $path = __DIR__.'/../../Fixtures/ubl/valid-hr-cius.xml';
    $xml = file_get_contents($path);

    expect($xml)->toBeString()->not->toBeEmpty();

    return (string) $xml;
}

function as4SchemaPath(): string
{
    return base_path('resources/schemas/as4/as4-envelope.xsd');
}

function buildEnvelope(
    string $messageId = '11111111-2222-3333-4444-555555555555@erakun',
    string $senderOib = '12345678901',
    string $recipientOib = '98765432109',
): DOMDocument {
    return (new As4EnvelopeBuilder)->build(
        ublXml: loadUblFixture(),
        messageId: $messageId,
        senderOib: $senderOib,
        recipientOib: $recipientOib,
    );
}

it('produces an envelope that validates against as4-envelope.xsd', function (): void {
    $dom = buildEnvelope();

    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $valid = $dom->schemaValidate(as4SchemaPath());
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($valid)->toBeTrue('Envelope failed XSD validation: '.implode('; ', array_map(
        fn (LibXMLError $e): string => trim($e->message),
        $errors,
    )));
});

it('populates eb:MessageId, eb:From/PartyId, and eb:To/PartyId from inputs', function (): void {
    $dom = buildEnvelope(
        messageId: 'fixture-id@erakun',
        senderOib: '12345678901',
        recipientOib: '98765432109',
    );

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('soap', As4EnvelopeBuilder::NS_SOAP);
    $xpath->registerNamespace('eb', As4EnvelopeBuilder::NS_EB);

    $messageId = $xpath->query('//eb:UserMessage/eb:MessageInfo/eb:MessageId')->item(0)?->textContent;
    $from = $xpath->query('//eb:UserMessage/eb:PartyInfo/eb:From/eb:PartyId')->item(0);
    $to = $xpath->query('//eb:UserMessage/eb:PartyInfo/eb:To/eb:PartyId')->item(0);
    $service = $xpath->query('//eb:UserMessage/eb:CollaborationInfo/eb:Service')->item(0)?->textContent;
    $action = $xpath->query('//eb:UserMessage/eb:CollaborationInfo/eb:Action')->item(0)?->textContent;
    $partInfo = $xpath->query('//eb:UserMessage/eb:PayloadInfo/eb:PartInfo')->item(0);

    expect($messageId)->toBe('fixture-id@erakun')
        ->and($from?->textContent)->toBe('12345678901')
        ->and($from?->attributes->getNamedItem('type')?->nodeValue)->toBe(As4EnvelopeBuilder::PARTY_ID_TYPE)
        ->and($to?->textContent)->toBe('98765432109')
        ->and($to?->attributes->getNamedItem('type')?->nodeValue)->toBe(As4EnvelopeBuilder::PARTY_ID_TYPE)
        ->and($service)->toBe(As4EnvelopeBuilder::SERVICE)
        ->and($action)->toBe(As4EnvelopeBuilder::ACTION)
        ->and($partInfo?->attributes->getNamedItem('href')?->nodeValue)->toBe('#'.As4EnvelopeBuilder::PAYLOAD_ID);
});

it('preserves the inner UBL Invoice payload', function (): void {
    $ubl = loadUblFixture();
    $dom = (new As4EnvelopeBuilder)->build(
        ublXml: $ubl,
        messageId: 'fixture@erakun',
        senderOib: '12345678901',
        recipientOib: '98765432109',
    );

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('soap', As4EnvelopeBuilder::NS_SOAP);
    $xpath->registerNamespace('as4', As4EnvelopeBuilder::NS_AS4);
    $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

    $wrapper = $xpath->query('/soap:Envelope/soap:Body/as4:UblPayload')->item(0);
    $invoice = $xpath->query('/soap:Envelope/soap:Body/as4:UblPayload/ubl:Invoice')->item(0);
    $invoiceId = $xpath->query('//ubl:Invoice/cbc:ID')->item(0)?->textContent;

    expect($wrapper)->toBeInstanceOf(DOMElement::class)
        ->and($invoice)->toBeInstanceOf(DOMElement::class)
        ->and($invoiceId)->toBe('RN-2026-00001');

    /** @var DOMElement $wrapper */
    expect($wrapper->getAttribute('id'))->toBe(As4EnvelopeBuilder::PAYLOAD_ID);
});

it('throws when the UBL payload is malformed', function (): void {
    expect(fn () => (new As4EnvelopeBuilder)->build(
        ublXml: '<not really xml',
        messageId: 'x@erakun',
        senderOib: '12345678901',
        recipientOib: '98765432109',
    ))->toThrow(RuntimeException::class);
});
