<?php

declare(strict_types=1);

use App\As4\As4DeliveryException;
use App\As4\As4DeliveryReceipt;
use App\As4\As4EnvelopeBuilder;
use App\As4\As4EnvelopeSigner;
use App\As4\ConfigPeerEndpointResolver;
use App\As4\Http\HttpAs4DeliveryService;
use App\As4\PeerEndpointResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => bootTestPki());

function makeAs4Service(?PeerEndpointResolver $peers = null): HttpAs4DeliveryService
{
    return new HttpAs4DeliveryService(
        builder: new As4EnvelopeBuilder,
        signer: resolve(As4EnvelopeSigner::class),
        peers: $peers ?? new ConfigPeerEndpointResolver(
            map: ['98765432109' => 'http://peer.test'],
            defaultPeerUrl: 'http://default.test',
        ),
        timeout: 5,
    );
}

function ublFixtureXml(): string
{
    $path = __DIR__.'/../../Fixtures/ubl/valid-hr-cius.xml';
    $xml = file_get_contents($path);

    expect($xml)->toBeString()->not->toBeEmpty();

    return (string) $xml;
}

describe('send', function (): void {
    it('returns a parsed As4DeliveryReceipt on a successful eb:Receipt', function (): void {
        $captured = null;

        Http::fake([
            'http://peer.test/api/as4/inbox' => function (Request $request) use (&$captured) {
                $captured = $request;
                preg_match('/<eb:MessageId>([^<]+)<\/eb:MessageId>/', $request->body(), $m);

                return Http::response(as4ReceiptEnvelope($m[1] ?? 'unknown'), 200, [
                    'Content-Type' => 'application/soap+xml; charset=utf-8',
                ]);
            },
        ]);

        $receipt = makeAs4Service()->send(
            ublXml: ublFixtureXml(),
            senderOib: '12345678901',
            recipientOib: '98765432109',
        );

        expect($receipt)->toBeInstanceOf(As4DeliveryReceipt::class)
            ->and($receipt->messageId)->toEndWith('@erakun')
            ->and($receipt->receiptMessageId)->toBe('peer-receipt-001@erakun')
            ->and($receipt->acknowledgedAt->toIso8601ZuluString())->toBe('2026-05-12T10:14:23Z')
            ->and($receipt->envelopeXml)->toContain('<eb:UserMessage>')
            ->and($receipt->envelopeXml)->toContain('<ds:Signature')
            ->and($receipt->receiptXml)->toContain('<eb:Receipt/>');

        expect($captured)->not->toBeNull();
    });

    it('POSTs to the resolved peer URL with application/soap+xml content type', function (): void {
        Http::fake([
            'http://peer.test/api/as4/inbox' => function (Request $request) {
                preg_match('/<eb:MessageId>([^<]+)<\/eb:MessageId>/', $request->body(), $m);

                return Http::response(as4ReceiptEnvelope($m[1] ?? 'unknown'), 200);
            },
        ]);

        makeAs4Service()->send(
            ublXml: ublFixtureXml(),
            senderOib: '12345678901',
            recipientOib: '98765432109',
        );

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://peer.test/api/as4/inbox'
            && $request->method() === 'POST'
            && str_contains((string) $request->header('Content-Type')[0], 'application/soap+xml')
            && str_contains($request->body(), '<soap:Envelope')
            && str_contains($request->body(), '<eb:UserMessage>')
            && str_contains($request->body(), '<ds:Signature'));
    });

    it('falls back to the default peer URL when no explicit OIB mapping exists', function (): void {
        Http::fake([
            'http://default.test/api/as4/inbox' => function (Request $request) {
                preg_match('/<eb:MessageId>([^<]+)<\/eb:MessageId>/', $request->body(), $m);

                return Http::response(as4ReceiptEnvelope($m[1] ?? 'unknown'), 200);
            },
        ]);

        makeAs4Service()->send(
            ublXml: ublFixtureXml(),
            senderOib: '12345678901',
            recipientOib: '00000000000',
        );

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://default.test/api/as4/inbox');
    });

    it('translates an eb:Error response into an As4DeliveryException', function (): void {
        Http::fake([
            'http://peer.test/api/as4/inbox' => function (Request $request) {
                preg_match('/<eb:MessageId>([^<]+)<\/eb:MessageId>/', $request->body(), $m);

                return Http::response(as4ErrorEnvelope($m[1] ?? 'unknown', 'EBMS:0004', 'bad ubl'), 400, [
                    'Content-Type' => 'application/soap+xml; charset=utf-8',
                ]);
            },
        ]);

        try {
            makeAs4Service()->send(
                ublXml: ublFixtureXml(),
                senderOib: '12345678901',
                recipientOib: '98765432109',
            );
            test()->fail('Expected As4DeliveryException was not thrown.');
        } catch (As4DeliveryException $e) {
            expect($e->errorCode)->toBe('EBMS:0004')
                ->and($e->messageId)->not->toBeNull()
                ->and($e->envelopeXml)->toContain('<eb:UserMessage>')
                ->and($e->getMessage())->toBe('bad ubl');
        }
    });

    it('throws EBMS:0005 with a null messageId and null envelope when the recipient is unknown', function (): void {
        $resolver = new ConfigPeerEndpointResolver(map: [], defaultPeerUrl: '');

        try {
            makeAs4Service($resolver)->send(
                ublXml: ublFixtureXml(),
                senderOib: '12345678901',
                recipientOib: '99999999999',
            );
            test()->fail('Expected As4DeliveryException was not thrown.');
        } catch (As4DeliveryException $e) {
            expect($e->errorCode)->toBe('EBMS:0005')
                ->and($e->messageId)->toBeNull()
                ->and($e->envelopeXml)->toBeNull();
        }

        Http::assertNothingSent();
    });

    it('translates a ConnectionException into a TRANSPORT error carrying the outbound messageId and envelope', function (): void {
        Http::fake(fn (): never => throw new ConnectionException('Connection refused'));

        try {
            makeAs4Service()->send(
                ublXml: ublFixtureXml(),
                senderOib: '12345678901',
                recipientOib: '98765432109',
            );
            test()->fail('Expected As4DeliveryException was not thrown.');
        } catch (As4DeliveryException $e) {
            expect($e->errorCode)->toBe('TRANSPORT')
                ->and($e->messageId)->not->toBeNull()
                ->and($e->envelopeXml)->toContain('<eb:UserMessage>')
                ->and($e->getMessage())->toContain('Connection refused');
        }
    });

    it('translates an unexpected response body into a TRANSPORT error', function (): void {
        Http::fake([
            'http://peer.test/api/as4/inbox' => Http::response('<not-an-envelope/>', 200),
        ]);

        try {
            makeAs4Service()->send(
                ublXml: ublFixtureXml(),
                senderOib: '12345678901',
                recipientOib: '98765432109',
            );
            test()->fail('Expected As4DeliveryException was not thrown.');
        } catch (As4DeliveryException $e) {
            expect($e->errorCode)->toBe('TRANSPORT');
        }
    });

    it('translates a malformed XML response into a TRANSPORT error', function (): void {
        Http::fake([
            'http://peer.test/api/as4/inbox' => Http::response('<<<garbage', 200),
        ]);

        try {
            makeAs4Service()->send(
                ublXml: ublFixtureXml(),
                senderOib: '12345678901',
                recipientOib: '98765432109',
            );
            test()->fail('Expected As4DeliveryException was not thrown.');
        } catch (As4DeliveryException $e) {
            expect($e->errorCode)->toBe('TRANSPORT')
                ->and($e->getMessage())->toContain('malformed');
        }
    });
});
