<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitFiscalization;
use App\Enums\FiscalMessageState;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

class InvoiceFiscalizationController extends Controller
{
    public function store(Invoice $invoice, SubmitFiscalization $action): JsonResponse
    {
        $reporterOib = $invoice->reporterOib();

        $latest = $invoice->latestFiscalMessageFor($reporterOib);

        if ($latest !== null && $latest->state === FiscalMessageState::Accepted) {
            return new JsonResponse([
                'message' => 'Fiscal message is already accepted.',
            ], 409);
        }

        $action->execute($invoice, $reporterOib);

        return InvoiceResource::make($invoice->load('supplier', 'buyer', 'lines'))
            ->response()
            ->setStatusCode(200);
    }
}
