<?php

namespace App\Actions;

final class ParsedInvoice
{
    /**
     * @param  list<ParsedInvoiceLine>  $lines
     */
    public function __construct(
        public readonly ParsedParty $supplier,
        public readonly ParsedParty $buyer,
        public readonly string $invoiceNumber,
        public readonly string $issueDate,
        public readonly ?string $dueDate,
        public readonly string $currency,
        public readonly string $netAmount,
        public readonly string $taxAmount,
        public readonly string $totalAmount,
        public readonly array $lines,
    ) {}
}
