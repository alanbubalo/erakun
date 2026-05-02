<?php

namespace App\Http\Controllers;

use App\Actions\UblGenerator;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Http\Response;

class InvoiceXmlController extends Controller
{
    public function show(Invoice $invoice, UblGenerator $generator): Response
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
}
