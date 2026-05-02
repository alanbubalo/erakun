<?php

namespace App\Http\Controllers;

use App\Actions\TransitionInvoiceStatus;
use App\Enums\InvoiceStatus;
use App\Http\Requests\UpdateInvoiceStatusRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

class InvoiceStatusController extends Controller
{
    public function update(
        UpdateInvoiceStatusRequest $request,
        Invoice $invoice,
        TransitionInvoiceStatus $action,
    ): JsonResponse {
        $target = InvoiceStatus::from($request->validated('status'));
        $invoice = $action->execute($invoice, $target);

        return InvoiceResource::make($invoice)
            ->response()
            ->setStatusCode(200);
    }
}
