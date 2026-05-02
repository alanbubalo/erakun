<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class ParsedInvoice
{
    /**
     * @param  list<ParsedInvoiceLine>  $lines
     */
    public function __construct(
        public ParsedParty $supplier,
        public ParsedParty $buyer,
        public string $invoiceNumber,
        public string $issueDate,
        public ?string $dueDate,
        public string $currency,
        public string $netAmount,
        public string $taxAmount,
        public string $totalAmount,
        public array $lines,
    ) {}
}
