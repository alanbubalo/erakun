<?php

declare(strict_types=1);

use App\Enums\MatchStatus;
use App\Fiscalization\FiscalizationResponse;
use App\Fiscalization\FiscalizationServiceException;
use App\Fiscalization\Http\HttpFiscalizationService;
use App\Fiscalization\MatchReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeHttpService(): HttpFiscalizationService
{
    return new HttpFiscalizationService(baseUrl: 'http://cis.test', timeout: 5);
}

function soapSuccess(string $messageId = 'FIS-2026-000001', string $matchStatus = 'pending'): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
          <soap:Body>
            <FiscalizationResponse xmlns="urn:hr:erakun:fiscal:1.0">
              <MessageId>{$messageId}</MessageId>
              <ReceivedAt>2026-05-11T10:14:22Z</ReceivedAt>
              <MatchStatus>{$matchStatus}</MatchStatus>
            </FiscalizationResponse>
          </soap:Body>
        </soap:Envelope>
        XML;
}

function soapFault(string $code, string $reason = 'Service rejected the request.'): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
          <soap:Body>
            <soap:Fault>
              <soap:Code>
                <soap:Value>soap:Sender</soap:Value>
                <soap:Subcode><soap:Value>{$code}</soap:Value></soap:Subcode>
              </soap:Code>
              <soap:Reason><soap:Text xml:lang="en">{$reason}</soap:Text></soap:Reason>
            </soap:Fault>
          </soap:Body>
        </soap:Envelope>
        XML;
}

describe('fiscalize', function (): void {
    it('returns a parsed FiscalizationResponse on SOAP success', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(soapSuccess('FIS-2026-000042', 'pending'), 200, [
                'Content-Type' => 'application/soap+xml; charset=utf-8',
            ]),
        ]);

        $response = makeHttpService()->fiscalize('<FiscalizationRequest xmlns="urn:hr:erakun:fiscal:1.0"/>');

        expect($response)->toBeInstanceOf(FiscalizationResponse::class)
            ->and($response->messageId)->toBe('FIS-2026-000042')
            ->and($response->matchStatus)->toBe(MatchStatus::Pending)
            ->and($response->receivedAt->toIso8601ZuluString())->toBe('2026-05-11T10:14:22Z');
    });

    it('wraps the request body in a SOAP envelope with the right content type', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(soapSuccess(), 200),
        ]);

        makeHttpService()->fiscalize('<FiscalizationRequest xmlns="urn:hr:erakun:fiscal:1.0"/>');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://cis.test/fiscalize'
            && str_contains((string) $request->header('Content-Type')[0], 'application/soap+xml')
            && str_contains($request->body(), '<soap:Envelope')
            && str_contains($request->body(), '<soap:Body>')
            && str_contains($request->body(), '<FiscalizationRequest'));
    });

    it('throws FiscalizationServiceException with the subcode on soap:Fault', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(soapFault('XSD_INVALID', 'Bad shape.'), 400, [
                'Content-Type' => 'application/soap+xml; charset=utf-8',
            ]),
        ]);

        try {
            makeHttpService()->fiscalize('<FiscalizationRequest xmlns="urn:hr:erakun:fiscal:1.0"/>');
            test()->fail('Expected FiscalizationServiceException was not thrown.');
        } catch (FiscalizationServiceException $e) {
            expect($e->errorCode)->toBe('XSD_INVALID')
                ->and($e->getMessage())->toBe('Bad shape.');
        }
    });

    it('throws TRANSPORT when the service is unreachable', function (): void {
        Http::fake(fn (): never => throw new ConnectionException('Connection refused'));

        try {
            makeHttpService()->fiscalize('<FiscalizationRequest xmlns="urn:hr:erakun:fiscal:1.0"/>');
            test()->fail('Expected FiscalizationServiceException was not thrown.');
        } catch (FiscalizationServiceException $e) {
            expect($e->errorCode)->toBe('TRANSPORT')
                ->and($e->getMessage())->toContain('Connection refused');
        }
    });

    it('throws TRANSPORT when the response is not a recognized envelope', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response('<not-soap/>', 200),
        ]);

        try {
            makeHttpService()->fiscalize('<FiscalizationRequest xmlns="urn:hr:erakun:fiscal:1.0"/>');
            test()->fail('Expected FiscalizationServiceException was not thrown.');
        } catch (FiscalizationServiceException $e) {
            expect($e->errorCode)->toBe('TRANSPORT');
        }
    });
});

