<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\VatCategory;

final readonly class ParsedInvoiceLine
{
    public function __construct(
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $lineTotal,
        public string $vatRate,
        public VatCategory $vatCategory,
        public string $unitCode,
        public ?string $kpdCode,
    ) {}
}
