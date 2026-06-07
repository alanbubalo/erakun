<?php

namespace App\Actions;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Exceptions\As4DeliveryFailedException;
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
        private readonly SubmitAs4Delivery $submitAs4Delivery,
        private readonly SubmitFiscalization $submitFiscalization,
        private readonly StoreInvoiceUbl $storeUbl,
    ) {}

    public function execute(Invoice $invoice, InvoiceStatus $target): Invoice
    {
        if (! $invoice->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from {$invoice->status->value} to {$target->value}.",
            ]);
        }

        // Generate (and validate) before mutating status so an invalid document
        // leaves the invoice in its current state — generateSignedXml throws.
        $signedXml = $this->shouldGenerateUbl($invoice, $target)
            ? $this->generateSignedXml($invoice)
            : null;

        $invoice->update(['status' => $target]);

        if ($signedXml !== null) {
            $this->storeUbl->execute($invoice, $signedXml);
        }

        $invoice->load('supplier', 'buyer', 'lines');

        if ($this->shouldDeliver($invoice, $target)) {
            try {
                $this->submitAs4Delivery->execute($invoice);
            } catch (As4DeliveryFailedException) {
                // AS4 delivery is decoupled — the error is persisted on the as4_messages
                // row and surfaced via the resource. The lifecycle transition is not rolled
                // back; callers retry via POST /invoices/{invoice}/deliver.
            }
        }

        if ($this->shouldFiscalize($invoice, $target)) {
            try {
                $this->submitFiscalization->execute($invoice, $invoice->reporterOib());
            } catch (FiscalizationException) {
                // Fiscalization is decoupled — the error is persisted on the fiscal_messages
                // row and surfaced via the resource. The lifecycle transition is not rolled
                // back; callers retry via POST /invoices/{invoice}/fiscalize.
            }
        }

        return $invoice;
    }

    private function shouldDeliver(Invoice $invoice, InvoiceStatus $target): bool
    {
        return $target === InvoiceStatus::Sent
            && $invoice->direction === InvoiceDirection::Outbound;
    }

    private function shouldFiscalize(Invoice $invoice, InvoiceStatus $target): bool
    {
        return ($target === InvoiceStatus::Sent && $invoice->direction === InvoiceDirection::Outbound)
            || ($target === InvoiceStatus::Delivered && $invoice->direction === InvoiceDirection::Inbound);
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
