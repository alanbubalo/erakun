<?php

declare(strict_types=1);

use App\As4\As4DeliveryService;
use App\As4\PeerEndpointResolver;
use App\Enums\As4MessageDirection;
use App\Enums\As4MessageState;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\InvoiceFixture;

beforeEach(function (): void {
    config([
        'services.as4.default_peer_url' => 'http://peer.test',
        'services.as4.peers' => '11111111119=http://peer.test',
    ]);
    $this->app->forgetInstance(As4DeliveryService::class);
    $this->app->forgetInstance(PeerEndpointResolver::class);
});

function queueOutboundDeliveryInvoice(): Invoice
{
    $invoice = InvoiceFixture::outbound();
    test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'queued'])->assertOk();

    return $invoice->fresh();
}

function fakeAs4Receipt(): void
{
    Http::fake([
        'http://peer.test/api/as4/inbox' => function (Request $request) {
            preg_match('/<eb:MessageId>([^<]+)<\/eb:MessageId>/', $request->body(), $m);

            return Http::response(as4ReceiptEnvelope($m[1] ?? 'unknown'), 200, [
                'Content-Type' => 'application/soap+xml; charset=utf-8',
            ]);
        },
    ]);
}

function fakeAs4Error(string $errorCode, string $description): void
{
    Http::fake([
        'http://peer.test/api/as4/inbox' => function (Request $request) use ($errorCode, $description) {
            preg_match('/<eb:MessageId>([^<]+)<\/eb:MessageId>/', $request->body(), $m);

            return Http::response(as4ErrorEnvelope($m[1] ?? 'unknown', $errorCode, $description), 400, [
                'Content-Type' => 'application/soap+xml; charset=utf-8',
            ]);
        },
    ]);
}

describe('outbound Queued → Sent', function (): void {
    it('delivers the UBL to the peer and exposes acknowledged state on the resource', function (): void {
        fakeAs4Receipt();

        $invoice = queueOutboundDeliveryInvoice();

        $response = $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.delivery.state', 'acknowledged')
            ->assertJsonPath('data.delivery.peer_message_id', 'peer-receipt-001@erakun')
            ->assertJsonPath('data.delivery.error', null);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent);

        $message = $invoice->fresh()->latestAs4MessageFor(As4MessageDirection::Outbound);
        expect($message)->not->toBeNull()
            ->and($message->state)->toBe(As4MessageState::Acknowledged)
            ->and($message->from_oib)->toBe($invoice->supplier->oib)
            ->and($message->to_oib)->toBe($invoice->buyer->oib)
            ->and($message->envelope_xml)->toContain('<eb:UserMessage>')
            ->and($message->envelope_xml)->toContain('<ds:Signature')
            ->and($message->receipt_xml)->toContain('<eb:Receipt/>');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://peer.test/api/as4/inbox'
            && str_contains($request->body(), '<eb:UserMessage>'));
    });

    it('persists an error row and keeps lifecycle as sent when the peer rejects', function (): void {
        fakeAs4Error('EBMS:0004', 'UBL payload failed HR-CIUS schema validation.');

        $invoice = queueOutboundDeliveryInvoice();

        $response = $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.delivery.state', 'error')
            ->assertJsonPath('data.delivery.error.code', 'EBMS:0004')
            ->assertJsonPath('data.delivery.error.message', 'UBL payload failed HR-CIUS schema validation.');

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent);
    });

    it('persists an error row when the peer is unreachable', function (): void {
        Http::fake(fn (): never => throw new ConnectionException('Connection refused'));

        $invoice = queueOutboundDeliveryInvoice();

        $response = $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.delivery.state', 'error')
            ->assertJsonPath('data.delivery.error.code', 'TRANSPORT');
    });
});

describe('POST /api/invoices/{invoice}/deliver', function (): void {
    it('returns 404 for an unknown invoice', function (): void {
        $this->postJson('/api/invoices/9999/deliver')->assertNotFound();
    });

    it('advances a queued invoice to delivered on a successful acknowledgement', function (): void {
        fakeAs4Receipt();

        $invoice = queueOutboundDeliveryInvoice();
        expect($invoice->status)->toBe(InvoiceStatus::Queued);

        $response = $this->postJson("/api/invoices/{$invoice->id}/deliver");

        $response->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.delivery.state', 'acknowledged');

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Delivered);
    });

    it('returns 409 when the latest delivery is already acknowledged', function (): void {
        fakeAs4Receipt();

        $invoice = queueOutboundDeliveryInvoice();
        $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent'])->assertOk();

        $this->postJson("/api/invoices/{$invoice->id}/deliver")
            ->assertStatus(409)
            ->assertJsonPath('message', 'AS4 delivery is already acknowledged.');
    });

    it('returns 422 with delivery_error body when the peer rejects on retry', function (): void {
        fakeAs4Error('EBMS:0009', 'Envelope signature missing.');

        $invoice = queueOutboundDeliveryInvoice();
        $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent'])->assertOk();

        $this->postJson("/api/invoices/{$invoice->id}/deliver")
            ->assertStatus(422)
            ->assertJsonPath('message', 'AS4 peer rejected the delivery.')
            ->assertJsonPath('delivery_error.code', 'EBMS:0009')
            ->assertJsonPath('delivery_error.message', 'Envelope signature missing.');
    });

    it('transitions an error row to acknowledged on a successful retry', function (): void {
        $callCount = 0;
        Http::fake([
            'http://peer.test/api/as4/inbox' => function (Request $request) use (&$callCount) {
                $callCount++;

                if ($callCount === 1) {
                    return Http::response('', 500);
                }

                preg_match('/<eb:MessageId>([^<]+)<\/eb:MessageId>/', $request->body(), $m);

                return Http::response(as4ReceiptEnvelope($m[1] ?? 'unknown'), 200, [
                    'Content-Type' => 'application/soap+xml; charset=utf-8',
                ]);
            },
        ]);

        $invoice = queueOutboundDeliveryInvoice();

        $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent'])->assertOk();

        expect($invoice->fresh()->latestAs4MessageFor(As4MessageDirection::Outbound)->state)
            ->toBe(As4MessageState::Error);

        $response = $this->postJson("/api/invoices/{$invoice->id}/deliver");

        $response->assertOk()
            ->assertJsonPath('data.delivery.state', 'acknowledged')
            ->assertJsonPath('data.delivery.peer_message_id', 'peer-receipt-001@erakun')
            ->assertJsonPath('data.delivery.error', null);

        $message = $invoice->fresh()->latestAs4MessageFor(As4MessageDirection::Outbound);
        expect($message->state)->toBe(As4MessageState::Acknowledged)
            ->and($message->error_code)->toBeNull();

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Delivered);
    });
});
