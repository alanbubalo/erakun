<?php

namespace App\Http\Resources;

use App\Enums\FiscalMessageState;
use App\Enums\MatchStatus;
use App\Fiscalization\FiscalizationService;
use App\Fiscalization\FiscalizationServiceException;
use App\Models\FiscalMessage;
use App\Models\Invoice;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

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
    #[Override]
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
        $message = $this->resource->latestFiscalMessageFor($this->resource->reporterOib());

        if (! $message instanceof FiscalMessage) {
            return null;
        }

        return [
            'state' => $message->state->value,
            'service_message_id' => $message->service_message_id,
            'match_status' => $this->resolveMatchStatus($message)?->value,
            'error_code' => $message->error_code,
            'error_message' => $message->error_message,
            'submitted_at' => $message->submitted_at?->toIso8601ZuluString(),
            'settled_at' => $message->settled_at?->toIso8601ZuluString(),
        ];
    }

    private function resolveMatchStatus(FiscalMessage $message): ?MatchStatus
    {
        if ($message->state !== FiscalMessageState::Accepted) {
            return $message->match_status;
        }

        try {
            return app(FiscalizationService::class)->lookupMatch(
                supplierOib: $this->resource->supplier->oib,
                buyerOib: $this->resource->buyer->oib,
                invoiceNumber: $this->resource->invoice_number,
            )->matchStatus;
        } catch (FiscalizationServiceException) {
            return $message->match_status;
        }
    }
}
