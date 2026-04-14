<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
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
            'supplier' => [
                'oib' => $this->supplier->oib,
                'name' => $this->supplier->name,
            ],
            'buyer' => [
                'oib' => $this->buyer->oib,
                'name' => $this->buyer->name,
            ],
            'issue_date' => $this->issue_date->toDateString(),
            'status' => $this->status->value,
            'direction' => $this->direction->value,
            'total_amount' => $this->total_amount,
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
