<?php

declare(strict_types=1);

use App\Actions\SubmissionOutcome;
use App\Actions\SubmitAs4Delivery;
use App\As4\As4DeliveryService;
use App\Models\As4Message;
use App\Models\Invoice;

it('returns AlreadyTerminal without contacting the peer when delivery is acknowledged', function (): void {
    $invoice = Invoice::factory()->outbound()->create();
    As4Message::factory()->acknowledged()->for($invoice)->create();

    // A bare mock with no expectations fails the test if send() is ever reached.
    $this->mock(As4DeliveryService::class);

    $result = resolve(SubmitAs4Delivery::class)->execute($invoice);

    expect($result->outcome)->toBe(SubmissionOutcome::AlreadyTerminal);
});
