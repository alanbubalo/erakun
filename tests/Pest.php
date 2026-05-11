<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit', 'Integration');

function fiscAcceptedEnvelope(string $messageId = 'FIS-2026-000042', string $matchStatus = 'pending'): string
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

function fiscFaultEnvelope(string $code, string $reason): string
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

/**
 * @param  list<string>  $mismatchFields
 * @return array{
 *   match_status: string,
 *   mismatch_fields: list<string>,
 *   supplier_submission: ?array<string, string>,
 *   buyer_submission: ?array<string, string>,
 * }
 */
function fiscMatchPayload(string $matchStatus, array $mismatchFields = []): array
{
    return [
        'match_status' => $matchStatus,
        'mismatch_fields' => $mismatchFields,
        'supplier_submission' => null,
        'buyer_submission' => null,
    ];
}

function postInboundXml(string $xml)
{
    return test()->call(
        'POST',
        '/api/invoices/inbound',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/xml', 'HTTP_ACCEPT' => 'application/json'],
        $xml,
    );
}
