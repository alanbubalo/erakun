<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 *
 * @property \DateTimeInterface $issue_date
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'supplier' => TaxpayerResource::make($this->supplier),
            'buyer' => TaxpayerResource::make($this->buyer),
            'issue_date' => $this->issue_date->format('Y-m-d'),
            'status' => $this->status->value,
            'direction' => $this->direction->value,
            'total_amount' => $this->total_amount,
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
