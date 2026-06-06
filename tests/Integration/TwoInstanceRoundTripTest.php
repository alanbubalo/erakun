<?php

declare(strict_types=1);

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

    // Point instance A at the real CIS and route the buyer's OIB to the peer AP.
    config([
        'services.fiscalization.url' => CisProcess::BASE_URL,
        'services.fiscalization.timeout' => 5,
        'services.as4.timeout' => 5,
        'services.as4.peers' => '11111111119='.PeerProcess::BASE_URL,
    ]);
    app()->forgetInstance(FiscalizationService::class);
    app()->forgetInstance(PeerEndpointResolver::class);
    app()->forgetInstance(As4DeliveryService::class);

    // The receiver rejects invoices for an unregistered buyer; ensure it exists
    // on B (the peer DB is migrated fresh once at boot, so a duplicate POST on a
    // later test simply 422s — harmless).
    PeerProcess::api('POST', '/api/taxpayers', [
        'oib' => '11111111119',
        'name' => 'Tvrtka B d.o.o.',
        'address_line' => 'Ulica 2',
        'city' => 'RIJEKA',
        'postcode' => '51000',
        'is_vat_registered' => true,
    ]);
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

it('flips both sides to mismatch when the inner UBL total is tampered in transit', function (): void {
    // Variant: corrupt the inner UBL cbc:PayableAmount so the buyer side reports
    // a different total than the supplier side, and assert both reporters flip to
    // `mismatch`. Pending a tamper hook — the AS4 path delivers A's signed UBL
    // verbatim, so this needs either a man-in-the-middle re-sign step or a direct
    // POST of a mutated payload to B's /api/invoices/inbound endpoint.
})->todo();
