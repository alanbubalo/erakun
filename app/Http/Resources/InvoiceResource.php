<?php

namespace App\Http\Resources;

use App\Enums\As4MessageDirection;
use App\Enums\FiscalMessageState;
use App\Enums\MatchStatus;
use App\Fiscalization\FiscalizationService;
use App\Fiscalization\FiscalizationServiceException;
use App\Models\As4Message;
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
            'supplier' => PartyResource::make($this->supplier),
            'buyer' => PartyResource::make($this->buyer),
            'issue_date' => $this->issue_date->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status->value,
            'direction' => $this->direction->value,
            'currency' => $this->currency,
            'net_amount' => $this->net_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'delivery' => $this->deliveryBlock(),
            'fiscalization' => $this->fiscalizationBlock(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function deliveryBlock(): ?array
    {
        $direction = $this->resource->direction->value === 'outbound'
            ? As4MessageDirection::Outbound
            : As4MessageDirection::Inbound;

        $message = $this->resource->latestAs4MessageFor($direction);

        if (! $message instanceof As4Message) {
            return null;
        }

        return [
            'state' => $message->state->value,
            'message_id' => $message->message_id,
            'peer_message_id' => $message->ref_to_message_id,
            'sent_at' => $message->sent_at?->toIso8601ZuluString(),
            'acknowledged_at' => $message->acknowledged_at?->toIso8601ZuluString(),
            'error' => $message->error_code === null ? null : [
                'code' => $message->error_code,
                'message' => $message->error_message,
            ],
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
            return resolve(FiscalizationService::class)->lookupMatch(
                supplierOib: $this->resource->supplier->oib,
                buyerOib: $this->resource->buyer->oib,
                invoiceNumber: $this->resource->invoice_number,
            )->matchStatus;
        } catch (FiscalizationServiceException) {
            return $message->match_status;
        }
    }
}
