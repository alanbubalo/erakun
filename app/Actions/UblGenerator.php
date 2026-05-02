<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Taxpayer;
use DOMDocument;
use DOMElement;

class UblGenerator
{
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';

    private const NS_SIG = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    private const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:mfin.gov.hr:cius-2025:1.0#conformant#urn:mfin.gov.hr:ext-2025:1.0';

    private const PROFILE_ID = 'P1';

    private const INVOICE_TYPE_CODE = '380';

    private const PAYMENT_MEANS_CODE = '30';

    private const ISSUE_TIME = '12:00:00';

    public function execute(Invoice $invoice): DOMDocument
    {
        $invoice->loadMissing('supplier', 'buyer', 'lines');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;

        $root = $dom->createElementNS(self::NS_INVOICE, 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::NS_EXT);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', self::NS_SAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sig', self::NS_SIG);
        $dom->appendChild($root);

        $this->appendUblExtensionsPlaceholder($dom, $root);
        $this->appendHeader($dom, $root, $invoice);
        $this->appendSupplierParty($dom, $root, $invoice);
        $this->appendCustomerParty($dom, $root, $invoice);
        $this->appendPaymentMeans($dom, $root, $invoice);
        $this->appendTaxTotal($dom, $root, $invoice);
        $this->appendLegalMonetaryTotal($dom, $root, $invoice);
        $this->appendInvoiceLines($dom, $root, $invoice);

