<?php

declare(strict_types=1);

namespace App\As4;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use RuntimeException;

class As4EnvelopeBuilder
{
    public const string NS_SOAP = 'http://www.w3.org/2003/05/soap-envelope';

    public const string NS_EB = 'http://docs.oasis-open.org/ebxml-msg/ebms/v3.0/ns/core/200704/';

    public const string NS_AS4 = 'urn:hr:erakun:as4:1.0';

    public const string SERVICE = 'urn:hr:erakun:eRacun';

    public const string ACTION = 'UblInvoice';

    public const string PARTY_ID_TYPE = 'iso6523:9934';

    public const string PAYLOAD_ID = 'ubl';

    public function build(
        string $ublXml,
        string $messageId,
        string $senderOib,
        string $recipientOib,
    ): DOMDocument {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $envelope = $dom->createElementNS(self::NS_SOAP, 'soap:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:eb', self::NS_EB);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:as4', self::NS_AS4);
        $dom->appendChild($envelope);

        $envelope->appendChild($this->buildHeader($dom, $messageId, $senderOib, $recipientOib));
        $envelope->appendChild($this->buildBody($dom, $ublXml));

        return $dom;
    }

    private function buildHeader(
        DOMDocument $dom,
        string $messageId,
        string $senderOib,
        string $recipientOib,
    ): DOMElement {
        $header = $dom->createElementNS(self::NS_SOAP, 'soap:Header');

        $messaging = $dom->createElementNS(self::NS_EB, 'eb:Messaging');
        $messaging->appendChild($this->buildUserMessage($dom, $messageId, $senderOib, $recipientOib));
        $header->appendChild($messaging);

        return $header;
    }

    private function buildUserMessage(
        DOMDocument $dom,
        string $messageId,
        string $senderOib,
        string $recipientOib,
    ): DOMElement {
        $userMessage = $dom->createElementNS(self::NS_EB, 'eb:UserMessage');

        $userMessage->appendChild($this->buildMessageInfo($dom, $messageId));
        $userMessage->appendChild($this->buildPartyInfo($dom, $senderOib, $recipientOib));
        $userMessage->appendChild($this->buildCollaborationInfo($dom));
        $userMessage->appendChild($this->buildPayloadInfo($dom));

        return $userMessage;
    }

    private function buildMessageInfo(DOMDocument $dom, string $messageId): DOMElement
    {
        $info = $dom->createElementNS(self::NS_EB, 'eb:MessageInfo');

        $timestamp = CarbonImmutable::now()->toIso8601ZuluString();
        $info->appendChild($dom->createElementNS(self::NS_EB, 'eb:Timestamp', $timestamp));
        $info->appendChild($dom->createElementNS(self::NS_EB, 'eb:MessageId', $messageId));

        return $info;
    }

    private function buildPartyInfo(DOMDocument $dom, string $senderOib, string $recipientOib): DOMElement
    {
        $partyInfo = $dom->createElementNS(self::NS_EB, 'eb:PartyInfo');
        $partyInfo->appendChild($this->buildParty($dom, 'From', $senderOib));
        $partyInfo->appendChild($this->buildParty($dom, 'To', $recipientOib));

        return $partyInfo;
    }

    private function buildParty(DOMDocument $dom, string $localName, string $oib): DOMElement
    {
        $party = $dom->createElementNS(self::NS_EB, 'eb:'.$localName);
        $partyId = $dom->createElementNS(self::NS_EB, 'eb:PartyId', $oib);
        $partyId->setAttribute('type', self::PARTY_ID_TYPE);
        $party->appendChild($partyId);

        return $party;
    }

    private function buildCollaborationInfo(DOMDocument $dom): DOMElement
    {
        $collab = $dom->createElementNS(self::NS_EB, 'eb:CollaborationInfo');
        $collab->appendChild($dom->createElementNS(self::NS_EB, 'eb:Service', self::SERVICE));
        $collab->appendChild($dom->createElementNS(self::NS_EB, 'eb:Action', self::ACTION));

        return $collab;
    }

    private function buildPayloadInfo(DOMDocument $dom): DOMElement
    {
        $payloadInfo = $dom->createElementNS(self::NS_EB, 'eb:PayloadInfo');
        $partInfo = $dom->createElementNS(self::NS_EB, 'eb:PartInfo');
        $partInfo->setAttribute('href', '#'.self::PAYLOAD_ID);
        $payloadInfo->appendChild($partInfo);

        return $payloadInfo;
    }

    private function buildBody(DOMDocument $dom, string $ublXml): DOMElement
    {
        $body = $dom->createElementNS(self::NS_SOAP, 'soap:Body');

        $wrapper = $dom->createElementNS(self::NS_AS4, 'as4:UblPayload');
        $wrapper->setAttribute('id', self::PAYLOAD_ID);
        $wrapper->appendChild($this->importUbl($dom, $ublXml));
        $body->appendChild($wrapper);

        return $body;
    }

    private function importUbl(DOMDocument $dom, string $ublXml): DOMElement
    {
        $source = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $source->loadXML($ublXml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        throw_if($loaded === false || ! $source->documentElement instanceof DOMElement, RuntimeException::class, 'As4EnvelopeBuilder received malformed UBL payload XML.');

        $imported = $dom->importNode($source->documentElement, true);

        throw_unless($imported instanceof DOMElement, RuntimeException::class, 'Failed to import UBL payload into AS4 envelope.');

        return $imported;
    }
}
