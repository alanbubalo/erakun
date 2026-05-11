<?php

declare(strict_types=1);

use App\Enums\FiscalMessageState;
use App\Enums\InvoiceStatus;
use App\Enums\MatchStatus;
use App\Fiscalization\FiscalizationService;
use Tests\Fixtures\InvoiceFixture;
use Tests\Integration\CisProcess;

beforeAll(function (): void {
    CisProcess::boot();
});

afterAll(function (): void {
    CisProcess::stop();
});

beforeEach(function (): void {
    if (! CisProcess::isReady()) {
        $log = CisProcess::bootLog();
        $reason = 'erakun-porezna sibling app is not reachable on '.CisProcess::BASE_URL;

        if ($log !== '') {
            $reason .= "\n--- subprocess output ---\n".$log;
        }

        test()->markTestSkipped($reason);
    }

    CisProcess::reset();

    config([
        'services.fiscalization.url' => CisProcess::BASE_URL,
        'services.fiscalization.timeout' => 5,
    ]);

    app()->forgetInstance(FiscalizationService::class);
});

it('fiscalizes an outbound invoice through the real CIS wire', function (): void {
    $invoice = InvoiceFixture::outbound();

    test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'queued'])->assertOk();

    $response = test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);

    $response->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.fiscalization.state', 'accepted')
        ->assertJsonPath('data.fiscalization.match_status', 'pending');

    $messageId = $response->json('data.fiscalization.service_message_id');
    expect($messageId)->toStartWith('FIS-2026-');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent);

    $message = $invoice->fresh()->latestFiscalMessageFor($invoice->supplier->oib);
    expect($message)->not->toBeNull()
        ->and($message->state)->toBe(FiscalMessageState::Accepted)
        ->and($message->match_status)->toBe(MatchStatus::Pending)
        ->and($message->service_message_id)->toBe($messageId);
});

it('returns the same message id when fiscalizing the same invoice twice (idempotency)', function (): void {
    $invoice = InvoiceFixture::outbound();

    $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'queued'])->assertOk();
    $first = $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);
    $firstId = $first->json('data.fiscalization.service_message_id');

    $second = $this->postJson("/api/invoices/{$invoice->id}/fiscalize");

    $second->assertStatus(409)
        ->assertJsonPath('message', 'Fiscal message is already accepted.');

    expect($invoice->fresh()->latestFiscalMessageFor($invoice->supplier->oib)->service_message_id)
        ->toBe($firstId);
});
