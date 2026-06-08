<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/** @mixin Certificate */
class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'party_id' => $this->party_id,
            'status' => $this->status,
            'serial_number' => $this->serial_number,
            'subject' => $this->subject,
            'issuer' => $this->issuer,
            'valid_from' => $this->valid_from,
            'valid_to' => $this->valid_to,
            'fingerprint' => $this->fingerprint,
            // The public certificate is safe to expose; the private key is not
            // and is never part of this resource.
            'certificate_pem' => $this->certificate_pem,
        ];
    }
}
