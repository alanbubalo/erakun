<?php

namespace App\Models;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'buyer_id',
        'invoice_number',
        'issue_date',
        'status',
        'direction',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'status' => InvoiceStatus::class,
            'direction' => InvoiceDirection::class,
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
