<?php

declare(strict_types=1);

use App\Actions\IssuePartyCertificate;
use App\Models\Certificate;
use App\Models\Invoice;
use App\Models\Party;
use App\Pki\PartySigningCredentials;
use App\Pki\SigningCredential;
use App\Pki\TestPkiGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit', 'Integration');

// UBL documents and PKI material live on filesystem disks (only paths/metadata are
// persisted in the DB). Fake both per test so the pipeline never touches real storage.
uses()->beforeEach(function (): void {
    Storage::fake();
    Storage::fake('pki');
})->in('Feature', 'Unit', 'Integration');

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

function as4ReceiptEnvelope(
    string $refMessageId,
    string $peerMessageId = 'peer-receipt-001@erakun',
    string $timestamp = '2026-05-12T10:14:23Z',
): string {
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"
                       xmlns:eb="http://docs.oasis-open.org/ebxml-msg/ebms/v3.0/ns/core/200704/">
          <soap:Header>
            <eb:Messaging>
              <eb:SignalMessage>
                <eb:MessageInfo>
                  <eb:Timestamp>{$timestamp}</eb:Timestamp>
                  <eb:MessageId>{$peerMessageId}</eb:MessageId>
                  <eb:RefToMessageId>{$refMessageId}</eb:RefToMessageId>
                </eb:MessageInfo>
                <eb:Receipt/>
              </eb:SignalMessage>
            </eb:Messaging>
          </soap:Header>
          <soap:Body/>
        </soap:Envelope>
        XML;
}

function as4ErrorEnvelope(
    string $refMessageId,
    string $errorCode = 'EBMS:0004',
    string $description = 'UBL payload failed HR-CIUS schema validation.',
): string {
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"
                       xmlns:eb="http://docs.oasis-open.org/ebxml-msg/ebms/v3.0/ns/core/200704/">
          <soap:Header>
            <eb:Messaging>
              <eb:SignalMessage>
                <eb:MessageInfo>
                  <eb:Timestamp>2026-05-12T10:14:23Z</eb:Timestamp>
                  <eb:MessageId>peer-err-001@erakun</eb:MessageId>
                  <eb:RefToMessageId>{$refMessageId}</eb:RefToMessageId>
                </eb:MessageInfo>
                <eb:Error errorCode="{$errorCode}"
                          severity="failure"
                          origin="ebms"
                          category="Content"
                          shortDescription="PayloadValidationFailure">
                  <eb:Description xml:lang="en">{$description}</eb:Description>
                </eb:Error>
              </eb:SignalMessage>
            </eb:Messaging>
          </soap:Header>
          <soap:Body/>
        </soap:Envelope>
        XML;
}

/**
 * Fake the PKI disk and mint the test hierarchy (root CAs + access point cert).
 * Call in beforeEach for any test that signs or verifies real signatures.
 */
function bootTestPki(): void
{
    Storage::fake('pki');
    resolve(TestPkiGenerator::class)->generate();
}

/** Issue + register an active signing certificate for a party (requires bootTestPki). */
function issueTestCertificate(Party $party): Certificate
{
    return resolve(IssuePartyCertificate::class)->execute($party);
}

/** Resolve a party's active signing credential (requires an issued certificate). */
function testSigningCredential(Party $party): SigningCredential
{
    return resolve(PartySigningCredentials::class)->for($party);
}

/** Ensure both of an invoice's parties hold an active signing certificate (boots PKI if needed). */
function provisionInvoiceCertificates(Invoice $invoice): void
{
    if (! Storage::disk((string) config('pki.disk'))->exists(TestPkiGenerator::FINA_CA_CERT)) {
        resolve(TestPkiGenerator::class)->generate();
    }

    foreach ([$invoice->supplier, $invoice->buyer] as $party) {
        if ($party !== null && $party->activeCertificate()->doesntExist()) {
            issueTestCertificate($party);
        }
    }
}
