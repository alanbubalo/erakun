<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class MissingSigningCertificateException extends RuntimeException
{
    public function __construct(public readonly string $oib)
    {
        parent::__construct("Party {$oib} has no active signing certificate. Upload one or run `php artisan pki:generate --parties`.");
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
        ], 422);
    }
}
