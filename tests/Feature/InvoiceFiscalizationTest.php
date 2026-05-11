<?php

declare(strict_types=1);

use App\Enums\FiscalMessageState;
use App\Enums\InvoiceStatus;
use App\Enums\MatchStatus;
use App\Fiscalization\FiscalizationService;
use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\InvoiceFixture;

beforeEach(function (): void {
    config(['services.fiscalization.url' => 'http://cis.test']);
    $this->app->forgetInstance(FiscalizationService::class);
});

function queueOutboundInvoice(): Invoice
{
    $invoice = InvoiceFixture::outbound();
    test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'queued'])->assertOk();

    return $invoice->fresh();
}

describe('outbound Queued → Sent', function (): void {
    it('submits the fiscal message and exposes accepted state on the resource', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(fiscAcceptedEnvelope('FIS-2026-000042', 'pending'), 200),
        ]);

        $invoice = queueOutboundInvoice();

        $response = $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.fiscalization.state', 'accepted')
            ->assertJsonPath('data.fiscalization.service_message_id', 'FIS-2026-000042')
            ->assertJsonPath('data.fiscalization.match_status', 'pending');

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent);

        $message = $invoice->latestFiscalMessageFor($invoice->supplier->oib);
        expect($message)->not->toBeNull()
            ->and($message->state)->toBe(FiscalMessageState::Accepted)
            ->and($message->match_status)->toBe(MatchStatus::Pending)
            ->and($message->request_xml)->toContain('<FiscalizationRequest')
            ->and($message->request_xml)->toContain('<ds:Signature');
    });

    it('persists an error row and keeps lifecycle as sent when the service rejects', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(fiscFaultEnvelope('MISMATCH_TOTAL', 'Totals do not match.'), 400),
        ]);

        $invoice = queueOutboundInvoice();

        $response = $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.fiscalization.state', 'error')
            ->assertJsonPath('data.fiscalization.error_code', 'MISMATCH_TOTAL')
            ->assertJsonPath('data.fiscalization.error_message', 'Totals do not match.');

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent);
    });
});

describe('POST /api/invoices/{invoice}/fiscalize', function (): void {
    it('returns 404 for an unknown invoice', function (): void {
        $this->postJson('/api/invoices/9999/fiscalize')->assertNotFound();
    });

    it('returns 409 when the latest fiscal message is already accepted', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(fiscAcceptedEnvelope(), 200),
        ]);

        $invoice = queueOutboundInvoice();
        $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent'])->assertOk();

        $this->postJson("/api/invoices/{$invoice->id}/fiscalize")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Fiscal message is already accepted.');
    });

    it('returns 422 with fiscalization_error body when the service rejects on retry', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(fiscFaultEnvelope('XSD_INVALID', 'Bad shape.'), 400),
        ]);

        $invoice = queueOutboundInvoice();
        $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent'])->assertOk();

        $this->postJson("/api/invoices/{$invoice->id}/fiscalize")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Fiscalization service rejected the request.')
            ->assertJsonPath('fiscalization_error.code', 'XSD_INVALID')
            ->assertJsonPath('fiscalization_error.message', 'Bad shape.');
    });

    it('transitions an error row back to accepted on a successful retry', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::sequence()
                ->push(fiscFaultEnvelope('MISMATCH_TOTAL', 'Totals do not match.'), 400)
                ->push(fiscAcceptedEnvelope('FIS-RETRY-001', 'pending'), 200),
            'http://cis.test/match*' => Http::response(fiscMatchPayload('pending'), 200),
        ]);

        $invoice = queueOutboundInvoice();
        $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent'])->assertOk();

        expect($invoice->fresh()->latestFiscalMessageFor($invoice->supplier->oib)->state)
            ->toBe(FiscalMessageState::Error);

        $response = $this->postJson("/api/invoices/{$invoice->id}/fiscalize");

        $response->assertOk()
            ->assertJsonPath('data.fiscalization.state', 'accepted')
            ->assertJsonPath('data.fiscalization.service_message_id', 'FIS-RETRY-001')
            ->assertJsonPath('data.fiscalization.error_code', null);

        $message = $invoice->fresh()->latestFiscalMessageFor($invoice->supplier->oib);
        expect($message->state)->toBe(FiscalMessageState::Accepted)
            ->and($message->service_message_id)->toBe('FIS-RETRY-001');
    });
});
