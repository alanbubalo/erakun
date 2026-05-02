<?php

namespace App\Http\Controllers;

use App\Actions\ReceiveInbound;
use App\Actions\UblParser;
use App\Exceptions\InvoiceValidationException;
use App\Http\Resources\InvoiceResource;
use App\Validation\UblValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboundInvoiceController extends Controller
{
    public function store(
        Request $request,
        UblValidator $validator,
        UblParser $parser,
        ReceiveInbound $action,
    ): JsonResponse {
        $rawXml = $request->getContent();

        $report = $validator->validate($rawXml);
        throw_unless($report->isValid(), InvoiceValidationException::class, $report);

        $parsed = $parser->parse($rawXml);
        $invoice = $action->execute($parsed, $rawXml);

        return InvoiceResource::make($invoice)
            ->response()
            ->setStatusCode($invoice->wasRecentlyCreated ? 201 : 200);
    }
}
