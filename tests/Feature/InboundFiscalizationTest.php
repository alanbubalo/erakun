<?php

declare(strict_types=1);

use App\Actions\InvoiceSigner;
use App\Actions\UblGenerator;
use App\Enums\FiscalMessageState;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Enums\MatchStatus;
use App\Enums\VatCategory;
use App\Fiscalization\FiscalizationService;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Facades\Http;
use Tests\Doubles\InMemoryFiscalizationService;
use Tests\Fixtures\InvoiceFixture;

beforeEach(function (): void {
    config(['services.fiscalization.url' => 'http://cis.test']);
    $this->app->forgetInstance(FiscalizationService::class);
});

/** Generate signed UBL from an in-memory Invoice (which may be tampered). */
function signedUblFor(Invoice $invoice): string
{
    $dom = resolve(UblGenerator::class)->execute($invoice);
    $signed = resolve(InvoiceSigner::class)->execute($dom);

    return $signed->saveXML();
}

/** Build an outbound invoice, drive it to Sent, return the refreshed model. */
function fiscalizedOutbound(): Invoice
{
    $invoice = InvoiceFixture::outbound();
    test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'queued'])->assertOk();
    test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent'])->assertOk();

    return $invoice->fresh()->load('supplier', 'buyer', 'lines');
}

/** Receive an inbound UBL and return the freshly-created inbound invoice. */
function receivedInbound(string $xml): Invoice
{
    postInboundXml($xml)->assertStatus(201);

    $invoice = Invoice::where('direction', InvoiceDirection::Inbound)->latest('id')->first();
    expect($invoice)->not->toBeNull();

    return $invoice->load('supplier', 'buyer', 'lines');
}

describe('inbound Received → Delivered', function (): void {
    it('submits the buyer-side fiscal message and exposes accepted state on the resource', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(fiscAcceptedEnvelope('FIS-BUY-001', 'matched'), 200),
            'http://cis.test/match*' => Http::response(fiscMatchPayload('matched'), 200),
        ]);

        $inbound = receivedInbound(signedUblFor(InvoiceFixture::outbound()));

        $response = test()->patchJson("/api/invoices/{$inbound->id}/status", ['status' => 'delivered']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.fiscalization.state', 'accepted')
            ->assertJsonPath('data.fiscalization.service_message_id', 'FIS-BUY-001')
            ->assertJsonPath('data.fiscalization.match_status', 'matched');

        $message = $inbound->fresh()->latestFiscalMessageFor($inbound->buyer->oib);
        expect($message)->not->toBeNull()
            ->and($message->state)->toBe(FiscalMessageState::Accepted)
            ->and($message->request_xml)->toContain('<FiscalizationRequest')
            ->and($message->request_xml)->toContain('<ds:Signature');
    });

    it('persists an error row and keeps lifecycle as delivered when the service rejects', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::response(fiscFaultEnvelope('XSD_INVALID', 'Bad shape.'), 400),
        ]);

        $inbound = receivedInbound(signedUblFor(InvoiceFixture::outbound()));

        $response = test()->patchJson("/api/invoices/{$inbound->id}/status", ['status' => 'delivered']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.fiscalization.state', 'error')
            ->assertJsonPath('data.fiscalization.error_code', 'XSD_INVALID');

        expect($inbound->fresh()->status)->toBe(InvoiceStatus::Delivered);
    });

    it('recovers via the retry endpoint after a transient service error', function (): void {
        Http::fake([
            'http://cis.test/fiscalize' => Http::sequence()
                ->push(fiscFaultEnvelope('TRANSPORT', 'Service unavailable.'), 503)
                ->push(fiscAcceptedEnvelope('FIS-BUY-RETRY', 'matched'), 200),
            'http://cis.test/match*' => Http::response(fiscMatchPayload('matched'), 200),
        ]);

        $inbound = receivedInbound(signedUblFor(InvoiceFixture::outbound()));

        test()->patchJson("/api/invoices/{$inbound->id}/status", ['status' => 'delivered'])->assertOk();

        expect($inbound->fresh()->latestFiscalMessageFor($inbound->buyer->oib)->state)
            ->toBe(FiscalMessageState::Error);

        test()->postJson("/api/invoices/{$inbound->id}/fiscalize")
            ->assertOk()
            ->assertJsonPath('data.fiscalization.state', 'accepted')
            ->assertJsonPath('data.fiscalization.service_message_id', 'FIS-BUY-RETRY')
            ->assertJsonPath('data.fiscalization.error_code', null);
    });
});

