<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SignatureVerificationException;
use App\Pki\TrustStore;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;

/**
 * Verifies an XML-DSig / XAdES signature for real: it authenticates SignedInfo
 * against the embedded certificate, recomputes every reference digest to catch
 * tampering, and checks the certificate chains to a trusted CA and is in date.
 *
 * Verification is done by hand rather than via xmlseclibs' validateReference():
 * that method detaches the signature before processing references, which makes
 * the in-signature XAdES SignedProperties reference unresolvable. Doing it
 * directly keeps full control over which signature is checked and how each
 * reference is canonicalised.
 */
final readonly class SignatureVerifier
{
    private const string NS_DS = XMLSecurityDSig::XMLDSIGNS;

    private const string NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

    private const string TRANSFORM_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public function __construct(private TrustStore $trustStore) {}

    /**
     * @param  string|null  $signatureXPath  Selects the signature to verify; defaults to the first ds:Signature.
     *
     * @throws SignatureVerificationException
     */
    public function verify(DOMDocument $document, ?string $signatureXPath = null): void
    {
        // The document must be loaded verbatim (DOMDocument::loadXML, the default
        // preserveWhiteSpace=true): the signature commits to the exact serialised
        // bytes, including the whitespace xmlseclibs bakes into SignedInfo.
        $xpath = $this->xpath($document);
        $signature = $this->locateSignature($xpath, $signatureXPath);

        $certificatePem = $this->extractCertificate($xpath, $signature);

        $this->verifySignedInfo($xpath, $signature, $certificatePem);
        $this->verifyReferences($document, $xpath, $signature);

        if (! $this->trustStore->isIssuedByTrustedCa($certificatePem)) {
            throw SignatureVerificationException::untrusted();
        }

        if (! $this->trustStore->isWithinValidityWindow($certificatePem, now()->getTimestamp())) {
            throw SignatureVerificationException::expired();
        }
    }

    private function locateSignature(DOMXPath $xpath, ?string $signatureXPath): DOMElement
    {
        $node = $this->firstNode($xpath, $signatureXPath ?? '//ds:Signature');

        if (! $node instanceof DOMElement) {
            throw SignatureVerificationException::missingSignature();
        }

        return $node;
    }

    private function extractCertificate(DOMXPath $xpath, DOMElement $signature): string
    {
        $node = $this->firstNode($xpath, './/ds:KeyInfo/ds:X509Data/ds:X509Certificate', $signature);

        if (! $node instanceof DOMNode) {
            throw SignatureVerificationException::missingCertificate();
        }

        $body = (string) preg_replace('/\s+/', '', $node->textContent);

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n").'-----END CERTIFICATE-----'."\n";
    }

    /**
     * Authenticate SignedInfo: canonicalise it with its declared method and
     * verify the SignatureValue with the certificate's public key.
     */
    private function verifySignedInfo(DOMXPath $xpath, DOMElement $signature, string $certificatePem): void
    {
        $signedInfo = $this->firstNode($xpath, './ds:SignedInfo', $signature);
        $signatureValue = $this->firstNode($xpath, './ds:SignatureValue', $signature);

        if (! $signedInfo instanceof DOMElement || ! $signatureValue instanceof DOMNode) {
            throw SignatureVerificationException::invalidSignature();
        }

        $methodNode = $this->firstNode($xpath, './ds:CanonicalizationMethod', $signedInfo);
        $method = $methodNode instanceof DOMElement ? $methodNode->getAttribute('Algorithm') : '';
        $canonical = (string) $signedInfo->C14N($this->isExclusive($method), false);

        $publicKey = openssl_pkey_get_public($certificatePem);
        if ($publicKey === false) {
            throw SignatureVerificationException::missingCertificate();
        }

        $verified = openssl_verify(
            $canonical,
            (string) base64_decode((string) preg_replace('/\s+/', '', $signatureValue->textContent), true),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw SignatureVerificationException::invalidSignature();
        }
    }

    /**
     * Recompute each reference's digest from the live document and compare with
     * the value the signature commits to. Any mismatch means the signed content
     * was altered after signing.
     */
    private function verifyReferences(DOMDocument $document, DOMXPath $xpath, DOMElement $signature): void
    {
        $signatureId = $signature->getAttribute('Id');

        foreach ($xpath->query('./ds:SignedInfo/ds:Reference', $signature) as $reference) {
            if (! $reference instanceof DOMElement) {
                continue;
            }

            $uri = $reference->getAttribute('URI');
            $digestNode = $this->firstNode($xpath, './ds:DigestValue', $reference);
            $embedded = $digestNode instanceof DOMNode ? trim($digestNode->textContent) : '';

            $transforms = [];
            foreach ($xpath->query('./ds:Transforms/ds:Transform', $reference) as $transform) {
                if ($transform instanceof DOMElement) {
                    $transforms[] = $transform->getAttribute('Algorithm');
                }
            }

            $calculated = $this->digestReferencedNode($document, $signatureId, $uri, $transforms);

            if (! hash_equals($embedded, $calculated)) {
                throw SignatureVerificationException::digestMismatch($uri);
            }
        }
    }

    /**
     * @param  list<string>  $transforms
     */
    private function digestReferencedNode(DOMDocument $document, string $signatureId, string $uri, array $transforms): string
    {
        $enveloped = in_array(self::TRANSFORM_ENVELOPED, $transforms, true);
        $exclusive = $this->isExclusive(...$this->canonicalTransforms($transforms));

        // Operate on a detached copy so removing the enveloped signature never
        // mutates the document under verification.
        $work = new DOMDocument;
        $work->loadXML((string) $document->saveXML());
        $workXpath = $this->xpath($work);

        if ($enveloped) {
            $sig = $this->findSignatureCopy($workXpath, $signatureId);
            $sig?->parentNode?->removeChild($sig);
        }

        $identifier = ltrim($uri, '#');
        $target = $uri === ''
            ? $work->documentElement
            : $this->firstNode($workXpath, '//*[@Id='.$this->xpathLiteral($identifier).' or @wsu:Id='.$this->xpathLiteral($identifier).']');

        if (! $target instanceof DOMElement) {
            throw SignatureVerificationException::digestMismatch($uri);
        }

        return base64_encode(hash('sha256', (string) $target->C14N($exclusive, false), binary: true));
    }

    private function findSignatureCopy(DOMXPath $xpath, string $signatureId): ?DOMElement
    {
        $query = $signatureId === ''
            ? '//ds:Signature'
            : '//ds:Signature[@Id='.$this->xpathLiteral($signatureId).']';

        $node = $this->firstNode($xpath, $query);

        return $node instanceof DOMElement ? $node : null;
    }

    private function firstNode(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?DOMNode
    {
        $result = $xpath->query($query, $context);

        return $result === false ? null : $result->item(0);
    }

    /**
     * @param  list<string>  $transforms
     * @return list<string>
     */
    private function canonicalTransforms(array $transforms): array
    {
        return array_values(array_filter($transforms, static fn (string $t): bool => str_contains($t, 'c14n')));
    }

    private function isExclusive(string ...$methods): bool
    {
        return array_any($methods, fn ($method): bool => str_starts_with((string) $method, 'http://www.w3.org/2001/10/xml-exc-c14n#'));
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::NS_DS);
        $xpath->registerNamespace('wsu', self::NS_WSU);

        return $xpath;
    }

    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        return "concat('".str_replace("'", "',\"'\",'", $value)."')";
    }
}
