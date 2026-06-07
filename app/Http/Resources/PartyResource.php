<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/** @mixin Party */
class PartyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'oib' => $this->oib,
            'name' => $this->name,
            'is_vat_registered' => $this->is_vat_registered,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'postcode' => $this->postcode,
            'country_code' => $this->country_code,
            'iban' => $this->iban,
        ];
    }
}
