<?php

namespace App\Models;

use Database\Factories\TaxpayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Taxpayer extends Model
{
    /** @use HasFactory<TaxpayerFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'oib';
    }

    protected $fillable = [
        'oib',
        'name',
        'is_vat_registered',
    ];

    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function suppliedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'supplier_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function receivedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'buyer_id');
    }
}
