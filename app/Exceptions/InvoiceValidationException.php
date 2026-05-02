<?php

namespace App\Exceptions;

use App\Validation\ValidationReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvoiceValidationException extends RuntimeException
{
    public function __construct(public readonly ValidationReport $report)
    {
        parent::__construct('Invoice failed UBL/EN 16931 validation.');
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'validation_report' => $this->report->toArray(),
        ], 422);
    }
}
