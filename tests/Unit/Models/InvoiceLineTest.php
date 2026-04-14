<?php

use App\Models\Invoice;
use App\Models\InvoiceLine;

it('belongs to an invoice', function () {
    $invoice = Invoice::factory()->create();
    $line = InvoiceLine::factory()->for($invoice)->create();

    expect($line->invoice->id)->toBe($invoice->id);
});

it('casts decimal fields correctly', function () {
    $line = InvoiceLine::factory()->create([
        'quantity' => 2.500,
        'unit_price' => 100.00,
        'line_total' => 250.00,
    ]);

    $fresh = $line->fresh();

    expect($fresh->quantity)->toBe('2.500')
        ->and($fresh->unit_price)->toBe('100.00')
        ->and($fresh->line_total)->toBe('250.00');
});

it('is deleted when its invoice is deleted', function () {
    $invoice = Invoice::factory()->create();
    InvoiceLine::factory(2)->for($invoice)->create();

    $invoice->delete();

    expect(InvoiceLine::where('invoice_id', $invoice->id)->count())->toBe(0);
});
