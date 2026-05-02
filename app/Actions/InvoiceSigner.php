<?php

namespace App\Actions;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;

class InvoiceSigner
{
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';

    private const TRANSFORM_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const STUB_SIGNATURE_VALUE = 'STUB-PHASE3';

    private const STUB_CERT_SERIAL = '00000000000000000001';

    private const STUB_CERT_ISSUER = 'CN=eRakun Phase 3 Stub Issuer, O=eRakun, C=HR';

    public function execute(DOMDocument $invoice): DOMDocument
    {
        $signed = clone $invoice;

        $info = $this->findSignatureInformation($signed);
        $digest = $this->computeDigest($signed);
        $signature = $this->buildSignature($signed, $digest);

        $info->appendChild($signature);

        return $signed;
    }

    private function findSignatureInformation(DOMDocument $dom): DOMElement
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', self::NS_EXT);
        $xpath->registerNamespace('sac', self::NS_SAC);

        $nodes = $xpath->query('//ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/*/sac:SignatureInformation');
        $node = $nodes === false ? null : $nodes->item(0);

        if (! $node instanceof DOMElement) {
            throw new \RuntimeException('Invoice is missing the sac:SignatureInformation placeholder.');
        }

        return $node;
    }

    private function computeDigest(DOMDocument $dom): string
    {
        $canonical = $dom->documentElement?->C14N(exclusive: false, withComments: false);

        if ($canonical === null || $canonical === false) {
            throw new \RuntimeException('Failed to canonicalize invoice for digest.');
        }

        return base64_encode(hash('sha256', $canonical, binary: true));
    }

    private function buildSignature(DOMDocument $dom, string $digestValue): DOMElement
    {
        $signature = $dom->createElementNS(self::NS_DS, 'ds:Signature');
        $signature->setAttribute('Id', 'erakun-stub-signature');

        $signedInfo = $dom->createElementNS(self::NS_DS, 'ds:SignedInfo');
        $this->appendDs($dom, $signedInfo, 'CanonicalizationMethod', ['Algorithm' => XMLSecurityDSig::C14N]);
        $this->appendDs($dom, $signedInfo, 'SignatureMethod', ['Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']);

        $reference = $dom->createElementNS(self::NS_DS, 'ds:Reference');
        $reference->setAttribute('URI', '');

        $transforms = $dom->createElementNS(self::NS_DS, 'ds:Transforms');
        $this->appendDs($dom, $transforms, 'Transform', ['Algorithm' => self::TRANSFORM_ENVELOPED]);
        $this->appendDs($dom, $transforms, 'Transform', ['Algorithm' => XMLSecurityDSig::C14N]);
        $reference->appendChild($transforms);

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