describe('lookupMatch', function (): void {
    it('parses a matched MatchReport with both snapshots', function (): void {
        Http::fake([
            'http://cis.test/match*' => Http::response([
                'match_status' => 'matched',
                'mismatch_fields' => [],
                'supplier_submission' => [
                    'message_id' => 'FIS-2026-000001',
                    'reporter_oib' => '22222222226',
                    'received_at' => '2026-05-11T10:14:22Z',
                    'net_amount' => '100.00',
                    'tax_amount' => '25.00',
                    'total_amount' => '125.00',
                ],
                'buyer_submission' => [
                    'message_id' => 'FIS-2026-000002',
                    'reporter_oib' => '11111111119',
                    'received_at' => '2026-05-11T10:15:00Z',
                    'net_amount' => '100.00',
                    'tax_amount' => '25.00',
                    'total_amount' => '125.00',
                ],
            ], 200),
        ]);

        $report = makeHttpService()->lookupMatch('22222222226', '11111111119', 'RN-2026-00001');

        expect($report)->toBeInstanceOf(MatchReport::class)
            ->and($report->matchStatus)->toBe(MatchStatus::Matched)
            ->and($report->mismatchFields)->toBe([])
            ->and($report->supplierSubmission)->not->toBeNull()
            ->and($report->supplierSubmission->messageId)->toBe('FIS-2026-000001')
            ->and($report->buyerSubmission)->not->toBeNull()
            ->and($report->buyerSubmission->messageId)->toBe('FIS-2026-000002');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://cis.test/match')
            && $request['supplier_oib'] === '22222222226'
            && $request['buyer_oib'] === '11111111119'
            && $request['invoice_number'] === 'RN-2026-00001');
    });

    it('parses a pending MatchReport with a missing counterpart snapshot', function (): void {
        Http::fake([
            'http://cis.test/match*' => Http::response([
                'match_status' => 'pending',
                'mismatch_fields' => [],
                'supplier_submission' => [
                    'message_id' => 'FIS-2026-000001',
                    'reporter_oib' => '22222222226',
                    'received_at' => '2026-05-11T10:14:22Z',
                    'net_amount' => '100.00',
                    'tax_amount' => '25.00',
                    'total_amount' => '125.00',
                ],
                'buyer_submission' => null,
            ], 200),
        ]);

        $report = makeHttpService()->lookupMatch('22222222226', '11111111119', 'RN-2026-00001');

        expect($report->matchStatus)->toBe(MatchStatus::Pending)
            ->and($report->supplierSubmission)->not->toBeNull()
            ->and($report->buyerSubmission)->toBeNull();
    });

    it('parses a mismatch MatchReport with the diverging fields', function (): void {
        Http::fake([
            'http://cis.test/match*' => Http::response([
                'match_status' => 'mismatch',
                'mismatch_fields' => ['net_amount', 'total_amount'],
                'supplier_submission' => [
                    'message_id' => 'FIS-2026-000001',
                    'reporter_oib' => '22222222226',
                    'received_at' => '2026-05-11T10:14:22Z',
                    'net_amount' => '100.00',
                    'tax_amount' => '25.00',
                    'total_amount' => '125.00',
                ],
                'buyer_submission' => [
                    'message_id' => 'FIS-2026-000002',
                    'reporter_oib' => '11111111119',
                    'received_at' => '2026-05-11T10:15:00Z',
                    'net_amount' => '110.00',
                    'tax_amount' => '25.00',
                    'total_amount' => '135.00',
                ],
            ], 200),
        ]);

        $report = makeHttpService()->lookupMatch('22222222226', '11111111119', 'RN-2026-00001');

        expect($report->matchStatus)->toBe(MatchStatus::Mismatch)
            ->and($report->mismatchFields)->toBe(['net_amount', 'total_amount']);
    });

    it('throws TRANSPORT when match lookup returns a non-2xx status', function (): void {
        Http::fake([
            'http://cis.test/match*' => Http::response('boom', 500),
        ]);

        try {
            makeHttpService()->lookupMatch('22222222226', '11111111119', 'RN-2026-00001');
            test()->fail('Expected FiscalizationServiceException was not thrown.');
        } catch (FiscalizationServiceException $e) {
            expect($e->errorCode)->toBe('TRANSPORT');
        }
    });
});
