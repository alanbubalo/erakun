<?php

use App\Models\Invoice;
use App\Models\Taxpayer;

it('has supplied invoices relationship', function () {
    $taxpayer = Taxpayer::factory()->create();
    $invoice = Invoice::factory()->for($taxpayer, 'supplier')->create();

    expect($taxpayer->suppliedInvoices)->toHaveCount(1)
        ->and($taxpayer->suppliedInvoices->first()->id)->toBe($invoice->id);
});

it('has received invoices relationship', function () {
    $taxpayer = Taxpayer::factory()->create();
    $invoice = Invoice::factory()->for($taxpayer, 'buyer')->create();

    expect($taxpayer->receivedInvoices)->toHaveCount(1)
        ->and($taxpayer->receivedInvoices->first()->id)->toBe($invoice->id);
});

it('casts is_vat_registered to boolean', function () {
    $taxpayer = Taxpayer::factory()->vatRegistered()->create();

    $fresh = $taxpayer->fresh();

    expect($fresh->is_vat_registered)->toBeTrue()->toBeBool();
});

it('generates a valid 11-digit OIB via factory', function () {
    $taxpayer = Taxpayer::factory()->create();

    expect($taxpayer->oib)->toHaveLength(11)
        ->and($taxpayer->oib)->toMatch('/^\d{11}$/');
});
