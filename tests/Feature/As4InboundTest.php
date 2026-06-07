<?php

declare(strict_types=1);

use App\Actions\InvoiceSigner;
use App\As4\As4EnvelopeBuilder;
use App\Enums\As4MessageDirection;
use App\Enums\As4MessageState;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\As4Message;
use App\Models\Invoice;
use App\Models\Party;

function ublFixtureFor5c(string $name = 'valid-hr-cius.xml'): string
{
    $xml = file_get_contents(__DIR__.'/../Fixtures/ubl/'.$name);

    expect($xml)->toBeString()->not->toBeEmpty();

    return (string) $xml;
}

function buildAs4Envelope(string $ublXml, string $messageId, bool $signed = true): string
{
    $dom = (new As4EnvelopeBuilder)->build(
        ublXml: $ublXml,
        messageId: $messageId,
        senderOib: '22222222226',
        recipientOib: '11111111119',
    );

    if ($signed) {
        $dom = (new InvoiceSigner)->execute($dom);
    }

    $xml = $dom->saveXML();
    expect($xml)->toBeString();

    return (string) $xml;
}

function postAs4Envelope(string $envelopeXml)
{
    return test()->call(
        'POST',
        '/api/as4/inbox',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/soap+xml; charset=utf-8'],
        $envelopeXml,
    );
}

function registerLocalBuyer(): Party
{
    return Party::factory()->create([
        'oib' => '11111111119',
        'name' => 'Tvrtka B d.o.o.',
        'is_vat_registered' => true,
        'address_line' => 'Ulica 2',
        'city' => 'RIJEKA',
        'postcode' => '51000',
        'country_code' => 'HR',
    ]);
}

describe('POST /api/as4/inbox', function (): void {
    it('accepts a signed envelope, persists an inbound invoice, and returns a receipt', function (): void {
        $buyer = registerLocalBuyer();
        $messageId = 'happy-path@erakun';
        $envelope = buildAs4Envelope(ublFixtureFor5c(), $messageId);

        $response = postAs4Envelope($envelope);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/soap+xml; charset=utf-8');

        $body = $response->getContent();
        expect($body)->toContain('<eb:Receipt/>')
            ->and($body)->toContain('<eb:RefToMessageId>'.$messageId.'</eb:RefToMessageId>');

        $invoice = Invoice::where('direction', InvoiceDirection::Inbound)->first();
        expect($invoice)->not->toBeNull()
            ->and($invoice->status)->toBe(InvoiceStatus::Received)
            ->and($invoice->buyer->oib)->toBe($buyer->oib)
            ->and($invoice->supplier->oib)->toBe('22222222226');

        $message = As4Message::where('message_id', $messageId)->first();
        expect($message)->not->toBeNull()
            ->and($message->direction)->toBe(As4MessageDirection::Inbound)
            ->and($message->state)->toBe(As4MessageState::Delivered)
            ->and($message->invoice_id)->toBe($invoice->id)
            ->and($message->from_oib)->toBe('22222222226')
            ->and($message->to_oib)->toBe('11111111119')
            ->and($message->envelope_xml)->toContain('<eb:UserMessage>')
            ->and($message->receipt_xml)->toContain('<eb:Receipt/>');
    });

    it('returns EBMS:0009 when the envelope is missing ds:Signature', function (): void {
        registerLocalBuyer();
        $envelope = buildAs4Envelope(ublFixtureFor5c(), 'unsigned@erakun', signed: false);

        $response = postAs4Envelope($envelope);

        $response->assertStatus(400);
        $body = $response->getContent();
        expect($body)->toContain('errorCode="EBMS:0009"')
            ->and($body)->toContain('Signature');

        expect(As4Message::count())->toBe(0);
        expect(Invoice::where('direction', InvoiceDirection::Inbound)->count())->toBe(0);
    });

    it('returns EBMS:0005 when the recipient OIB is not registered locally', function (): void {
        $messageId = 'unknown-recipient@erakun';
        $envelope = buildAs4Envelope(ublFixtureFor5c(), $messageId);

        $response = postAs4Envelope($envelope);

        $response->assertStatus(400);
        $body = $response->getContent();
        expect($body)->toContain('errorCode="EBMS:0005"')
            ->and($body)->toContain('11111111119')
            ->and($body)->toContain('<eb:RefToMessageId>'.$messageId.'</eb:RefToMessageId>');

        expect(As4Message::count())->toBe(0);
    });

    it('returns EBMS:0004 when the inner UBL fails HR-CIUS validation', function (): void {
        registerLocalBuyer();
        $messageId = 'bad-ubl@erakun';
        $envelope = buildAs4Envelope(ublFixtureFor5c('invalid-br-co-10.xml'), $messageId);

        $response = postAs4Envelope($envelope);

        $response->assertStatus(400);
        $body = $response->getContent();
        expect($body)->toContain('errorCode="EBMS:0004"')
            ->and($body)->toContain('<eb:RefToMessageId>'.$messageId.'</eb:RefToMessageId>');

        $message = As4Message::where('message_id', $messageId)->first();
        expect($message)->not->toBeNull()
            ->and($message->state)->toBe(As4MessageState::Error)
            ->and($message->error_code)->toBe('EBMS:0004')
            ->and($message->invoice_id)->toBeNull();

        expect(Invoice::where('direction', InvoiceDirection::Inbound)->count())->toBe(0);
    });

    it('is idempotent on replay: identical responses, no duplicate invoice', function (): void {
        registerLocalBuyer();
        $messageId = 'replay@erakun';
        $envelope = buildAs4Envelope(ublFixtureFor5c(), $messageId);

        $first = postAs4Envelope($envelope);
        $second = postAs4Envelope($envelope);

        $first->assertOk();
        $second->assertOk();

        expect($second->getContent())->toBe($first->getContent());

        expect(As4Message::where('message_id', $messageId)->count())->toBe(1);
        expect(Invoice::where('direction', InvoiceDirection::Inbound)->count())->toBe(1);
    });

    it('returns EBMS:0009 when the envelope is not well-formed XML', function (): void {
        registerLocalBuyer();

        $response = postAs4Envelope('<not really an envelope');

        $response->assertStatus(400);
        expect($response->getContent())->toContain('errorCode="EBMS:0009"');
    });
});
