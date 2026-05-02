<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceLine */
class InvoiceLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'vat_rate' => $this->vat_rate,
            'vat_category' => $this->vat_category->value,
            'unit_code' => $this->unit_code,
            'kpd_code' => $this->kpd_code,
        ];
    }
}
