<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\InvoiceStatus;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvalidInvoiceTransitionException extends RuntimeException
{
    public function __construct(
        public readonly InvoiceStatus $from,
        public readonly InvoiceStatus $to,
    ) {
        parent::__construct("Cannot transition from {$from->value} to {$to->value}.");
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
        ], 422);
    }
}
