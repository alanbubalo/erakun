<?php

declare(strict_types=1);

namespace App\As4;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;

class As4ErrorBuilder
{
    private const string SEVERITY = 'failure';

    private const string ORIGIN = 'ebms';

    public function build(
        string $messageId,
        ?string $refToMessageId,
        string $errorCode,
        string $errorMessage,
        CarbonImmutable $timestamp,
    ): DOMDocument {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $envelope = $dom->createElementNS(As4EnvelopeBuilder::NS_SOAP, 'soap:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:eb', As4EnvelopeBuilder::NS_EB);
        $dom->appendChild($envelope);

        $envelope->appendChild($this->buildHeader($dom, $messageId, $refToMessageId, $errorCode, $errorMessage, $timestamp));
        $envelope->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_SOAP, 'soap:Body'));

        return $dom;
    }

    private function buildHeader(
        DOMDocument $dom,
        string $messageId,
        ?string $refToMessageId,
        string $errorCode,
        string $errorMessage,
        CarbonImmutable $timestamp,
    ): DOMElement {
        $header = $dom->createElementNS(As4EnvelopeBuilder::NS_SOAP, 'soap:Header');

        $messaging = $dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:Messaging');
        $messaging->appendChild($this->buildSignalMessage($dom, $messageId, $refToMessageId, $errorCode, $errorMessage, $timestamp));
        $header->appendChild($messaging);

        return $header;
    }

    private function buildSignalMessage(
        DOMDocument $dom,
        string $messageId,
        ?string $refToMessageId,
        string $errorCode,
        string $errorMessage,
        CarbonImmutable $timestamp,
    ): DOMElement {
        $signal = $dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:SignalMessage');

        $info = $dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:MessageInfo');
        $info->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:Timestamp', $timestamp->toIso8601ZuluString()));
        $info->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:MessageId', $messageId));
        if ($refToMessageId !== null && $refToMessageId !== '') {
            $info->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:RefToMessageId', $refToMessageId));
        }
        $signal->appendChild($info);

        $error = $dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:Error');
        $error->setAttribute('errorCode', $errorCode);
        $error->setAttribute('severity', self::SEVERITY);
        $error->setAttribute('origin', self::ORIGIN);
        $description = $dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:Description', $errorMessage);
        $description->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'en');
        $error->appendChild($description);
        $signal->appendChild($error);

        return $signal;
    }
}
