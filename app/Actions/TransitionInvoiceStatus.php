<?php

namespace App\Actions;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Exceptions\FiscalizationException;
use App\Exceptions\InvoiceValidationException;
use App\Models\Invoice;
use App\Validation\UblValidator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TransitionInvoiceStatus
{
    public function __construct(
        private readonly UblGenerator $generator,
        private readonly InvoiceSigner $signer,
        private readonly UblValidator $validator,
        private readonly SubmitFiscalization $submitFiscalization,
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
        $invoice->load('supplier', 'buyer', 'lines');

        if ($this->shouldFiscalizeOutbound($invoice, $target)) {
            try {
                $this->submitFiscalization->execute($invoice, $invoice->supplier->oib);
            } catch (FiscalizationException) {
                // Fiscalization is decoupled — the error is persisted on the fiscal_messages
                // row and surfaced via the resource. The lifecycle transition is not rolled
                // back; callers retry via POST /invoices/{invoice}/fiscalize.
            }
        }

        return $invoice;
    }

    private function shouldFiscalizeOutbound(Invoice $invoice, InvoiceStatus $target): bool
    {
        return $target === InvoiceStatus::Sent
            && $invoice->direction === InvoiceDirection::Outbound;
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

        throw_if($xml === false, RuntimeException::class, 'Failed to serialize signed UBL document.');

        $report = $this->validator->validate($xml);
        throw_unless($report->isValid(), InvoiceValidationException::class, $report);

        return $xml;
    }
}
