<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmissionOutcome;
use App\Actions\SubmitFiscalization;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

class InvoiceFiscalizationController extends Controller
{
    public function store(Invoice $invoice, SubmitFiscalization $action): JsonResponse
    {
        $result = $action->execute($invoice, $invoice->reporterOib());

        if ($result->outcome === SubmissionOutcome::AlreadyTerminal) {
            return new JsonResponse([
                'message' => 'Fiscal message is already accepted.',
            ], 409);
        }

        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'))
            ->response()
            ->setStatusCode(200);
    }
}
