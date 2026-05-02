<?php

namespace App\Models;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property InvoiceStatus $status
 * @property InvoiceDirection $direction
 * @property string $currency
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string|null $ubl_xml
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'buyer_id',
        'invoice_number',
        'issue_date',
        'due_date',
        'status',
        'direction',
        'currency',
        'net_amount',
        'tax_amount',
        'total_amount',
        'ubl_xml',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'status' => InvoiceStatus::class,
            'direction' => InvoiceDirection::class,
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Taxpayer, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<Taxpayer, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Taxpayer::class, 'buyer_id');
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
