<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\As4Message;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class As4DeliveryFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $errorMessage,
        public readonly ?As4Message $as4Message = null,
    ) {
        parent::__construct('AS4 peer rejected the delivery.');
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'delivery_error' => [
                'code' => $this->errorCode,
                'message' => $this->errorMessage,
                'message_id' => $this->as4Message?->message_id,
                'sent_at' => $this->as4Message?->sent_at?->toIso8601ZuluString(),
            ],
        ], 422);
    }
}
