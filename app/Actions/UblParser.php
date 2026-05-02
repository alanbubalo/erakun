<?php

namespace App\Actions;

use App\Enums\VatCategory;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class UblParser
{
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function parse(string $xml): ParsedInvoice
    {
        $dom = new DOMDocument;
        throw_unless($dom->loadXML($xml, LIBXML_NONET), RuntimeException::class, 'Failed to load UBL invoice XML.');

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('inv', self::NS_INVOICE);
        $xpath->registerNamespace('cac', self::NS_CAC);
        $xpath->registerNamespace('cbc', self::NS_CBC);

        $root = $dom->documentElement;
        throw_unless($root instanceof DOMElement, RuntimeException::class, 'UBL invoice has no document element.');

        $supplierParty = $this->requireElement($xpath, $root, 'cac:AccountingSupplierParty/cac:Party');
        $buyerParty = $this->requireElement($xpath, $root, 'cac:AccountingCustomerParty/cac:Party');

        $iban = $this->text($xpath, $root, 'cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID') ?: null;

        $supplier = $this->buildParty($xpath, $supplierParty, $iban);
        $buyer = $this->buildParty($xpath, $buyerParty, null);

        $lines = [];
        $lineNodes = $xpath->query('cac:InvoiceLine', $root);
        if ($lineNodes !== false) {
            foreach ($lineNodes as $node) {
                if ($node instanceof DOMElement) {
                    $lines[] = $this->buildLine($xpath, $node);
                }
            }
        }

        return new ParsedInvoice(
            supplier: $supplier,
            buyer: $buyer,
            invoiceNumber: $this->requireText($xpath, $root, 'cbc:ID'),
            issueDate: $this->requireText($xpath, $root, 'cbc:IssueDate'),
            dueDate: $this->text($xpath, $root, 'cbc:DueDate') ?: null,
            currency: $this->requireText($xpath, $root, 'cbc:DocumentCurrencyCode'),
            netAmount: $this->money($this->requireText($xpath, $root, 'cac:LegalMonetaryTotal/cbc:LineExtensionAmount')),
            taxAmount: $this->money($this->requireText($xpath, $root, 'cac:TaxTotal/cbc:TaxAmount')),
            totalAmount: $this->money($this->requireText($xpath, $root, 'cac:LegalMonetaryTotal/cbc:PayableAmount')),
            lines: $lines,
        );
    }

    private function buildParty(DOMXPath $xpath, DOMElement $party, ?string $iban): ParsedParty
    {
        $oib = $this->requireText($xpath, $party, 'cbc:EndpointID');
        $name = $this->requireText($xpath, $party, 'cac:PartyLegalEntity/cbc:RegistrationName');
        $addressLine = $this->requireText($xpath, $party, 'cac:PostalAddress/cbc:StreetName');
        $city = $this->requireText($xpath, $party, 'cac:PostalAddress/cbc:CityName');
        $postcode = $this->requireText($xpath, $party, 'cac:PostalAddress/cbc:PostalZone');
        $countryCode = $this->requireText($xpath, $party, 'cac:PostalAddress/cac:Country/cbc:IdentificationCode');

        $taxScheme = $xpath->query('cac:PartyTaxScheme', $party);
        $isVatRegistered = $taxScheme !== false && $taxScheme->length > 0;

        return new ParsedParty(
            oib: $oib,
            name: $name,
            addressLine: $addressLine,
            city: $city,
            postcode: $postcode,
            countryCode: $countryCode,
            isVatRegistered: $isVatRegistered,
            iban: $iban,
        );
    }

    private function buildLine(DOMXPath $xpath, DOMElement $line): ParsedInvoiceLine
    {
        $quantityNode = $this->requireElement($xpath, $line, 'cbc:InvoicedQuantity');
        $unitCode = $quantityNode->getAttribute('unitCode') ?: 'H87';

        $vatCategoryCode = $this->requireText($xpath, $line, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID');
        $vatCategory = VatCategory::from($vatCategoryCode);

        $kpdCode = $this->parseKpd(
            $this->text($xpath, $line, 'cac:Item/cac:CommodityClassification[cbc:ItemClassificationCode/@listID="CG"]/cbc:ItemClassificationCode')
        );

        return new ParsedInvoiceLine(
            description: $this->requireText($xpath, $line, 'cac:Item/cbc:Name'),
            quantity: $this->quantity($quantityNode->textContent),
            unitPrice: $this->money($this->requireText($xpath, $line, 'cac:Price/cbc:PriceAmount')),
            lineTotal: $this->money($this->requireText($xpath, $line, 'cbc:LineExtensionAmount')),
            vatRate: $this->percent($this->requireText($xpath, $line, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent')),
            vatCategory: $vatCategory,
            unitCode: $unitCode,
            kpdCode: $kpdCode,
        );
    }

    private function requireElement(DOMXPath $xpath, DOMNode $context, string $expression): DOMElement
    {
        $nodes = $xpath->query($expression, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        throw_unless($node instanceof DOMElement, RuntimeException::class, "Missing required UBL element: $expression");

        return $node;
    }

    private function requireText(DOMXPath $xpath, DOMNode $context, string $expression): string
    {
        $value = $this->text($xpath, $context, $expression);

        throw_if($value === '', RuntimeException::class, "Missing required UBL text: $expression");

        return $value;
    }

    private function text(DOMXPath $xpath, DOMNode $context, string $expression): string
    {
        $nodes = $xpath->query($expression, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node === null ? '' : trim((string) $node->textContent);
    }

    private function money(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }

    private function quantity(string $amount): string
    {
        return bcadd(trim($amount), '0', 3);
    }

    private function percent(string $amount): string
    {
        return bcadd(trim($amount), '0', 2);
    }

    private function parseKpd(string $code): ?string
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $stripped = str_replace('.', '', $code);

        return preg_match('/^\d{6}$/', $stripped) === 1 ? $stripped : $code;
    }
}
