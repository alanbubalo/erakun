<?php

declare(strict_types=1);

namespace App\As4;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;

class As4ReceiptBuilder
{
    public function build(
        string $messageId,
        string $refToMessageId,
        CarbonImmutable $timestamp,
    ): DOMDocument {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $envelope = $dom->createElementNS(As4EnvelopeBuilder::NS_SOAP, 'soap:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:eb', As4EnvelopeBuilder::NS_EB);
        $dom->appendChild($envelope);

        $envelope->appendChild($this->buildHeader($dom, $messageId, $refToMessageId, $timestamp));
        $envelope->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_SOAP, 'soap:Body'));

        return $dom;
    }

    private function buildHeader(
        DOMDocument $dom,
        string $messageId,
        string $refToMessageId,
        CarbonImmutable $timestamp,
    ): DOMElement {
        $header = $dom->createElementNS(As4EnvelopeBuilder::NS_SOAP, 'soap:Header');

        $messaging = $dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:Messaging');
        $messaging->appendChild($this->buildSignalMessage($dom, $messageId, $refToMessageId, $timestamp));
        $header->appendChild($messaging);

        return $header;
    }

    private function buildSignalMessage(
        DOMDocument $dom,
        string $messageId,
        string $refToMessageId,
        CarbonImmutable $timestamp,
    ): DOMElement {
        $signal = $dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:SignalMessage');

        $info = $dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:MessageInfo');
        $info->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:Timestamp', $timestamp->toIso8601ZuluString()));
        $info->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:MessageId', $messageId));
        $info->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:RefToMessageId', $refToMessageId));
        $signal->appendChild($info);

        $signal->appendChild($dom->createElementNS(As4EnvelopeBuilder::NS_EB, 'eb:Receipt'));

        return $signal;
    }
}
