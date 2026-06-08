<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmissionOutcome;
use App\Actions\SubmitAs4Delivery;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

class InvoiceDeliveryController extends Controller
{
    public function store(Invoice $invoice, SubmitAs4Delivery $action): JsonResponse
    {
        $result = $action->execute($invoice);

        if ($result->outcome === SubmissionOutcome::AlreadyTerminal) {
            return new JsonResponse([
                'message' => 'AS4 delivery is already acknowledged.',
            ], 409);
        }

        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'))
            ->response()
            ->setStatusCode(200);
    }
}
