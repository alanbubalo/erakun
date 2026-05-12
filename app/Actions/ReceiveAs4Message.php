<?php

declare(strict_types=1);

namespace App\Actions;

use App\As4\As4EnvelopeBuilder;
use App\As4\As4ErrorBuilder;
use App\As4\As4ReceiptBuilder;
use App\As4\ReceiveAs4Result;
use App\Enums\As4MessageDirection;
use App\Enums\As4MessageState;
use App\Exceptions\As4InboundException;
use App\Exceptions\InvoiceValidationException;
use App\Models\As4Message;
use App\Models\Taxpayer;
use App\Validation\UblValidator;
use App\Validation\ValidationReport;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReceiveAs4Message
{
    private const string NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const string NS_UBL_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const int HTTP_OK = 200;

    private const int HTTP_ERROR = 400;

    public function __construct(
        private readonly UblValidator $validator,
        private readonly UblParser $parser,
        private readonly ReceiveInbound $receiveInbound,
        private readonly As4ReceiptBuilder $receiptBuilder,
        private readonly As4ErrorBuilder $errorBuilder,
    ) {}

    public function execute(string $envelopeXml): ReceiveAs4Result
    {
        try {
            $dom = $this->parseAndValidateEnvelope($envelopeXml);
            $metadata = $this->extractMetadata($dom);
            $this->assertSignaturePresent($dom);
        } catch (As4InboundException $e) {
            return $this->errorResult($e);
        }

        $existing = As4Message::where('message_id', $metadata['messageId'])->first();
        if ($existing instanceof As4Message) {
            return $this->replayResult($existing);
        }

        if (! Taxpayer::where('oib', $metadata['toOib'])->exists()) {
            return $this->errorResult(new As4InboundException(
                'EBMS:0005',
                "Recipient OIB {$metadata['toOib']} is not registered with this intermediary.",
                $metadata['messageId'],
            ));
        }

        $message = $this->persistReceived($envelopeXml, $metadata);

        try {
            $ublXml = $this->extractUblPayload($dom);
            $report = $this->validator->validate($ublXml);

            throw_unless($report->isValid(), new As4InboundException(
                'EBMS:0004',
                'UBL payload failed HR-CIUS validation: '.$this->summariseReport($report),
                $metadata['messageId'],
            ));

            $parsed = $this->parser->parse($ublXml);
            $invoice = $this->receiveInbound->execute($parsed, $ublXml);
        } catch (As4InboundException $e) {
            return $this->errorResultForMessage($message, $e);
        } catch (InvoiceValidationException $e) {
            return $this->errorResultForMessage($message, new As4InboundException(
                'EBMS:0004',
                'UBL payload rejected: '.$e->getMessage(),
                $metadata['messageId'],
            ));
        } catch (Throwable $e) {
            return $this->errorResultForMessage($message, new As4InboundException(
                'EBMS:0004',
                'Failed to ingest UBL payload: '.$e->getMessage(),
                $metadata['messageId'],
            ));
        }

        $receiptXml = $this->buildReceipt($metadata['messageId']);

        $message->update([
            'state' => As4MessageState::Delivered,
            'invoice_id' => $invoice->id,
            'receipt_xml' => $receiptXml,
        ]);

        return new ReceiveAs4Result(
            responseXml: $receiptXml,
            httpStatus: self::HTTP_OK,
            message: $message->refresh(),
        );
    }

    private function parseAndValidateEnvelope(string $envelopeXml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        $loaded = $dom->loadXML($envelopeXml, LIBXML_NONET);

        if (! $loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            throw new As4InboundException('EBMS:0009', 'AS4 envelope is not well-formed XML.');
        }

        $valid = $dom->schemaValidate(resource_path('schemas/as4/as4-envelope.xsd'));
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $valid) {
            $first = $errors[0] ?? null;
            $detail = $first !== null ? trim($first->message) : 'unknown';
            throw new As4InboundException('EBMS:0009', 'AS4 envelope failed schema validation: '.$detail);
        }

        return $dom;
    }

    /**
     * @return array{messageId: string, fromOib: string, toOib: string}
     */
    private function extractMetadata(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('soap', As4EnvelopeBuilder::NS_SOAP);
        $xpath->registerNamespace('eb', As4EnvelopeBuilder::NS_EB);

        $messageId = trim((string) $xpath->query('/soap:Envelope/soap:Header/eb:Messaging/eb:UserMessage/eb:MessageInfo/eb:MessageId')->item(0)?->textContent);
        $fromOib = trim((string) $xpath->query('/soap:Envelope/soap:Header/eb:Messaging/eb:UserMessage/eb:PartyInfo/eb:From/eb:PartyId')->item(0)?->textContent);
        $toOib = trim((string) $xpath->query('/soap:Envelope/soap:Header/eb:Messaging/eb:UserMessage/eb:PartyInfo/eb:To/eb:PartyId')->item(0)?->textContent);

        // XSD already enforces these are present and well-formed; defensive check.
        throw_if(
            $messageId === '' || $fromOib === '' || $toOib === '',
            new As4InboundException('EBMS:0009', 'AS4 envelope is missing required header fields.'),
        );

        return ['messageId' => $messageId, 'fromOib' => $fromOib, 'toOib' => $toOib];
    }

    private function assertSignaturePresent(DOMDocument $dom): void
    {
        $signatures = $dom->getElementsByTagNameNS(self::NS_DS, 'Signature');

        throw_if($signatures->length === 0, new As4InboundException(
            'EBMS:0009',
            'AS4 envelope is missing the required <ds:Signature> element.',
        ));
    }

    private function extractUblPayload(DOMDocument $dom): string
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('soap', As4EnvelopeBuilder::NS_SOAP);
        $xpath->registerNamespace('as4', As4EnvelopeBuilder::NS_AS4);
        $xpath->registerNamespace('ubl', self::NS_UBL_INVOICE);

        $invoice = $xpath->query('/soap:Envelope/soap:Body/as4:UblPayload/ubl:Invoice')->item(0);

        throw_unless($invoice instanceof DOMElement, new As4InboundException(
            'EBMS:0004',
            'AS4 envelope body is missing a UBL Invoice payload.',
        ));

        $clone = new DOMDocument('1.0', 'UTF-8');
        $clone->appendChild($clone->importNode($invoice, true));
        $xml = $clone->saveXML();

        throw_if($xml === false, RuntimeException::class, 'Failed to serialize inner UBL payload.');

        return $xml;
    }

    /**
     * @param  array{messageId: string, fromOib: string, toOib: string}  $metadata
     */
    private function persistReceived(string $envelopeXml, array $metadata): As4Message
    {
        return As4Message::create([
            'direction' => As4MessageDirection::Inbound,
            'message_id' => $metadata['messageId'],
            'from_oib' => $metadata['fromOib'],
            'to_oib' => $metadata['toOib'],
            'state' => As4MessageState::Received,
            'envelope_xml' => $envelopeXml,
            'received_at' => now(),
        ]);
    }

    private function buildReceipt(string $refToMessageId): string
    {
        $dom = $this->receiptBuilder->build(
            messageId: Str::uuid()->toString().'@erakun',
            refToMessageId: $refToMessageId,
            timestamp: CarbonImmutable::now(),
        );

        $xml = $dom->saveXML();

        throw_if($xml === false, RuntimeException::class, 'Failed to serialize AS4 receipt envelope.');

        return $xml;
    }

    private function buildErrorEnvelope(As4InboundException $exception): string
    {
        $dom = $this->errorBuilder->build(
            messageId: Str::uuid()->toString().'@erakun',
            refToMessageId: $exception->refToMessageId,
            errorCode: $exception->errorCode,
            errorMessage: $exception->getMessage(),
            timestamp: CarbonImmutable::now(),
        );

        $xml = $dom->saveXML();

        throw_if($xml === false, RuntimeException::class, 'Failed to serialize AS4 error envelope.');

        return $xml;
    }

    private function errorResult(As4InboundException $exception): ReceiveAs4Result
    {
        return new ReceiveAs4Result(
            responseXml: $this->buildErrorEnvelope($exception),
            httpStatus: self::HTTP_ERROR,
        );
    }

    private function errorResultForMessage(As4Message $message, As4InboundException $exception): ReceiveAs4Result
    {
        $xml = $this->buildErrorEnvelope($exception);

        $message->update([
            'state' => As4MessageState::Error,
            'error_code' => $exception->errorCode,
            'error_message' => $exception->getMessage(),
            'receipt_xml' => $xml,
        ]);

        return new ReceiveAs4Result(
            responseXml: $xml,
            httpStatus: self::HTTP_ERROR,
            message: $message->refresh(),
        );
    }

    private function replayResult(As4Message $existing): ReceiveAs4Result
    {
        $status = $existing->state === As4MessageState::Error ? self::HTTP_ERROR : self::HTTP_OK;

        return new ReceiveAs4Result(
            responseXml: (string) $existing->receipt_xml,
            httpStatus: $status,
            message: $existing,
        );
    }

    private function summariseReport(ValidationReport $report): string
    {
        $first = $report->issues[0] ?? null;

        return $first === null ? 'invalid' : "{$first->rule}: {$first->message}";
    }
}
