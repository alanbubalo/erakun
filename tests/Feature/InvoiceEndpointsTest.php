<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Taxpayer;

it('creates an invoice with lines', function () {
    $supplier = Taxpayer::factory()->create(['oib' => '12345678903']);
    $buyer = Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', [
        'supplier_oib' => '12345678903',
        'buyer_oib' => '98765432106',
        'invoice_number' => 'RN-2026-00042',
        'issue_date' => '2026-04-14',
        'direction' => 'outbound',
        'lines' => [
            ['description' => 'Šišanje i pranje kose', 'quantity' => 1, 'unit_price' => 25.00],
            ['description' => 'Farbanje kose', 'quantity' => 1, 'unit_price' => 60.00],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'invoice_number' => 'RN-2026-00042',
                'supplier' => ['oib' => '12345678903', 'name' => $supplier->name],
                'buyer' => ['oib' => '98765432106', 'name' => $buyer->name],
                'issue_date' => '2026-04-14',
                'status' => 'draft',
                'direction' => 'outbound',
                'total_amount' => '85.00',
            ],
        ]);

    $response->assertJsonCount(2, 'data.lines');
    $this->assertDatabaseCount('invoice_lines', 2);
});

it('computes line totals and total amount', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', [
        'supplier_oib' => '12345678903',
        'buyer_oib' => '98765432106',
        'invoice_number' => 'RN-001',
        'issue_date' => '2026-04-14',
        'direction' => 'outbound',
        'lines' => [
            ['description' => 'Item A', 'quantity' => 2.5, 'unit_price' => 10.00],
            ['description' => 'Item B', 'quantity' => 3, 'unit_price' => 20.00],
        ],
    ]);

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['lines'][0]['line_total'])->toBe('25.00')
        ->and($data['lines'][1]['line_total'])->toBe('60.00')
        ->and($data['total_amount'])->toBe('85.00');
});

it('rejects invoice with unknown supplier oib', function () {
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', [
        'supplier_oib' => '55555555551',
        'buyer_oib' => '98765432106',
        'invoice_number' => 'RN-001',
        'issue_date' => '2026-04-14',
        'direction' => 'outbound',
        'lines' => [
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 10],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('supplier_oib');
});

it('rejects invoice with no lines', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', [
        'supplier_oib' => '12345678903',
        'buyer_oib' => '98765432106',
        'invoice_number' => 'RN-001',
        'issue_date' => '2026-04-14',
        'direction' => 'outbound',
        'lines' => [],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('lines');
});

it('rejects line with non-positive quantity', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', [
        'supplier_oib' => '12345678903',
        'buyer_oib' => '98765432106',
        'invoice_number' => 'RN-001',
        'issue_date' => '2026-04-14',
        'direction' => 'outbound',
        'lines' => [
            ['description' => 'Item', 'quantity' => 0, 'unit_price' => 10],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.quantity');
});

it('lists all invoices', function () {
    $invoice = Invoice::factory()->draft()->create();
    InvoiceLine::factory(2)->for($invoice)->create();

    $response = $this->getJson('/api/invoices');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters invoices by status', function () {
    Invoice::factory()->draft()->create();
    Invoice::factory()->create(['status' => InvoiceStatus::Queued]);

    $response = $this->getJson('/api/invoices?status=draft');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
    expect($response->json('data.0.status'))->toBe('draft');
});

it('filters invoices by direction', function () {
    Invoice::factory()->outbound()->create();
    Invoice::factory()->inbound()->create();

    $response = $this->getJson('/api/invoices?direction=outbound');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
    expect($response->json('data.0.direction'))->toBe('outbound');
});

it('filters invoices by supplier oib', function () {
    $supplier = Taxpayer::factory()->create();
    Invoice::factory()->for($supplier, 'supplier')->create();
    Invoice::factory()->create();

    $response = $this->getJson("/api/invoices?supplier_oib={$supplier->oib}");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters invoices by buyer oib', function () {
    $buyer = Taxpayer::factory()->create();
    Invoice::factory()->for($buyer, 'buyer')->create();
    Invoice::factory()->create();

    $response = $this->getJson("/api/invoices?buyer_oib={$buyer->oib}");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows a single invoice with lines', function () {
    $invoice = Invoice::factory()->draft()->create();
    InvoiceLine::factory(2)->for($invoice)->create();

    $response = $this->getJson("/api/invoices/{$invoice->id}");

    $response->assertOk()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.id', $invoice->id);
});

it('returns 404 for unknown invoice', function () {
    $response = $this->getJson('/api/invoices/999');

    $response->assertNotFound();
});

it('transitions draft to queued', function () {
    $invoice = Invoice::factory()->draft()->create();

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'queued',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'queued');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Queued);
});

it('transitions queued to sent', function () {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Queued]);

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'sent',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'sent');
});

it('transitions sent to delivered', function () {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Sent]);

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'delivered',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'delivered');
});

it('transitions sent to rejected', function () {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Sent]);

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'rejected',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('rejects invalid status transition draft to delivered', function () {
    $invoice = Invoice::factory()->draft()->create();

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'delivered',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Cannot transition from draft to delivered.',
        ]);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

it('rejects transition from terminal state', function () {
    $invoice = Invoice::factory()->delivered()->create();

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'queued',
    ]);

    $response->assertStatus(422);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Delivered);
});

it('rejects invalid status value', function () {
    $invoice = Invoice::factory()->draft()->create();

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'nonexistent',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('status');
});
