<?php

namespace App\Models;

use App\Enums\As4MessageDirection;
use App\Enums\As4MessageState;
use Database\Factories\As4MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int|null $invoice_id
 * @property As4MessageDirection $direction
 * @property string $message_id
 * @property string|null $ref_to_message_id
 * @property string $from_oib
 * @property string $to_oib
 * @property As4MessageState $state
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string $envelope_xml
 * @property string|null $receipt_xml
 * @property Carbon|null $sent_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $received_at
 */
#[Fillable([
    'invoice_id',
    'direction',
    'message_id',
    'ref_to_message_id',
    'from_oib',
    'to_oib',
    'state',
    'error_code',
    'error_message',
    'envelope_xml',
    'receipt_xml',
    'sent_at',
    'acknowledged_at',
    'received_at',
])]
class As4Message extends Model
{
    /** @use HasFactory<As4MessageFactory> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'direction' => As4MessageDirection::class,
            'state' => As4MessageState::class,
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
