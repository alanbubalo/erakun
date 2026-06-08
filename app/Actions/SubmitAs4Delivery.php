<?php

declare(strict_types=1);

namespace App\Actions;

use App\As4\As4DeliveryException;
use App\As4\As4DeliveryService;
use App\Enums\As4MessageDirection;
use App\Enums\As4MessageState;
use App\Exceptions\As4DeliveryFailedException;
use App\Models\As4Message;
use App\Models\Invoice;

class SubmitAs4Delivery
{
    public function __construct(
        private readonly As4DeliveryService $delivery,
    ) {}

    public function execute(Invoice $invoice): SubmissionResult
    {
        $existing = $invoice->latestAs4MessageFor(As4MessageDirection::Outbound);

        if ($existing instanceof As4Message && $existing->state === As4MessageState::Acknowledged) {
            return new SubmissionResult(SubmissionOutcome::AlreadyTerminal, $existing);
        }

        $invoice->loadMissing('supplier', 'buyer');
        $senderOib = $invoice->supplier->oib;
        $recipientOib = $invoice->buyer->oib;

        $ublXml = $invoice->ubl_xml;

        // No-op when the invoice has no signed UBL — the Draft → Queued pipeline
        // always populates it; reaching here without one means the caller bypassed
        // generation (factory-built fixtures, seeds). Nothing to deliver.
        if ($ublXml === null || $ublXml === '') {
            return new SubmissionResult(SubmissionOutcome::Skipped);
        }

        try {
            $receipt = $this->delivery->send($ublXml, $senderOib, $recipientOib);
        } catch (As4DeliveryException $e) {
            throw $this->persistErrorAndWrap($invoice, $e, $senderOib, $recipientOib);
        }

        $message = $invoice->as4Messages()->create([
            'direction' => As4MessageDirection::Outbound,
            'message_id' => $receipt->messageId,
            'ref_to_message_id' => $receipt->receiptMessageId,
            'from_oib' => $senderOib,
            'to_oib' => $recipientOib,
            'state' => As4MessageState::Acknowledged,
            'envelope_xml' => $receipt->envelopeXml,
            'receipt_xml' => $receipt->receiptXml,
            'sent_at' => now(),
            'acknowledged_at' => $receipt->acknowledgedAt,
        ]);

        return new SubmissionResult(SubmissionOutcome::Submitted, $message);
    }

    private function persistErrorAndWrap(
        Invoice $invoice,
        As4DeliveryException $exception,
        string $senderOib,
        string $recipientOib,
    ): As4DeliveryFailedException {
        if ($exception->envelopeXml === null || $exception->messageId === null) {
            // Pre-build failure (e.g. unknown recipient) — nothing to audit.
            return new As4DeliveryFailedException(
                errorCode: $exception->errorCode,
                errorMessage: $exception->getMessage(),
            );
        }

        $message = $invoice->as4Messages()->create([
            'direction' => As4MessageDirection::Outbound,
            'message_id' => $exception->messageId,
            'from_oib' => $senderOib,
            'to_oib' => $recipientOib,
            'state' => As4MessageState::Error,
            'envelope_xml' => $exception->envelopeXml,
            'error_code' => $exception->errorCode,
            'error_message' => $exception->getMessage(),
            'sent_at' => now(),
        ]);

        return new As4DeliveryFailedException(
            errorCode: $exception->errorCode,
            errorMessage: $exception->getMessage(),
            as4Message: $message,
        );
    }
}
