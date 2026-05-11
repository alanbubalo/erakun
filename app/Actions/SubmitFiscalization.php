<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FiscalMessageState;
use App\Enums\FiscalMessageType;
use App\Exceptions\FiscalizationException;
use App\Fiscalization\FiscalizationService;
use App\Fiscalization\FiscalizationServiceException;
use App\Models\FiscalMessage;
use App\Models\Invoice;
use RuntimeException;

class SubmitFiscalization
{
    public function __construct(
        private readonly FiscalMessageBuilder $builder,
        private readonly InvoiceSigner $signer,
        private readonly FiscalizationService $fiscalization,
    ) {}

    public function execute(Invoice $invoice, string $reporterOib): FiscalMessage
    {
        $existing = $invoice->latestFiscalMessageFor($reporterOib);

        if ($existing instanceof FiscalMessage && $existing->state === FiscalMessageState::Accepted) {
            return $existing;
        }

        $dom = $this->builder->build($invoice, $reporterOib);
        $signed = $this->signer->execute($dom);
        $xml = $signed->saveXML();

        throw_if($xml === false, RuntimeException::class, 'Failed to serialize signed fiscal message.');

        $message = $this->upsertRequested($invoice, $reporterOib, $xml, $existing);

        try {
            $response = $this->fiscalization->fiscalize($xml);
        } catch (FiscalizationServiceException $e) {
            $message->update([
                'state' => FiscalMessageState::Error,
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
                'settled_at' => now(),
            ]);

            throw new FiscalizationException($message->refresh());
        }

        $message->update([
            'state' => FiscalMessageState::Accepted,
            'service_message_id' => $response->messageId,
            'match_status' => $response->matchStatus,
            'error_code' => null,
            'error_message' => null,
            'settled_at' => now(),
        ]);

        return $message->refresh();
    }

    private function upsertRequested(
        Invoice $invoice,
        string $reporterOib,
        string $xml,
        ?FiscalMessage $existing,
    ): FiscalMessage {
        $attributes = [
            'state' => FiscalMessageState::Requested,
            'request_xml' => $xml,
            'response_xml' => null,
            'service_message_id' => null,
            'match_status' => null,
            'error_code' => null,
            'error_message' => null,
            'submitted_at' => now(),
            'settled_at' => null,
        ];

        if ($existing instanceof FiscalMessage) {
            $existing->update($attributes);

            return $existing;
        }

        return $invoice->fiscalMessages()->create([
            ...$attributes,
            'reporter_oib' => $reporterOib,
            'message_type' => FiscalMessageType::Fis,
        ]);
    }
}
