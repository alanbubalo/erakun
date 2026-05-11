<?php

namespace App\Http\Resources;

use App\Enums\InvoiceDirection;
use App\Models\FiscalMessage;
use App\Models\Invoice;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 *
 * @property DateTimeInterface $issue_date
 * @property DateTimeInterface|null $due_date
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
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status->value,
            'direction' => $this->direction->value,
            'currency' => $this->currency,
            'net_amount' => $this->net_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'fiscalization' => $this->fiscalizationBlock(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fiscalizationBlock(): ?array
    {
        $reporterOib = $this->direction === InvoiceDirection::Outbound
            ? $this->supplier->oib
            : $this->buyer->oib;

        $message = $this->resource->latestFiscalMessageFor($reporterOib);

        if (! $message instanceof FiscalMessage) {
            return null;
        }

        return [
            'state' => $message->state->value,
            'service_message_id' => $message->service_message_id,
            'match_status' => $message->match_status?->value,
            'error_code' => $message->error_code,
            'error_message' => $message->error_message,
            'submitted_at' => $message->submitted_at?->toIso8601ZuluString(),
            'settled_at' => $message->settled_at?->toIso8601ZuluString(),
        ];
    }
}
