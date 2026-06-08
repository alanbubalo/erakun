<?php

declare(strict_types=1);

use App\Actions\SubmissionOutcome;
use App\Actions\SubmitFiscalization;
use App\Fiscalization\FiscalizationService;
use App\Models\FiscalMessage;
use App\Models\Invoice;

it('returns AlreadyTerminal without re-fiscalizing when the latest message is accepted', function (): void {
    $invoice = Invoice::factory()->outbound()->create();
    $reporterOib = $invoice->reporterOib();

    FiscalMessage::factory()->accepted()->for($invoice)->create([
        'reporter_oib' => $reporterOib,
    ]);

    // A bare mock with no expectations fails the test if fiscalize() is ever reached.
    $this->mock(FiscalizationService::class);

    $result = resolve(SubmitFiscalization::class)->execute($invoice, $reporterOib);

    expect($result->outcome)->toBe(SubmissionOutcome::AlreadyTerminal);
});
