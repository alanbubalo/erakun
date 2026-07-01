<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;

/**
 * Delivers an invoice over AS4 and, on a confirmed acknowledgement, advances the
 * outbound lifecycle to its terminal Delivered state.
 *
 * Status advancement lives here rather than in SubmitAs4Delivery so the
 * Queued → Sent PATCH path — which calls the delivery action directly as a
 * side-effect — keeps ending on its explicit target status. The standalone
 * deliver route has no explicit target, so a successful delivery completes the
 * lifecycle.
 */
class DeliverInvoice
{
    public function __construct(
        private readonly SubmitAs4Delivery $delivery,
    ) {}

    public function execute(Invoice $invoice): SubmissionResult
    {
        $result = $this->delivery->execute($invoice);

        if ($result->outcome === SubmissionOutcome::Submitted
            && $invoice->direction === InvoiceDirection::Outbound) {
            $this->advanceToDelivered($invoice);
        }

        return $result;
    }

    /**
     * A confirmed AS4 receipt means the recipient's access point has the
     * document, so walk the outbound lifecycle to Delivered. Each hop is guarded
     * via canTransitionTo(), so an invoice already partway through (or in a
     * non-advanceable state) is a safe no-op rather than an
     * InvalidInvoiceTransitionException.
     */
    private function advanceToDelivered(Invoice $invoice): void
    {
        foreach ([InvoiceStatus::Sent, InvoiceStatus::Delivered] as $target) {
            if ($invoice->status->canTransitionTo($target)) {
                $invoice->transitionTo($target);
            }
        }
    }
}
