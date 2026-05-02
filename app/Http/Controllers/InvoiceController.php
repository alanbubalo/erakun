<?php

namespace App\Http\Controllers;

use App\Actions\CreateInvoice;
use App\Actions\InvoiceSigner;
use App\Actions\UblGenerator;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceValidationException;
use App\Http\Requests\ListInvoicesRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceStatusRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Validation\UblValidator;
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

    public function updateStatus(
        UpdateInvoiceStatusRequest $request,
        Invoice $invoice,
        UblGenerator $generator,
        InvoiceSigner $signer,
        UblValidator $validator,
    ): JsonResponse {
        $targetStatus = InvoiceStatus::from($request->validated('status'));

        if (! $invoice->status->canTransitionTo($targetStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from {$invoice->status->value} to {$targetStatus->value}.",
            ]);
        }

        $update = ['status' => $targetStatus];

        if ($this->shouldGenerateUbl($invoice, $targetStatus)) {
            $update['ubl_xml'] = $this->generateSignedXml($invoice, $generator, $signer, $validator);
        }

        $invoice->update($update);

        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'))
            ->response()
            ->setStatusCode(200);
    }

    public function xml(Invoice $invoice, UblGenerator $generator)
    {
        if ($invoice->ubl_xml !== null) {
            return response($invoice->ubl_xml, 200, ['Content-Type' => 'application/xml']);
        }

        if ($invoice->status === InvoiceStatus::Draft && $invoice->direction === InvoiceDirection::Outbound) {
            $invoice->load('supplier', 'buyer', 'lines');
            $dom = $generator->execute($invoice);

            return response($dom->saveXML(), 200, ['Content-Type' => 'application/xml']);
        }

        abort(404);
    }

    private function shouldGenerateUbl(Invoice $invoice, InvoiceStatus $target): bool
    {
        return $target === InvoiceStatus::Queued
            && $invoice->direction === InvoiceDirection::Outbound;
    }

    private function generateSignedXml(
        Invoice $invoice,
        UblGenerator $generator,
        InvoiceSigner $signer,
        UblValidator $validator,
    ): string {
        $invoice->load('supplier', 'buyer', 'lines');

        $dom = $generator->execute($invoice);
        $signed = $signer->execute($dom);
        $xml = $signed->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Failed to serialize signed UBL document.');
        }

        $report = $validator->validate($xml);
        if (! $report->isValid()) {
            throw new InvoiceValidationException($report);
        }

        return $xml;
    }
}
