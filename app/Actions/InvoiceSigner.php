<?php

declare(strict_types=1);

namespace App\Actions;

use App\Pki\CertificateMetadata;
use App\Pki\SigningCredential;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * Signs a UBL document with the supplier's signing key, producing a real
 * XAdES-B enveloped signature inside the document's sac:SignatureInformation.
 *
 * The signature carries two references: one over the whole document (enveloped
 * transform), one over the XAdES SignedProperties (SigningTime + a digest of the
 * signing certificate). xmlseclibs builds neither XAdES nor the second reference
 * on its own, so SignedProperties is hand-built and added as a ds:Object before
 * signing. RSA-SHA256 over exclusive-C14N SignedInfo; the certificate itself is
 * embedded in KeyInfo so a verifier can rebuild trust without a side channel.
 */
final class InvoiceSigner
{
    private const string NS_DS = XMLSecurityDSig::XMLDSIGNS;

    private const string NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const string NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';

    private const string NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    private const string TRANSFORM_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const string SIGNED_PROPS_TYPE = 'http://uri.etsi.org/01903#SignedProperties';

    private const string SIGNATURE_ID = 'erakun-signature';

    public function execute(DOMDocument $invoice, SigningCredential $credential): DOMDocument
    {
        $signed = clone $invoice;
        // The signature is computed over the in-memory DOM; pretty-printing on
        // serialisation would inject whitespace the digests never saw, breaking
        // verification. Emit the signed document verbatim.
        $signed->formatOutput = false;
        $host = $this->resolveSignatureHost($signed);

        $dsig = new XMLSecurityDSig;
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->sigNode->setAttribute('Id', self::SIGNATURE_ID);

        $signedPropsId = self::SIGNATURE_ID.'-signedprops';
        $objectNode = $dsig->addObject($this->buildQualifyingProperties($signed, $credential->certificatePem, $signedPropsId));
        $signedProps = $objectNode->getElementsByTagNameNS(self::NS_XADES, 'SignedProperties')->item(0);
        throw_unless($signedProps instanceof DOMElement, RuntimeException::class, 'Failed to build XAdES SignedProperties.');

        // Reference 1 — the enveloped document (URI=""), signature stripped before digest.
        $dsig->addReference(
            $signed,
            XMLSecurityDSig::SHA256,
            [self::TRANSFORM_ENVELOPED, XMLSecurityDSig::EXC_C14N],
            ['force_uri' => true],
        );

        // Reference 2 — the XAdES SignedProperties, bound by its Id.
        $dsig->addReference(
            $signedProps,
            XMLSecurityDSig::SHA256,
            [XMLSecurityDSig::EXC_C14N],
            ['overwrite' => false],
        );
        $this->markSignedPropertiesReference($dsig, '#'.$signedPropsId);

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($credential->privateKeyPem, false);
        $dsig->sign($key);
        $dsig->add509Cert($credential->certificatePem, true);

        $dsig->appendSignature($host);

        return $signed;
    }

    private function resolveSignatureHost(DOMDocument $dom): DOMElement
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', self::NS_EXT);
        $xpath->registerNamespace('sac', self::NS_SAC);

        $nodes = $xpath->query('//ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/*/sac:SignatureInformation');
        $node = $nodes === false ? null : $nodes->item(0);

        if ($node instanceof DOMElement) {
            return $node;
        }

        throw_unless($dom->documentElement instanceof DOMElement, RuntimeException::class, 'Cannot sign a document without a root element.');

        return $dom->documentElement;
    }

    /**
     * The minimal XAdES-B signed properties: when the document was signed and a
     * digest + issuer/serial of the signing certificate, binding the signature
     * to a specific cert.
     */
    private function buildQualifyingProperties(DOMDocument $dom, string $certificatePem, string $signedPropsId): DOMElement
    {
        $meta = CertificateMetadata::fromPem($certificatePem);
        $parsed = openssl_x509_parse($certificatePem) ?: [];

        $qp = $dom->createElementNS(self::NS_XADES, 'xades:QualifyingProperties');
        $qp->setAttribute('Target', '#'.self::SIGNATURE_ID);
        $qp->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', self::NS_DS);

        $signedProps = $dom->createElementNS(self::NS_XADES, 'xades:SignedProperties');
        $signedProps->setAttribute('Id', $signedPropsId);

        $sigProps = $dom->createElementNS(self::NS_XADES, 'xades:SignedSignatureProperties');
        $sigProps->appendChild($dom->createElementNS(self::NS_XADES, 'xades:SigningTime', CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z')));

        $signingCert = $dom->createElementNS(self::NS_XADES, 'xades:SigningCertificate');
        $cert = $dom->createElementNS(self::NS_XADES, 'xades:Cert');

        $certDigest = $dom->createElementNS(self::NS_XADES, 'xades:CertDigest');
        $digestMethod = $dom->createElementNS(self::NS_DS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', XMLSecurityDSig::SHA256);
        $certDigest->appendChild($digestMethod);
        $certDigest->appendChild($dom->createElementNS(self::NS_DS, 'ds:DigestValue', base64_encode(hash('sha256', $this->pemToDer($certificatePem), binary: true))));

        $issuerSerial = $dom->createElementNS(self::NS_XADES, 'xades:IssuerSerial');
        $issuerSerial->appendChild($dom->createElementNS(self::NS_DS, 'ds:X509IssuerName', $meta->issuer));
        $issuerSerial->appendChild($dom->createElementNS(self::NS_DS, 'ds:X509SerialNumber', (string) ($parsed['serialNumber'] ?? '')));

        $cert->appendChild($certDigest);
        $cert->appendChild($issuerSerial);
        $signingCert->appendChild($cert);

        $sigProps->appendChild($signingCert);
        $signedProps->appendChild($sigProps);
        $qp->appendChild($signedProps);

        return $qp;
    }

    private function markSignedPropertiesReference(XMLSecurityDSig $dsig, string $uri): void
    {
        foreach ($dsig->sigNode->getElementsByTagNameNS(self::NS_DS, 'Reference') as $reference) {
            if ($reference->getAttribute('URI') === $uri) {
                $reference->setAttribute('Type', self::SIGNED_PROPS_TYPE);

                return;
            }
        }
    }

    private function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s/', '', $pem);

        return (string) base64_decode((string) $body, true);
    }
}
