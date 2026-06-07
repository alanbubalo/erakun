<?php

namespace App\Models;

use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

#[Fillable([
    'oib',
    'name',
    'is_vat_registered',
    'address_line',
    'city',
    'postcode',
    'country_code',
    'iban',
])]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    #[Override]
    public function getRouteKeyName(): string
    {
        return 'oib';
    }

    #[Override]
    protected $attributes = [
        'is_vat_registered' => false,
        'country_code' => 'HR',
    ];

    #[Override]
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
