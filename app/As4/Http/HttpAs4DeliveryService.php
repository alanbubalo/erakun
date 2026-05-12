<?php

declare(strict_types=1);

namespace App\As4\Http;

use App\Actions\InvoiceSigner;
use App\As4\As4DeliveryException;
use App\As4\As4DeliveryReceipt;
use App\As4\As4DeliveryService;
use App\As4\As4EnvelopeBuilder;
use App\As4\PeerEndpointResolver;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class HttpAs4DeliveryService implements As4DeliveryService
{
    private const string SOAP_CONTENT_TYPE = 'application/soap+xml; charset=utf-8';

    private const string INBOX_PATH = '/api/as4/inbox';

    public function __construct(
        private As4EnvelopeBuilder $builder,
        private InvoiceSigner $signer,
        private PeerEndpointResolver $peers,
        private int $timeout,
    ) {}

    public function send(string $ublXml, string $senderOib, string $recipientOib): As4DeliveryReceipt
    {
        $peerUrl = $this->peers->resolve($recipientOib);

        throw_if($peerUrl === null, new As4DeliveryException('EBMS:0005', null, "No peer AP configured for recipient OIB {$recipientOib}."));

        $messageId = Str::uuid()->toString().'@erakun';

        $envelope = $this->buildSignedEnvelope($ublXml, $messageId, $senderOib, $recipientOib);

        try {
            $response = Http::baseUrl($peerUrl)
                ->timeout($this->timeout)
                ->withBody($envelope, self::SOAP_CONTENT_TYPE)
                ->withHeaders(['Accept' => self::SOAP_CONTENT_TYPE])
                ->post(self::INBOX_PATH);
        } catch (ConnectionException $e) {
            throw new As4DeliveryException('TRANSPORT', $messageId, 'AS4 peer unreachable: '.$e->getMessage(), $envelope);
        }

        return $this->parseResponse($response, $messageId, $envelope);
    }

    private function buildSignedEnvelope(
        string $ublXml,
        string $messageId,
        string $senderOib,
        string $recipientOib,
    ): string {
        try {
            $dom = $this->builder->build($ublXml, $messageId, $senderOib, $recipientOib);
            $signed = $this->signer->execute($dom);
            $xml = $signed->saveXML();
        } catch (Throwable $e) {
            throw new As4DeliveryException('TRANSPORT', $messageId, 'Failed to build AS4 envelope: '.$e->getMessage());
        }

        throw_if($xml === false, new As4DeliveryException('TRANSPORT', $messageId, 'Failed to serialize AS4 envelope.'));

        return $xml;
    }

    private function parseResponse(Response $response, string $messageId, string $envelope): As4DeliveryReceipt
    {
        $raw = $response->body();

        throw_if($raw === '', new As4DeliveryException('TRANSPORT', $messageId, "AS4 peer returned an empty body (HTTP {$response->status()}).", $envelope));

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        $loaded = $dom->loadXML($raw, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        throw_if($loaded === false, new As4DeliveryException('TRANSPORT', $messageId, "AS4 peer returned malformed XML (HTTP {$response->status()}).", $envelope));

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('soap', As4EnvelopeBuilder::NS_SOAP);
        $xpath->registerNamespace('eb', As4EnvelopeBuilder::NS_EB);

        $signal = $xpath->query('/soap:Envelope/soap:Header/eb:Messaging/eb:SignalMessage')->item(0);

        throw_unless($signal instanceof DOMElement, new As4DeliveryException('TRANSPORT', $messageId, 'AS4 peer response is missing eb:SignalMessage.', $envelope));

        $error = $xpath->query('eb:Error', $signal)->item(0);

        if ($error instanceof DOMElement) {
            throw $this->buildErrorException($error, $messageId, $envelope);
        }

        $receipt = $xpath->query('eb:Receipt', $signal)->item(0);

        throw_unless($receipt instanceof DOMElement, new As4DeliveryException('TRANSPORT', $messageId, 'AS4 peer signal is neither eb:Receipt nor eb:Error.', $envelope));

        return $this->buildReceipt($xpath, $signal, $messageId, $envelope, $raw);
    }

    private function buildErrorException(DOMElement $error, string $messageId, string $envelope): As4DeliveryException
    {
        $errorCode = trim($error->getAttribute('errorCode'));

        if ($errorCode === '') {
            $errorCode = 'TRANSPORT';
        }

        $shortDescription = trim($error->getAttribute('shortDescription'));

        $description = '';
        foreach ($error->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'Description') {
                $description = trim((string) $child->textContent);
                break;
            }
        }

        $message = match (true) {
            $description !== '' => $description,
            $shortDescription !== '' => $shortDescription,
            default => 'AS4 peer rejected the message.',
        };

        return new As4DeliveryException($errorCode, $messageId, $message, $envelope);
    }

    private function buildReceipt(
        DOMXPath $xpath,
        DOMElement $signal,
        string $messageId,
        string $envelope,
        string $raw,
    ): As4DeliveryReceipt {
        try {
            $receiptMessageId = $this->requireText($xpath, $signal, 'eb:MessageInfo/eb:MessageId', $messageId, $envelope);
            $timestamp = $this->requireText($xpath, $signal, 'eb:MessageInfo/eb:Timestamp', $messageId, $envelope);
            $acknowledgedAt = CarbonImmutable::parse($timestamp);
        } catch (RuntimeException $e) {
            throw new As4DeliveryException('TRANSPORT', $messageId, 'AS4 peer returned an unparseable receipt: '.$e->getMessage(), $envelope);
        }

        return new As4DeliveryReceipt(
            messageId: $messageId,
            receiptMessageId: $receiptMessageId,
            acknowledgedAt: $acknowledgedAt,
            envelopeXml: $envelope,
            receiptXml: $raw,
        );
    }

    private function requireText(DOMXPath $xpath, DOMElement $context, string $expression, string $messageId, string $envelope): string
    {
        $node = $xpath->query($expression, $context)->item(0);
        $value = $node === null ? '' : trim((string) $node->textContent);

        throw_if($value === '', new As4DeliveryException('TRANSPORT', $messageId, "Missing required field in AS4 receipt: {$expression}", $envelope));

        return $value;
    }
}
