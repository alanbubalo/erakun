<?php

namespace App\Http\Controllers;

use App\Actions\CreateInvoice;
use App\Enums\InvoiceStatus;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceStatusRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function store(StoreInvoiceRequest $request, CreateInvoice $action): JsonResponse
    {
        $invoice = $action->execute($request->validated());

        return InvoiceResource::make($invoice)
            ->response()
            ->setStatusCode(201);
    }

    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $query = Invoice::with('supplier', 'buyer', 'lines')
            ->when(
                $request->validated('status'),
                fn ($q, $status) => $q->where('status', $status),
            )
            ->when(
                $request->validated('direction'),
                fn ($q, $direction) => $q->where('direction', $direction),
            )
            ->when(
                $request->validated('supplier_oib'),
                fn ($q, $oib) => $q->whereHas(
                    'supplier',
                    fn ($q) => $q->where('oib', $oib)
                )
            )
            ->when(
                $request->validated('buyer_oib'),
                fn ($q, $oib) => $q->whereHas(
                    'buyer',
                    fn ($q) => $q->where('oib', $oib)
                )
            )
            ->latest();

        return InvoiceResource::collection($query->paginate());
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'));
    }

    public function updateStatus(UpdateInvoiceStatusRequest $request, Invoice $invoice): JsonResponse
    {
        $targetStatus = InvoiceStatus::from($request->validated('status'));

        if (! $invoice->status->canTransitionTo($targetStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from {$invoice->status->value} to {$targetStatus->value}.",
            ]);
        }

        $invoice->update(['status' => $targetStatus]);

        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'))
            ->response()
            ->setStatusCode(200);
    }
}
