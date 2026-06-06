<?php

declare(strict_types=1);

namespace App\As4;

use App\Actions\InvoiceSigner;
use DOMDocument;
use DOMElement;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RuntimeException;

/**
 * Adds the sending access point's transport-level signature to an AS4 envelope.
 *
 * This is distinct from the invoice signature: the supplier's qualified
 * signature is enveloped inside the UBL payload (see {@see InvoiceSigner}),
 * while this signs the *message* by placing a <ds:Signature> in the SOAP header,
 * the way WS-Security does. Keeping the two separate prevents the delivery step
 * from re-signing an already-signed UBL (which produced a second <ds:Signature>
 * inside sac:SignatureInformation and failed the receiver's schema validation).
 *
 * Crypto-light, like the rest of the mock: the digest is real (C14N + SHA-256
 * over the payload) but the SignatureValue and certificate are stubs.
 */
final class As4EnvelopeSigner
{
    private const string NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const string STUB_SIGNATURE_VALUE = 'STUB-AS4';

    private const string STUB_CERT_SERIAL = '00000000000000000002';

    private const string STUB_CERT_ISSUER = 'CN=eRakun AS4 Stub Access Point, O=eRakun, C=HR';

    public function execute(DOMDocument $envelope): DOMDocument
    {
        $header = $envelope->getElementsByTagNameNS(As4EnvelopeBuilder::NS_SOAP, 'Header')->item(0);

        throw_unless($header instanceof DOMElement, RuntimeException::class, 'AS4 envelope is missing a soap:Header to sign.');

        $digest = $this->digestPayload($envelope);
        $header->appendChild($this->buildSignature($envelope, $digest));

        return $envelope;
    }

    private function digestPayload(DOMDocument $envelope): string
    {
        $payload = $envelope->getElementsByTagNameNS(As4EnvelopeBuilder::NS_AS4, 'UblPayload')->item(0);
        $node = $payload instanceof DOMElement ? $payload : $envelope->documentElement;

        $canonical = $node?->C14N(exclusive: false, withComments: false);

        throw_if($canonical === null || $canonical === false, RuntimeException::class, 'Failed to canonicalize AS4 payload for digest.');

        return base64_encode(hash('sha256', $canonical, binary: true));
    }

    private function buildSignature(DOMDocument $dom, string $digestValue): DOMElement
    {
        $signature = $dom->createElementNS(self::NS_DS, 'ds:Signature');
        $signature->setAttribute('Id', 'erakun-as4-signature');

        $signedInfo = $dom->createElementNS(self::NS_DS, 'ds:SignedInfo');
        $this->appendDs($dom, $signedInfo, 'CanonicalizationMethod', ['Algorithm' => XMLSecurityDSig::C14N]);
        $this->appendDs($dom, $signedInfo, 'SignatureMethod', ['Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']);

        $reference = $dom->createElementNS(self::NS_DS, 'ds:Reference');
        $reference->setAttribute('URI', '#'.As4EnvelopeBuilder::PAYLOAD_ID);
        $this->appendDs($dom, $reference, 'DigestMethod', ['Algorithm' => XMLSecurityDSig::SHA256]);
        $this->appendDs($dom, $reference, 'DigestValue', [], $digestValue);
        $signedInfo->appendChild($reference);

        $signature->appendChild($signedInfo);

        $this->appendDs($dom, $signature, 'SignatureValue', [], base64_encode(self::STUB_SIGNATURE_VALUE));

        $keyInfo = $dom->createElementNS(self::NS_DS, 'ds:KeyInfo');
        $x509Data = $dom->createElementNS(self::NS_DS, 'ds:X509Data');
        $serial = $dom->createElementNS(self::NS_DS, 'ds:X509IssuerSerial');
        $this->appendDs($dom, $serial, 'X509IssuerName', [], self::STUB_CERT_ISSUER);
        $this->appendDs($dom, $serial, 'X509SerialNumber', [], self::STUB_CERT_SERIAL);
        $x509Data->appendChild($serial);
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        return $signature;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function appendDs(DOMDocument $dom, DOMElement $parent, string $name, array $attributes = [], string $value = ''): DOMElement
    {
        $el = $value === ''
            ? $dom->createElementNS(self::NS_DS, 'ds:'.$name)
            : $dom->createElementNS(self::NS_DS, 'ds:'.$name, $value);

        foreach ($attributes as $attr => $val) {
            $el->setAttribute($attr, $val);
        }

        $parent->appendChild($el);

        return $el;
    }
}
