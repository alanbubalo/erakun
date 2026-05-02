<?php

namespace App\Actions;

use App\Enums\VatCategory;

final class ParsedInvoiceLine
{
    public function __construct(
        public readonly string $description,
        public readonly string $quantity,
        public readonly string $unitPrice,
        public readonly string $lineTotal,
        public readonly string $vatRate,
        public readonly VatCategory $vatCategory,
        public readonly string $unitCode,
        public readonly ?string $kpdCode,
    ) {}
}
