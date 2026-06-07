<?php

namespace App\Actions;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

final class StoreInvoiceUbl
{
    /**
     * Persist the exact UBL byte stream to the configured filesystem disk and
     * record only its path on the invoice. The bytes are stored verbatim — the
     * XML-DSig signature is computed over them, so callers must never pass a
     * re-serialised document.
     */
    public function execute(Invoice $invoice, string $xml): Invoice
    {
        $path = "invoices/{$invoice->id}/ubl.xml";

        Storage::put($path, $xml);

        $invoice->ubl_xml_path = $path;
        $invoice->save();

        return $invoice;
    }
}
