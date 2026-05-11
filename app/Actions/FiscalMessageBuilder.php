<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Invoice;
use DOMDocument;
use DOMElement;

class FiscalMessageBuilder
{
    private const string NS = 'urn:hr:erakun:fiscal:1.0';

    public function build(Invoice $invoice, string $reporterOib): DOMDocument
    {
        $invoice->loadMissing('supplier', 'buyer', 'lines');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;

        $root = $dom->createElementNS(self::NS, 'FiscalizationRequest');
        $dom->appendChild($root);

        $root->appendChild($this->buildOibParty($dom, 'Reporter', $reporterOib));
        $root->appendChild($this->buildOibParty($dom, 'Supplier', $invoice->supplier->oib));
        $root->appendChild($this->buildOibParty($dom, 'Buyer', $invoice->buyer->oib));
        $root->appendChild($this->buildInvoiceData($dom, $invoice));

        return $dom;
    }

    private function buildOibParty(DOMDocument $dom, string $name, string $oib): DOMElement
    {
        $party = $dom->createElementNS(self::NS, $name);
        $party->appendChild($dom->createElementNS(self::NS, 'Oib', $oib));

        return $party;
    }

    private function buildInvoiceData(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $node = $dom->createElementNS(self::NS, 'Invoice');

        $this->appendNs($dom, $node, 'Number', $invoice->invoice_number);
        $this->appendNs($dom, $node, 'IssueDate', $invoice->issue_date->format('Y-m-d'));
        $this->appendNs($dom, $node, 'Currency', $invoice->currency);
        $this->appendNs($dom, $node, 'NetAmount', $this->money($invoice->net_amount));
        $this->appendNs($dom, $node, 'TaxAmount', $this->money($invoice->tax_amount));
        $this->appendNs($dom, $node, 'TotalAmount', $this->money($invoice->total_amount));

        $breakdown = $dom->createElementNS(self::NS, 'VatBreakdown');
        foreach ($invoice->vatBreakdown() as $row) {
            $group = $dom->createElementNS(self::NS, 'Group');
            $this->appendNs($dom, $group, 'Category', $row['category']);
            $this->appendNs($dom, $group, 'Rate', $this->money($row['rate']));
            $this->appendNs($dom, $group, 'Net', $row['taxable']);
            $this->appendNs($dom, $group, 'Tax', $row['tax']);
            $breakdown->appendChild($group);
        }
        $node->appendChild($breakdown);

        return $node;
    }

    private function appendNs(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name, $value);
        $parent->appendChild($el);

        return $el;
    }

    private function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
