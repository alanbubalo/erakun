<?php

declare(strict_types=1);

use App\As4\AmsClient;
use App\As4\As4DeliveryService;
use App\As4\PeerEndpointResolver;
use App\Enums\InvoiceStatus;
use App\Fiscalization\FiscalizationService;
use Tests\Fixtures\InvoiceFixture;
use Tests\Integration\CisProcess;
use Tests\Integration\PeerProcess;

/**
 * Phase 5d — the full 4-corner round trip over real HTTP.
 *
 *   instance A (this test process)  --AS4-->  instance B (PeerProcess :8002)
 *            |                                          |
 *            +--------- fiscalize ---> CIS <------------+
 *                       (erakun-porezna, CisProcess :8766)
 *
 * Instance A is the test process itself (in-memory DB, Laravel test client);
 * its outbound AS4 and fiscalization travel over real HTTP. Instance B is a
 * booted second copy of this app that receives the invoice and fiscalizes the
 * buyer side to the same CIS, so both reporters converge on `matched`.
 */
beforeAll(function (): void {
    CisProcess::boot();
    PeerProcess::boot();
});

afterAll(function (): void {
    PeerProcess::stop();
    CisProcess::stop();
});

beforeEach(function (): void {
    if (! CisProcess::isReady()) {
        test()->markTestSkipped(skipReason('erakun-porezna CIS mock', CisProcess::BASE_URL, CisProcess::bootLog()));
    }

    if (! PeerProcess::isReady()) {
        test()->markTestSkipped(skipReason('eRakun peer instance', PeerProcess::BASE_URL, PeerProcess::bootLog()));
    }

    CisProcess::reset();

    // Point instance A at the real CIS and at the AMS (co-located in porezna).
    // No static peer map and no default peer: the buyer's AP must be found
    // purely through discovery — AMS → MPS → AS4 endpoint.
    config([
        'services.fiscalization.url' => CisProcess::BASE_URL,
        'services.fiscalization.timeout' => 5,
        'services.as4.timeout' => 5,
        'services.as4.peers' => '',
        'services.as4.default_peer_url' => '',
        'services.ams.base_url' => CisProcess::BASE_URL,
    ]);
    app()->forgetInstance(FiscalizationService::class);
    app()->forgetInstance(AmsClient::class);
    app()->forgetInstance(PeerEndpointResolver::class);
    app()->forgetInstance(As4DeliveryService::class);

    // The receiver rejects invoices for an unregistered buyer; ensure it exists
    // on B (the peer DB is migrated fresh once at boot, so a duplicate POST on a
    // later test simply 422s — harmless).
    PeerProcess::api('POST', '/api/parties', [
        'oib' => '11111111119',
        'name' => 'Tvrtka B d.o.o.',
        'address_line' => 'Ulica 2',
        'city' => 'RIJEKA',
        'postcode' => '51000',
        'is_vat_registered' => true,
    ]);

    // Publish the buyer in the AMS as served by B's MPS. (Onboarding on B also
    // self-registers, but reset wipes the directory each run while the duplicate
    // POST above 422s — so register here to keep discovery deterministic.)
    CisProcess::registerParticipant('11111111119', PeerProcess::BASE_URL);
});

function skipReason(string $label, string $baseUrl, string $log): string
{
    $reason = "{$label} is not reachable on {$baseUrl}";

    return $log === '' ? $reason : $reason."\n--- subprocess output ---\n".$log;
}

it('round-trips an invoice from sender AP to receiver AP and matches on both sides', function (): void {
    // Corner 1→2: a supplier on A issues an invoice to the buyer.
    $invoice = InvoiceFixture::outbound();

    test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'queued'])->assertOk();

    // Queued → Sent fans out: AS4 delivery to B and fiscalization of the
    // supplier side to the CIS, both over real HTTP.
    $sent = test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);

    $sent->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.delivery.state', 'acknowledged')
        ->assertJsonPath('data.fiscalization.state', 'accepted')
        ->assertJsonPath('data.fiscalization.match_status', 'pending');

    // Corner 3: B has received the invoice over AS4 as a new inbound document.
    [$listStatus, $list] = PeerProcess::api('GET', '/api/invoices');
    expect($listStatus)->toBe(200)
        ->and($list['data'])->toHaveCount(1);

    $peerInvoice = $list['data'][0];
    expect($peerInvoice['direction'])->toBe('inbound')
        ->and($peerInvoice['status'])->toBe('received')
        ->and($peerInvoice['invoice_number'])->toBe($invoice->invoice_number);

    // Corner 3→4: the buyer's AP marks the invoice delivered, which fiscalizes
    // the buyer side to the same CIS.
    [$patchStatus, $delivered] = PeerProcess::api(
        'PATCH',
        "/api/invoices/{$peerInvoice['id']}/status",
        ['status' => 'delivered'],
    );
    expect($patchStatus)->toBe(200)
        ->and($delivered['data']['status'])->toBe('delivered')
        ->and($delivered['data']['fiscalization']['state'])->toBe('accepted');

    // Both submissions now sit in the CIS, so a live match lookup on either side
    // agrees: matched.
    expect($delivered['data']['fiscalization']['match_status'])->toBe('matched');

    test()->getJson("/api/invoices/{$invoice->id}")
        ->assertOk()
        ->assertJsonPath('data.fiscalization.match_status', 'matched');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Sent);
});

it('flips both sides to mismatch when the supplier and buyer report different totals', function (): void {
    // The receiver validates the inbound UBL against HR-CIUS, so a single-field
    // tamper of the payload would just be rejected (BR-CO-* totals rules). Instead
    // we drive the two corners apart at the reporting layer: A's UBL is generated
    // at Queued (100/25/125, the figures B will receive and report), then A's own
    // record is rewritten before Sent so the *supplier* fiscalizes 200/50/250.
    // CIS holds two submissions for the same invoice with different totals.
    $invoice = InvoiceFixture::outbound();
    $invoice->update(['invoice_number' => 'RN-2026-09999']);

    test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'queued'])->assertOk();

    $invoice->lines()->update(['unit_price' => '200.00', 'line_total' => '200.00']);
    $invoice->update(['net_amount' => '200.00', 'tax_amount' => '50.00', 'total_amount' => '250.00']);

    $sent = test()->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'sent']);
    $sent->assertOk()
        ->assertJsonPath('data.delivery.state', 'acknowledged')
        ->assertJsonPath('data.fiscalization.state', 'accepted')
        ->assertJsonPath('data.fiscalization.match_status', 'pending');

    // B received the untampered invoice (250 was never in the UBL) and reports it.
    [, $list] = PeerProcess::api('GET', '/api/invoices');
    $peerInvoice = collect($list['data'])->firstWhere('invoice_number', 'RN-2026-09999');
    expect($peerInvoice)->not->toBeNull()
        ->and($peerInvoice['total_amount'])->toBe('125.00');

    [$patchStatus, $delivered] = PeerProcess::api(
        'PATCH',
        "/api/invoices/{$peerInvoice['id']}/status",
        ['status' => 'delivered'],
    );
    expect($patchStatus)->toBe(200)
        ->and($delivered['data']['fiscalization']['state'])->toBe('accepted')
        ->and($delivered['data']['fiscalization']['match_status'])->toBe('mismatch');

    // The supplier side, re-fetched live, agrees: the totals do not reconcile.
    test()->getJson("/api/invoices/{$invoice->id}")
        ->assertOk()
        ->assertJsonPath('data.fiscalization.match_status', 'mismatch');
});
