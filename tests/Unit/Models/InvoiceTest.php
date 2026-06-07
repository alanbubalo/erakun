<?php

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Enums\VatCategory;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Party;
use Illuminate\Support\Carbon;

it('belongs to a supplier', function (): void {
    $supplier = Party::factory()->create();
    $invoice = Invoice::factory()->for($supplier, 'supplier')->create();

    expect($invoice->supplier->id)->toBe($supplier->id);
});

it('belongs to a buyer', function (): void {
    $buyer = Party::factory()->create();
    $invoice = Invoice::factory()->for($buyer, 'buyer')->create();

    expect($invoice->buyer->id)->toBe($buyer->id);
});

it('has many lines', function (): void {
    $invoice = Invoice::factory()->create();
    InvoiceLine::factory(3)->for($invoice)->create();

    expect($invoice->lines)->toHaveCount(3);
});

it('casts status to InvoiceStatus enum', function (): void {
    $invoice = Invoice::factory()->draft()->create();

    $fresh = $invoice->fresh();

    expect($fresh->status)->toBe(InvoiceStatus::Draft)
        ->and($fresh->status)->toBeInstanceOf(InvoiceStatus::class);
});

it('casts direction to InvoiceDirection enum', function (): void {
    $invoice = Invoice::factory()->outbound()->create();

    $fresh = $invoice->fresh();

    expect($fresh->direction)->toBe(InvoiceDirection::Outbound)
        ->and($fresh->direction)->toBeInstanceOf(InvoiceDirection::class);
});

it('casts issue_date to a date', function (): void {
    $invoice = Invoice::factory()->create(['issue_date' => '2026-01-15']);

    $fresh = $invoice->fresh();

    expect($fresh->issue_date)->toBeInstanceOf(Carbon::class)
        ->and($fresh->issue_date->toDateString())->toBe('2026-01-15');
});

it('reports the supplier OIB for outbound invoices', function (): void {
    $supplier = Party::factory()->create(['oib' => '12345678901']);
    $buyer = Party::factory()->create(['oib' => '98765432109']);
    $invoice = Invoice::factory()
        ->outbound()
        ->for($supplier, 'supplier')
        ->for($buyer, 'buyer')
        ->create();

    expect($invoice->reporterOib())->toBe('12345678901');
});

it('reports the buyer OIB for inbound invoices', function (): void {
    $supplier = Party::factory()->create(['oib' => '12345678901']);
    $buyer = Party::factory()->create(['oib' => '98765432109']);
    $invoice = Invoice::factory()
        ->inbound()
        ->for($supplier, 'supplier')
        ->for($buyer, 'buyer')
        ->create();

    expect($invoice->reporterOib())->toBe('98765432109');
});

it('returns an empty VAT breakdown for an invoice with no lines', function (): void {
    $invoice = Invoice::factory()->create();

    expect($invoice->vatBreakdown())->toBe([]);
});

it('merges lines with the same VAT category and rate into one group', function (): void {
    $invoice = Invoice::factory()->create();
    InvoiceLine::factory()->for($invoice)->create([
        'vat_category' => VatCategory::Standard,
        'vat_rate' => '25.00',
        'line_total' => '100.00',
    ]);
    InvoiceLine::factory()->for($invoice)->create([
        'vat_category' => VatCategory::Standard,
        'vat_rate' => '25.00',
        'line_total' => '50.00',
    ]);

    expect($invoice->vatBreakdown())->toBe([
        ['category' => 'S', 'rate' => '25.00', 'taxable' => '150.00', 'tax' => '37.50'],
    ]);
});

it('splits the VAT breakdown by rate', function (): void {
    $invoice = Invoice::factory()->create();
    InvoiceLine::factory()->for($invoice)->create([
        'vat_category' => VatCategory::Standard,
        'vat_rate' => '25.00',
        'line_total' => '100.00',
    ]);
    InvoiceLine::factory()->for($invoice)->create([
        'vat_category' => VatCategory::Standard,
        'vat_rate' => '5.00',
        'line_total' => '40.00',
    ]);

    expect($invoice->vatBreakdown())->toBe([
        ['category' => 'S', 'rate' => '25.00', 'taxable' => '100.00', 'tax' => '25.00'],
        ['category' => 'S', 'rate' => '5.00', 'taxable' => '40.00', 'tax' => '2.00'],
    ]);
});

it('keeps zero-rated lines in their own group with zero tax', function (): void {
    $invoice = Invoice::factory()->create();
    InvoiceLine::factory()->for($invoice)->create([
        'vat_category' => VatCategory::ZeroRated,
        'vat_rate' => '0.00',
        'line_total' => '200.00',
    ]);

    expect($invoice->vatBreakdown())->toBe([
        ['category' => 'Z', 'rate' => '0.00', 'taxable' => '200.00', 'tax' => '0.00'],
    ]);
});
