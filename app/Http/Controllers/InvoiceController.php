<?php

namespace App\Http\Controllers;

use App\Actions\CreateInvoice;
use App\Enums\InvoiceStatus;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceStatusRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends Controller
{
    public function store(StoreInvoiceRequest $request, CreateInvoice $action): JsonResponse
    {
        $invoice = $action->execute($request->validated());

        return InvoiceResource::make($invoice)
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Invoice::with('supplier', 'buyer', 'lines')
            ->when(
                $request->has('status'),
                fn ($q) => $q->where('status', $request->input('status')),
            )
            ->when(
                $request->has('direction'),
                fn ($q) => $q->where('direction', $request->input('direction')),
            )
            ->when(
                $request->has('supplier_oib'),
                fn ($q) => $q->whereHas(
                    'supplier',
                    fn ($q) => $q->where('oib', $request->input('supplier_oib'))
                )
            )
            ->when(
                $request->has('buyer_oib'),
                fn ($q) => $q->whereHas(
                    'buyer',
                    fn ($q) => $q->where('oib', $request->input('buyer_oib'))
                )
            )
            ->latest();

        return InvoiceResource::collection($query->get());
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'));
    }

    public function updateStatus(UpdateInvoiceStatusRequest $request, Invoice $invoice): JsonResponse
    {
        $targetStatus = InvoiceStatus::from($request->validated('status'));

        if (! $invoice->status->canTransitionTo($targetStatus)) {
            return response()->json([
                'message' => "Cannot transition from {$invoice->status->value} to {$targetStatus->value}.",
            ], 422);
        }

        $invoice->update(['status' => $targetStatus]);

        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'))
            ->response()
            ->setStatusCode(200);
    }
}
