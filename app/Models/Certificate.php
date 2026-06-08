<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Pki\SigningCredential;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Override;

#[Fillable([
    'status',
    'serial_number',
    'subject',
    'issuer',
    'valid_from',
    'valid_to',
    'certificate_pem',
    'private_key_path',
    'fingerprint',
])]
#[Hidden(['private_key_path'])]
class Certificate extends Model
{
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @param  Builder<Certificate>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', CertificateStatus::Active);
    }

    public function toSigningCredential(): SigningCredential
    {
        return new SigningCredential(
            privateKeyPem: (string) Storage::disk((string) config('pki.disk'))->get($this->private_key_path),
            certificatePem: $this->certificate_pem,
        );
    }
}
