<?php

namespace App\Validation;

use Illuminate\Support\Facades\Process;

class UblValidator
{
    private const SVRL_NS = 'http://purl.oclc.org/dsdl/svrl';

    public function __construct(
        private readonly string $schemasPath,
    ) {}

    public function validate(string $xml): ValidationReport
    {
        $issues = $this->runXsd($xml);

        $tmpXml = tempnam(sys_get_temp_dir(), 'erakun-ubl-').'.xml';
        file_put_contents($tmpXml, $xml);

        try {
            $issues = array_merge(
                $issues,
                $this->runSchematron($tmpXml, 'en16931'),
                $this->runSchematron($tmpXml, 'hr-cius'),
            );
        } finally {
            @unlink($tmpXml);
        }

        return new ValidationReport($issues);
    }

    /**
     * @return list<ValidationIssue>
     */
    private function runXsd(string $xml): array
    {
        $xsd = $this->schemasPath.'/ubl/maindoc/UBL-Invoice-2.1.xsd';

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument;
        $loaded = $dom->loadXML($xml, LIBXML_NONET);

        if (! $loaded) {
            $issues = $this->collectLibxmlErrors();
            libxml_use_internal_errors($previous);

            return $issues;
        }

        $dom->schemaValidate($xsd);
        $issues = $this->collectLibxmlErrors();
        libxml_use_internal_errors($previous);

        return $issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function collectLibxmlErrors(): array
    {
        $issues = [];
        foreach (libxml_get_errors() as $err) {
            $severity = match ($err->level) {
                LIBXML_ERR_WARNING => 'warning',
                default => 'error',
            };
            $issues[] = new ValidationIssue(
                source: 'xsd',
                rule: 'XSD-'.$err->code,
                severity: $severity,
                message: trim($err->message),
                location: 'line '.$err->line,
            );
        }
        libxml_clear_errors();

        return $issues;
    }

    /**
     * @return list<ValidationIssue>
     */
    private function runSchematron(string $xmlPath, string $name): array
    {
        $xsl = $this->schemasPath.'/compiled/'.$name.'.xsl';
        if (! is_file($xsl)) {
            throw new \RuntimeException("Compiled validator missing: $xsl. Run: php artisan schema:compile");
        }

        $svrlPath = tempnam(sys_get_temp_dir(), 'erakun-svrl-').'.xml';

        try {
            $result = Process::run([
                'java', '-cp', $this->saxonClasspath(), 'net.sf.saxon.Transform',
                '-s:'.$xmlPath, '-xsl:'.$xsl, '-o:'.$svrlPath,
            ]);

            if (! $result->successful()) {
                throw new \RuntimeException("Saxon failed validating against $name.xsl: ".($result->errorOutput() ?: $result->output()));
            }

            return $this->parseSvrl(file_get_contents($svrlPath) ?: '', 'schematron');
        } finally {
            @unlink($svrlPath);
        }
    }

    /**
     * @return list<ValidationIssue>
     */
    private function parseSvrl(string $svrl, string $source): array
    {
        if ($svrl === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadXML($svrl, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $issues = [];
        $asserts = $dom->getElementsByTagNameNS(self::SVRL_NS, 'failed-assert');

        /** @var \DOMElement $node */
        foreach ($asserts as $node) {
            $rule = $node->getAttribute('id') ?: $node->getAttribute('test');
            $location = $node->getAttribute('location') ?: null;
            $flag = strtolower($node->getAttribute('flag') ?: 'fatal');
            $severity = $flag === 'warning' ? 'warning' : 'error';

            $textNodes = $node->getElementsByTagNameNS(self::SVRL_NS, 'text');
            $message = $textNodes->length > 0
                ? trim((string) $textNodes->item(0)?->textContent)
                : '';

            $issues[] = new ValidationIssue(
                source: $source,
                rule: $rule !== '' ? $rule : 'unknown',
                severity: $severity,
                message: $message,
                location: $location,
            );
        }

        return $issues;
    }

    private function saxonClasspath(): string
    {
        $saxon = $this->schemasPath.'/saxon';

        return implode(':', [
            $saxon.'/Saxon-HE-12.5.jar',
            $saxon.'/xmlresolver-5.2.2.jar',
            $saxon.'/xmlresolver-5.2.2-data.jar',
        ]);
    }
}
