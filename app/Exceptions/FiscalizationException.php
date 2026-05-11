<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\FiscalMessage;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class FiscalizationException extends RuntimeException
{
    public function __construct(public readonly FiscalMessage $fiscalMessage)
    {
        parent::__construct('Fiscalization service rejected the request.');
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'fiscalization_error' => [
                'code' => $this->fiscalMessage->error_code,
                'message' => $this->fiscalMessage->error_message,
                'submitted_at' => $this->fiscalMessage->submitted_at?->toIso8601ZuluString(),
            ],
        ], 422);
    }
}
