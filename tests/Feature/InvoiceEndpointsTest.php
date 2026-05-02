<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Taxpayer;

function validInvoicePayload(array $overrides = []): array
{
    return array_merge([
        'supplier_oib' => '12345678903',
        'buyer_oib' => '98765432106',
        'invoice_number' => 'RN-2026-00042',
        'issue_date' => '2026-04-14',
        'direction' => 'outbound',
        'lines' => [
            [
                'description' => 'Šišanje i pranje kose',
                'quantity' => 1,
                'unit_price' => 25.00,
                'vat_rate' => 25.00,
                'vat_category' => 'S',
                'kpd_code' => '960212',
            ],
            [
                'description' => 'Farbanje kose',
                'quantity' => 1,
                'unit_price' => 60.00,
                'vat_rate' => 25.00,
                'vat_category' => 'S',
                'kpd_code' => '960212',
            ],
        ],
    ], $overrides);
}

it('creates an invoice with lines', function () {
    $supplier = Taxpayer::factory()->create(['oib' => '12345678903']);
    $buyer = Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload());

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'invoice_number' => 'RN-2026-00042',
                'supplier' => ['oib' => '12345678903', 'name' => $supplier->name],
                'buyer' => ['oib' => '98765432106', 'name' => $buyer->name],
                'issue_date' => '2026-04-14',
                'status' => 'draft',
                'direction' => 'outbound',
                'currency' => 'EUR',
                'net_amount' => '85.00',
                'tax_amount' => '21.25',
                'total_amount' => '106.25',
            ],
        ]);

    $response->assertJsonCount(2, 'data.lines');
    $this->assertDatabaseCount('invoice_lines', 2);
});

it('computes net, tax, and gross totals from line vat rates', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'invoice_number' => 'RN-001',
        'lines' => [
            ['description' => 'Standard', 'quantity' => 2, 'unit_price' => 100.00, 'vat_rate' => 25.00, 'vat_category' => 'S', 'kpd_code' => '960212'],
            ['description' => 'Reduced', 'quantity' => 1, 'unit_price' => 50.00, 'vat_rate' => 13.00, 'vat_category' => 'S', 'kpd_code' => '960212'],
            ['description' => 'Exempt', 'quantity' => 1, 'unit_price' => 30.00, 'vat_rate' => 0.00, 'vat_category' => 'E', 'kpd_code' => '960212'],
        ],
    ]));

    $response->assertStatus(201);

    $data = $response->json('data');
    expect($data['lines'][0]['line_total'])->toBe('200.00')
        ->and($data['lines'][1]['line_total'])->toBe('50.00')
        ->and($data['lines'][2]['line_total'])->toBe('30.00')
        ->and($data['net_amount'])->toBe('280.00')
        ->and($data['tax_amount'])->toBe('56.50')
        ->and($data['total_amount'])->toBe('336.50');
});

it('total_amount equals net_amount plus tax_amount (true gross)', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload());

    $response->assertStatus(201);
    $data = $response->json('data');

    $sum = bcadd($data['net_amount'], $data['tax_amount'], 2);
    expect($data['total_amount'])->toBe($sum);
});

it('accepts optional currency, due_date, and unit_code', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'currency' => 'USD',
        'due_date' => '2026-05-14',
        'lines' => [
            [
                'description' => 'Item',
                'quantity' => 1,
                'unit_price' => 10.00,
                'vat_rate' => 25.00,
                'vat_category' => 'S',
                'unit_code' => 'KGM',
                'kpd_code' => '960212',
            ],
        ],
    ]));

    $response->assertStatus(201)
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.due_date', '2026-05-14')
        ->assertJsonPath('data.lines.0.unit_code', 'KGM');
});

it('defaults unit_code to H87 when omitted', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload());

    $response->assertStatus(201)
        ->assertJsonPath('data.lines.0.unit_code', 'H87');
});

it('rejects invoice with unknown supplier oib', function () {
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'supplier_oib' => '55555555551',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('supplier_oib');
});

it('rejects invoice with no lines', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload(['lines' => []]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('lines');
});

it('rejects line with non-positive quantity', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'lines' => [
            ['description' => 'Item', 'quantity' => 0, 'unit_price' => 10, 'vat_rate' => 25, 'vat_category' => 'S', 'kpd_code' => '960212'],
        ],
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.quantity');
});

it('rejects line missing vat_rate', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'lines' => [
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'vat_category' => 'S', 'kpd_code' => '960212'],
        ],
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.vat_rate');
});

it('rejects line missing vat_category', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'lines' => [
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'vat_rate' => 25, 'kpd_code' => '960212'],
        ],
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.vat_category');
});

it('rejects line with invalid vat_category', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'lines' => [
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'vat_rate' => 25, 'vat_category' => 'X', 'kpd_code' => '960212'],
        ],
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.vat_category');
});

it('rejects line missing kpd_code', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'lines' => [
            ['description' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'vat_rate' => 25, 'vat_category' => 'S'],
        ],
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.kpd_code');
});

it('rejects due_date before issue_date', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);
    Taxpayer::factory()->create(['oib' => '98765432106']);

    $response = $this->postJson('/api/invoices', validInvoicePayload([
        'due_date' => '2026-04-01',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('due_date');
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

it('transitions received to delivered', function () {
    $invoice = Invoice::factory()->received()->create();

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'delivered',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'delivered');
});

it('transitions received to rejected', function () {
    $invoice = Invoice::factory()->received()->create();

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
