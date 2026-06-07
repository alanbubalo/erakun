<?php

use App\Models\Invoice;
use App\Models\Party;

it('has supplied invoices relationship', function (): void {
    $party = Party::factory()->create();
    $invoice = Invoice::factory()->for($party, 'supplier')->create();

    expect($party->suppliedInvoices)->toHaveCount(1)
        ->and($party->suppliedInvoices->first()->id)->toBe($invoice->id);
});

it('has received invoices relationship', function (): void {
    $party = Party::factory()->create();
    $invoice = Invoice::factory()->for($party, 'buyer')->create();

    expect($party->receivedInvoices)->toHaveCount(1)
        ->and($party->receivedInvoices->first()->id)->toBe($invoice->id);
});

it('casts is_vat_registered to boolean', function (): void {
    $party = Party::factory()->vatRegistered()->create();

    $fresh = $party->fresh();

    expect($fresh->is_vat_registered)->toBeTrue()->toBeBool();
});

it('generates a valid 11-digit OIB via factory', function (): void {
    $party = Party::factory()->create();

    expect($party->oib)->toHaveLength(11)
        ->and($party->oib)->toMatch('/^\d{11}$/');
});
