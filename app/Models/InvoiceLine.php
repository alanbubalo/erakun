<?php

namespace App\Models;

use App\Enums\VatCategory;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $line_total
 * @property string $vat_rate
 * @property VatCategory $vat_category
 * @property string $unit_code
 * @property string|null $kpd_code
 */
#[Fillable([
    'invoice_id',
    'description',
    'quantity',
    'unit_price',
    'line_total',
    'vat_rate',
    'vat_category',
    'unit_code',
    'kpd_code',
])]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_category' => VatCategory::class,
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
