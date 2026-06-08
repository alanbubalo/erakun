<?php

declare(strict_types=1);

namespace App\As4;

use App\Pki\AccessPointCredential;
use DOMDocument;
use DOMElement;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * Signs an AS4 envelope at the transport layer with the access point's own
 * certificate, the way WS-Security does: a real RSA-SHA256 ds:Signature inside a
 * wsse:Security header, referencing the payload by its wsu:Id over exclusive C14N.
 *
 * This is distinct from the supplier's XAdES signature enveloped inside the UBL
 * payload (see {@see InvoiceSigner}). Keeping the two layers
 * separate mirrors the PEPPOL split between the AP (OpenPEPPOL-issued) and the
 * party (FINA-issued) certificates.
 */
final readonly class As4EnvelopeSigner
{
    public const string NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    public function __construct(private AccessPointCredential $credential) {}

    public function execute(DOMDocument $envelope): DOMDocument
    {
        // Emit verbatim: pretty-printing would inject whitespace the digests —
        // both this transport signature and the enveloped UBL one inside the
        // payload — never saw, breaking verification on the receive side.
        $envelope->formatOutput = false;

        $header = $envelope->getElementsByTagNameNS(As4EnvelopeBuilder::NS_SOAP, 'Header')->item(0);
        throw_unless($header instanceof DOMElement, RuntimeException::class, 'AS4 envelope is missing a soap:Header to sign.');

        $payload = $envelope->getElementsByTagNameNS(As4EnvelopeBuilder::NS_AS4, 'UblPayload')->item(0);
        throw_unless($payload instanceof DOMElement, RuntimeException::class, 'AS4 envelope is missing an as4:UblPayload to sign.');

        $credential = $this->credential->resolve();

        $dsig = new XMLSecurityDSig;
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReference(
            $payload,
            XMLSecurityDSig::SHA256,
            [XMLSecurityDSig::EXC_C14N],
            ['overwrite' => false, 'id_name' => 'Id', 'prefix' => 'wsu', 'prefix_ns' => As4EnvelopeBuilder::NS_WSU],
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($credential->privateKeyPem, false);
        $dsig->sign($key);
        $dsig->add509Cert($credential->certificatePem, true);

        $security = $envelope->createElementNS(self::NS_WSSE, 'wsse:Security');
        $header->appendChild($security);
        $dsig->appendSignature($security);

        return $envelope;
    }
}
