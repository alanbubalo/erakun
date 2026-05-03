<?php

namespace App\Models;

use App\Enums\FiscalMessageState;
use App\Enums\FiscalMessageType;
use App\Enums\MatchStatus;
use Database\Factories\FiscalMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $reporter_oib
 * @property FiscalMessageType $message_type
 * @property FiscalMessageState $state
 * @property string|null $service_message_id
 * @property MatchStatus|null $match_status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $request_xml
 * @property string|null $response_xml
 * @property Carbon|null $submitted_at
 * @property Carbon|null $settled_at
 */
class FiscalMessage extends Model
{
    /** @use HasFactory<FiscalMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'reporter_oib',
        'message_type',
        'state',
        'service_message_id',
        'match_status',
        'error_code',
        'error_message',
        'request_xml',
        'response_xml',
        'submitted_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'message_type' => FiscalMessageType::class,
            'state' => FiscalMessageState::class,
            'match_status' => MatchStatus::class,
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
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
