<?php

namespace App\Actions;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Exceptions\As4DeliveryFailedException;
use App\Exceptions\FiscalizationException;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

class TransitionInvoiceStatus
{
    public function __construct(
        private readonly UblDocumentRenderer $renderer,
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

        // Render (and validate) before mutating status so an invalid document
        // leaves the invoice in its current state — renderer->signed() throws.
        $signedXml = $this->shouldGenerateUbl($invoice, $target)
            ? $this->renderer->signed($invoice)
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
}
