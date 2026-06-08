<?php

namespace App\Http\Controllers;

use App\Actions\UblDocumentRenderer;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Http\Response;

class InvoiceXmlController extends Controller
{
    public function show(Invoice $invoice, UblDocumentRenderer $renderer): Response
    {
        if ($invoice->ubl_xml !== null) {
            return response($invoice->ubl_xml, 200, ['Content-Type' => 'application/xml']);
        }

        if ($invoice->status === InvoiceStatus::Draft && $invoice->direction === InvoiceDirection::Outbound) {
            return response($renderer->draft($invoice), 200, ['Content-Type' => 'application/xml']);
        }

        abort(404);
    }
}
