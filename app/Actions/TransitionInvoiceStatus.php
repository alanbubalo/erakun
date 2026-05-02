<?php

namespace App\Actions;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceValidationException;
use App\Models\Invoice;
use App\Validation\UblValidator;
use Illuminate\Validation\ValidationException;

class TransitionInvoiceStatus
{
    public function __construct(
        private UblGenerator $generator,
        private InvoiceSigner $signer,
        private UblValidator $validator,
    ) {}

    public function execute(Invoice $invoice, InvoiceStatus $target): Invoice
    {
        if (! $invoice->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from {$invoice->status->value} to {$target->value}.",
            ]);
        }

        $update = ['status' => $target];

        if ($this->shouldGenerateUbl($invoice, $target)) {
            $update['ubl_xml'] = $this->generateSignedXml($invoice);
        }

        $invoice->update($update);

        return $invoice->load('supplier', 'buyer', 'lines');
    }

    private function shouldGenerateUbl(Invoice $invoice, InvoiceStatus $target): bool
    {
        return $target === InvoiceStatus::Queued
            && $invoice->direction === InvoiceDirection::Outbound;
    }

    private function generateSignedXml(Invoice $invoice): string
    {
        $invoice->load('supplier', 'buyer', 'lines');

        $dom = $this->generator->execute($invoice);
        $signed = $this->signer->execute($dom);
        $xml = $signed->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Failed to serialize signed UBL document.');
        }

        $report = $this->validator->validate($xml);
        if (! $report->isValid()) {
            throw new InvoiceValidationException($report);
        }

        return $xml;
    }
}
