<?php

declare(strict_types=1);

namespace App\Fiscalization\Http;

use App\Enums\MatchStatus;
use App\Fiscalization\FiscalizationResponse;
use App\Fiscalization\FiscalizationService;
use App\Fiscalization\FiscalizationServiceException;
use App\Fiscalization\MatchReport;
use App\Fiscalization\SubmissionSnapshot;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class HttpFiscalizationService implements FiscalizationService
{
    private const string NS = 'urn:hr:erakun:fiscal:1.0';

    private const string SOAP_NS = 'http://www.w3.org/2003/05/soap-envelope';

    private const string SOAP_CONTENT_TYPE = 'application/soap+xml; charset=utf-8';

    public function __construct(
        private string $baseUrl,
        private int $timeout,
    ) {}

    public function fiscalize(string $signedRequestXml): FiscalizationResponse
    {
        $envelope = $this->wrapEnvelope($signedRequestXml);

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->withBody($envelope, self::SOAP_CONTENT_TYPE)
                ->withHeaders(['Accept' => self::SOAP_CONTENT_TYPE])
                ->post('/fiscalize');
        } catch (ConnectionException $e) {
            throw new FiscalizationServiceException('TRANSPORT', 'Fiscalization service unreachable: '.$e->getMessage());
        }

        $body = $this->parseEnvelopeBody($response);

        $this->throwIfFault($body);

        return $this->parseFiscalizationResponse($body);
    }

    public function lookupMatch(string $supplierOib, string $buyerOib, string $invoiceNumber): MatchReport
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get('/match', [
                    'supplier_oib' => $supplierOib,
                    'buyer_oib' => $buyerOib,
                    'invoice_number' => $invoiceNumber,
                ]);
        } catch (ConnectionException $e) {
            throw new FiscalizationServiceException('TRANSPORT', 'Fiscalization service unreachable: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new FiscalizationServiceException('TRANSPORT', "Match lookup failed with HTTP {$response->status()}.");
        }

        /** @var array{
         *   match_status: string,
         *   mismatch_fields: list<string>,
         *   supplier_submission: ?array<string, string>,
         *   buyer_submission: ?array<string, string>,
         * } $payload
         */
        $payload = $response->json();

        return new MatchReport(
            supplierSubmission: $this->snapshotFromJson($payload['supplier_submission']),
            buyerSubmission: $this->snapshotFromJson($payload['buyer_submission']),
            matchStatus: MatchStatus::from($payload['match_status']),
            mismatchFields: $payload['mismatch_fields'],
        );
    }

    private function wrapEnvelope(string $bodyXml): string
    {
        $bodyXml = preg_replace('/^\s*<\?xml[^?]*\?>\s*/', '', $bodyXml) ?? $bodyXml;

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<soap:Envelope xmlns:soap="'.self::SOAP_NS.'">'."\n"
            .'  <soap:Body>'."\n"
            .$bodyXml."\n"
            .'  </soap:Body>'."\n"
            .'</soap:Envelope>';
    }

    private function parseEnvelopeBody(Response $response): DOMElement
    {
        $raw = $response->body();

        if ($raw === '') {
            throw new FiscalizationServiceException('TRANSPORT', "Fiscalization service returned an empty body (HTTP {$response->status()}).");
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        $loaded = $dom->loadXML($raw, LIBXML_NONET);

        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new FiscalizationServiceException('TRANSPORT', "Fiscalization service returned malformed XML (HTTP {$response->status()}).");
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('soap', self::SOAP_NS);

        $body = $xpath->query('/soap:Envelope/soap:Body/*')->item(0);

        throw_unless($body instanceof DOMElement, FiscalizationServiceException::class, 'TRANSPORT', 'Fiscalization service response is missing a SOAP body child element.');

        return $body;
    }

    private function throwIfFault(DOMElement $body): void
    {
        if ($body->namespaceURI !== self::SOAP_NS || $body->localName !== 'Fault') {
            return;
        }

        $xpath = new DOMXPath($body->ownerDocument);
        $xpath->registerNamespace('soap', self::SOAP_NS);

        $subcode = $xpath->query('.//soap:Code/soap:Subcode/soap:Value', $body)->item(0)?->textContent;
        $reason = $xpath->query('.//soap:Reason/soap:Text', $body)->item(0)?->textContent;

        $code = trim((string) ($subcode ?? '')) !== '' ? trim((string) $subcode) : 'UNKNOWN';
        $message = trim((string) ($reason ?? '')) !== '' ? trim((string) $reason) : 'Fiscalization service rejected the request.';

        throw new FiscalizationServiceException($code, $message);
    }

    private function parseFiscalizationResponse(DOMElement $body): FiscalizationResponse
    {
        if ($body->namespaceURI !== self::NS || $body->localName !== 'FiscalizationResponse') {
            throw new FiscalizationServiceException('TRANSPORT', "Unexpected response element <{$body->localName}>.");
        }

        $xpath = new DOMXPath($body->ownerDocument);
        $xpath->registerNamespace('f', self::NS);

        $messageId = $this->requireText($xpath, $body, 'f:MessageId');
        $receivedAt = $this->requireText($xpath, $body, 'f:ReceivedAt');
        $matchStatus = $this->requireText($xpath, $body, 'f:MatchStatus');

        try {
            return new FiscalizationResponse(
                messageId: $messageId,
                receivedAt: CarbonImmutable::parse($receivedAt),
                matchStatus: MatchStatus::from($matchStatus),
            );
        } catch (Throwable $e) {
            throw new FiscalizationServiceException('TRANSPORT', 'Fiscalization service returned an unparseable response: '.$e->getMessage());
        }
    }

    private function requireText(DOMXPath $xpath, DOMElement $context, string $expression): string
    {
        $node = $xpath->query($expression, $context)->item(0);
        $value = $node === null ? '' : trim((string) $node->textContent);

        throw_if($value === '', FiscalizationServiceException::class, 'TRANSPORT', "Missing required field in service response: {$expression}");

        return $value;
    }

    /**
     * @param  ?array<string, string>  $row
     */
    private function snapshotFromJson(?array $row): ?SubmissionSnapshot
    {
        if ($row === null) {
            return null;
        }

        return new SubmissionSnapshot(
            messageId: $row['message_id'],
            reporterOib: $row['reporter_oib'],
            receivedAt: CarbonImmutable::parse($row['received_at']),
            netAmount: $row['net_amount'],
            taxAmount: $row['tax_amount'],
            totalAmount: $row['total_amount'],
        );
    }
}