        return $this->stripDuplicateNamespaces($dom);
    }

    private function stripDuplicateNamespaces(DOMDocument $dom): DOMDocument
    {
        $clean = new DOMDocument('1.0', 'UTF-8');
        $clean->formatOutput = true;
        $clean->preserveWhiteSpace = false;
        $clean->loadXML((string) $dom->saveXML(), LIBXML_NSCLEAN);

        return $clean;
    }

    private function appendUblExtensionsPlaceholder(DOMDocument $dom, DOMElement $root): void
    {
        $extensions = $dom->createElementNS(self::NS_EXT, 'ext:UBLExtensions');
        $extension = $dom->createElementNS(self::NS_EXT, 'ext:UBLExtension');
        $content = $dom->createElementNS(self::NS_EXT, 'ext:ExtensionContent');
        $signatures = $dom->createElementNS(self::NS_SIG, 'sig:UBLDocumentSignatures');
        $info = $dom->createElementNS(self::NS_SAC, 'sac:SignatureInformation');

        $signatures->appendChild($info);
        $content->appendChild($signatures);
        $extension->appendChild($content);
        $extensions->appendChild($extension);
        $root->appendChild($extensions);
    }

    private function appendHeader(DOMDocument $dom, DOMElement $root, Invoice $invoice): void
    {
        $this->appendCbc($dom, $root, 'CustomizationID', self::CUSTOMIZATION_ID);
        $this->appendCbc($dom, $root, 'ProfileID', self::PROFILE_ID);
        $this->appendCbc($dom, $root, 'ID', $invoice->invoice_number);
        $this->appendCbc($dom, $root, 'IssueDate', $invoice->issue_date->format('Y-m-d'));
        $this->appendCbc($dom, $root, 'IssueTime', self::ISSUE_TIME);

        if ($invoice->due_date !== null) {
            $this->appendCbc($dom, $root, 'DueDate', $invoice->due_date->format('Y-m-d'));
        }

        $this->appendCbc($dom, $root, 'InvoiceTypeCode', self::INVOICE_TYPE_CODE);
        $this->appendCbc($dom, $root, 'DocumentCurrencyCode', $invoice->currency);
    }

    private function appendSupplierParty(DOMDocument $dom, DOMElement $root, Invoice $invoice): void
    {
        $wrapper = $dom->createElementNS(self::NS_CAC, 'cac:AccountingSupplierParty');
        $wrapper->appendChild($this->buildParty($dom, $invoice->supplier));

        $contact = $dom->createElementNS(self::NS_CAC, 'cac:SellerContact');
        $this->appendCbc($dom, $contact, 'ID', $invoice->supplier->oib);
        $this->appendCbc($dom, $contact, 'Name', $invoice->supplier->name);
        $wrapper->appendChild($contact);

        $root->appendChild($wrapper);
    }

    private function appendCustomerParty(DOMDocument $dom, DOMElement $root, Invoice $invoice): void
    {
        $wrapper = $dom->createElementNS(self::NS_CAC, 'cac:AccountingCustomerParty');
        $wrapper->appendChild($this->buildParty($dom, $invoice->buyer));
        $root->appendChild($wrapper);
    }

    private function buildParty(DOMDocument $dom, Taxpayer $taxpayer): DOMElement
    {
        $party = $dom->createElementNS(self::NS_CAC, 'cac:Party');

        $endpoint = $this->appendCbc($dom, $party, 'EndpointID', $taxpayer->oib);
        $endpoint->setAttribute('schemeID', '9934');

        $address = $dom->createElementNS(self::NS_CAC, 'cac:PostalAddress');
        $this->appendCbc($dom, $address, 'StreetName', $taxpayer->address_line);
        $this->appendCbc($dom, $address, 'CityName', $taxpayer->city);
        $this->appendCbc($dom, $address, 'PostalZone', $taxpayer->postcode);

        $country = $dom->createElementNS(self::NS_CAC, 'cac:Country');
        $this->appendCbc($dom, $country, 'IdentificationCode', $taxpayer->country_code);
        $address->appendChild($country);
        $party->appendChild($address);

        if ($taxpayer->is_vat_registered) {
            $taxScheme = $dom->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
            $this->appendCbc($dom, $taxScheme, 'CompanyID', $taxpayer->country_code.$taxpayer->oib);
            $scheme = $dom->createElementNS(self::NS_CAC, 'cac:TaxScheme');
            $this->appendCbc($dom, $scheme, 'ID', 'VAT');
            $taxScheme->appendChild($scheme);
            $party->appendChild($taxScheme);
        }

        $legal = $dom->createElementNS(self::NS_CAC, 'cac:PartyLegalEntity');
        $this->appendCbc($dom, $legal, 'RegistrationName', $taxpayer->name);
        $party->appendChild($legal);

        return $party;
    }

    private function appendPaymentMeans(DOMDocument $dom, DOMElement $root, Invoice $invoice): void
    {
        $means = $dom->createElementNS(self::NS_CAC, 'cac:PaymentMeans');
        $this->appendCbc($dom, $means, 'PaymentMeansCode', self::PAYMENT_MEANS_CODE);

        if ($invoice->supplier->iban !== null) {
            $account = $dom->createElementNS(self::NS_CAC, 'cac:PayeeFinancialAccount');
            $this->appendCbc($dom, $account, 'ID', $invoice->supplier->iban);
            $means->appendChild($account);
        }

        $root->appendChild($means);
    }

    private function appendTaxTotal(DOMDocument $dom, DOMElement $root, Invoice $invoice): void
    {
        $taxTotal = $dom->createElementNS(self::NS_CAC, 'cac:TaxTotal');
        $taxAmount = $this->appendCbc($dom, $taxTotal, 'TaxAmount', $this->money($invoice->tax_amount));
        $taxAmount->setAttribute('currencyID', $invoice->currency);

        foreach ($this->vatBreakdown($invoice) as $row) {
            $subtotal = $dom->createElementNS(self::NS_CAC, 'cac:TaxSubtotal');

            $taxable = $this->appendCbc($dom, $subtotal, 'TaxableAmount', $row['taxable']);
            $taxable->setAttribute('currencyID', $invoice->currency);

            $amount = $this->appendCbc($dom, $subtotal, 'TaxAmount', $row['tax']);
            $amount->setAttribute('currencyID', $invoice->currency);

            $category = $dom->createElementNS(self::NS_CAC, 'cac:TaxCategory');
            $this->appendCbc($dom, $category, 'ID', $row['category']);
            $this->appendCbc($dom, $category, 'Percent', $this->percent($row['rate']));

            $scheme = $dom->createElementNS(self::NS_CAC, 'cac:TaxScheme');
            $this->appendCbc($dom, $scheme, 'ID', 'VAT');
            $category->appendChild($scheme);
            $subtotal->appendChild($category);

            $taxTotal->appendChild($subtotal);
        }

        $root->appendChild($taxTotal);
    }

    private function appendLegalMonetaryTotal(DOMDocument $dom, DOMElement $root, Invoice $invoice): void
    {
        $totals = $dom->createElementNS(self::NS_CAC, 'cac:LegalMonetaryTotal');

        foreach (['LineExtensionAmount', 'TaxExclusiveAmount'] as $tag) {
            $el = $this->appendCbc($dom, $totals, $tag, $this->money($invoice->net_amount));
            $el->setAttribute('currencyID', $invoice->currency);
        }

        foreach (['TaxInclusiveAmount', 'PayableAmount'] as $tag) {
            $el = $this->appendCbc($dom, $totals, $tag, $this->money($invoice->total_amount));
            $el->setAttribute('currencyID', $invoice->currency);
        }

        $root->appendChild($totals);
    }

    private function appendInvoiceLines(DOMDocument $dom, DOMElement $root, Invoice $invoice): void
    {
        $i = 1;
        foreach ($invoice->lines as $line) {
            $root->appendChild($this->buildInvoiceLine($dom, $invoice, $line, $i++));
        }
    }

    private function buildInvoiceLine(DOMDocument $dom, Invoice $invoice, InvoiceLine $line, int $index): DOMElement
    {
        $node = $dom->createElementNS(self::NS_CAC, 'cac:InvoiceLine');

        $this->appendCbc($dom, $node, 'ID', (string) $index);

        $qty = $this->appendCbc($dom, $node, 'InvoicedQuantity', $this->quantity($line->quantity));
        $qty->setAttribute('unitCode', $line->unit_code);

        $lineTotal = $this->appendCbc($dom, $node, 'LineExtensionAmount', $this->money($line->line_total));
        $lineTotal->setAttribute('currencyID', $invoice->currency);

        $item = $dom->createElementNS(self::NS_CAC, 'cac:Item');
        $this->appendCbc($dom, $item, 'Name', $line->description);

        if ($line->kpd_code !== null) {
            $classification = $dom->createElementNS(self::NS_CAC, 'cac:CommodityClassification');
            $code = $this->appendCbc($dom, $classification, 'ItemClassificationCode', $this->formatKpd($line->kpd_code));
            $code->setAttribute('listID', 'CG');
            $item->appendChild($classification);
        }

        $taxCategory = $dom->createElementNS(self::NS_CAC, 'cac:ClassifiedTaxCategory');
        $this->appendCbc($dom, $taxCategory, 'ID', $line->vat_category->value);
        $this->appendCbc($dom, $taxCategory, 'Percent', $this->percent($line->vat_rate));
        $scheme = $dom->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $this->appendCbc($dom, $scheme, 'ID', 'VAT');
        $taxCategory->appendChild($scheme);
        $item->appendChild($taxCategory);

        $node->appendChild($item);

        $price = $dom->createElementNS(self::NS_CAC, 'cac:Price');
        $priceAmount = $this->appendCbc($dom, $price, 'PriceAmount', $this->money($line->unit_price));
        $priceAmount->setAttribute('currencyID', $invoice->currency);
        $node->appendChild($price);

        return $node;
    }

    /**
     * @return list<array{category: string, rate: string, taxable: string, tax: string}>
     */
    private function vatBreakdown(Invoice $invoice): array
    {
        $groups = [];

        foreach ($invoice->lines as $line) {
            $key = $line->vat_category->value.'|'.$this->percent($line->vat_rate);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $line->vat_category->value,
                    'rate' => $line->vat_rate,
                    'taxable' => '0.00',
                    'tax' => '0.00',
                ];
            }
            $groups[$key]['taxable'] = bcadd($groups[$key]['taxable'], $line->line_total, 2);
            $groups[$key]['tax'] = bcadd(
                $groups[$key]['tax'],
                bcdiv(bcmul($line->line_total, (string) $line->vat_rate, 4), '100', 2),
                2,
            );
        }

        return array_values($groups);
    }

    private function appendCbc(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS_CBC, 'cbc:'.$name, $value);
        $parent->appendChild($el);

        return $el;
    }

    private function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function percent(string $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
    }

    private function quantity(string $quantity): string
    {
        return number_format((float) $quantity, 3, '.', '');
    }

    private function formatKpd(string $code): string
    {
        if (preg_match('/^\d{6}$/', $code) === 1) {
            return substr($code, 0, 2).'.'.substr($code, 2, 2).'.'.substr($code, 4, 2);
        }

        return $code;
    }
}