describe('end-to-end round trip (uparivanje)', function (): void {
    beforeEach(function (): void {
        $this->app->instance(FiscalizationService::class, new InMemoryFiscalizationService);
    });

    it('pairs supplier-side and buyer-side submissions into a matched record', function (): void {
        $outbound = fiscalizedOutbound();

        expect($outbound->latestFiscalMessageFor($outbound->supplier->oib)->match_status)
            ->toBe(MatchStatus::Pending);

        $inbound = receivedInbound($outbound->ubl_xml);
        test()->patchJson("/api/invoices/{$inbound->id}/status", ['status' => 'delivered'])->assertOk();

        $supplierRow = $outbound->fresh()->latestFiscalMessageFor($outbound->supplier->oib);
        $buyerRow = $inbound->fresh()->latestFiscalMessageFor($inbound->buyer->oib);

        expect($supplierRow->state)->toBe(FiscalMessageState::Accepted)
            ->and($buyerRow->state)->toBe(FiscalMessageState::Accepted)
            ->and($buyerRow->match_status)->toBe(MatchStatus::Matched);

        $report = resolve(FiscalizationService::class)->lookupMatch(
            $outbound->supplier->oib,
            $outbound->buyer->oib,
            $outbound->invoice_number,
        );

        expect($report->matchStatus)->toBe(MatchStatus::Matched)
            ->and($report->mismatchFields)->toBe([])
            ->and($report->supplierSubmission)->not->toBeNull()
            ->and($report->buyerSubmission)->not->toBeNull();
    });

    it('live-refreshes the outbound resource from pending to matched after the buyer-side fiscalizes', function (): void {
        $outbound = fiscalizedOutbound();

        test()->getJson("/api/invoices/{$outbound->id}")
            ->assertOk()
            ->assertJsonPath('data.fiscalization.match_status', 'pending');

        $inbound = receivedInbound($outbound->ubl_xml);
        test()->patchJson("/api/invoices/{$inbound->id}/status", ['status' => 'delivered'])->assertOk();

        test()->getJson("/api/invoices/{$outbound->id}")
            ->assertOk()
            ->assertJsonPath('data.fiscalization.match_status', 'matched');

        // Persisted snapshot is unchanged — only the resource re-fetched live.
        expect($outbound->fresh()->latestFiscalMessageFor($outbound->supplier->oib)->match_status)
            ->toBe(MatchStatus::Pending);
    });

    it('flags a mismatch when the inbound UBL carries diverging totals', function (): void {
        $outbound = fiscalizedOutbound();

        $tampered = tamperedInboundUbl($outbound);
        $inbound = receivedInbound($tampered);
        test()->patchJson("/api/invoices/{$inbound->id}/status", ['status' => 'delivered'])->assertOk();

        $buyerRow = $inbound->fresh()->latestFiscalMessageFor($inbound->buyer->oib);
        expect($buyerRow->state)->toBe(FiscalMessageState::Accepted)
            ->and($buyerRow->match_status)->toBe(MatchStatus::Mismatch);

        $report = resolve(FiscalizationService::class)->lookupMatch(
            $outbound->supplier->oib,
            $outbound->buyer->oib,
            $outbound->invoice_number,
        );

        expect($report->matchStatus)->toBe(MatchStatus::Mismatch)
            ->and($report->mismatchFields)->toContain('net_amount')
            ->and($report->mismatchFields)->toContain('tax_amount')
            ->and($report->mismatchFields)->toContain('total_amount');

        test()->getJson("/api/invoices/{$outbound->id}")
            ->assertJsonPath('data.fiscalization.match_status', 'mismatch');
    });
});

/** Produce a signed UBL for the same (supplier, buyer, invoice_number) but with different totals. */
function tamperedInboundUbl(Invoice $outbound): string
{
    $tampered = $outbound->replicate(['ubl_xml_path']);
    $tampered->net_amount = '200.00';
    $tampered->tax_amount = '50.00';
    $tampered->total_amount = '250.00';

    $tamperedLine = new InvoiceLine([
        'description' => 'Proizvod',
        'quantity' => '2.000',
        'unit_price' => '100.00',
        'line_total' => '200.00',
        'vat_rate' => '25.00',
        'vat_category' => VatCategory::Standard,
        'unit_code' => 'H87',
        'kpd_code' => '622020',
    ]);

    $tampered->setRelation('supplier', $outbound->supplier);
    $tampered->setRelation('buyer', $outbound->buyer);
    $tampered->setRelation('lines', collect([$tamperedLine]));

    return signedUblFor($tampered);
}
