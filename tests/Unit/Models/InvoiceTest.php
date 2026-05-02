<?php

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Taxpayer;
use Illuminate\Support\Carbon;

it('belongs to a supplier', function (): void {
    $supplier = Taxpayer::factory()->create();
    $invoice = Invoice::factory()->for($supplier, 'supplier')->create();

    expect($invoice->supplier->id)->toBe($supplier->id);
});

it('belongs to a buyer', function (): void {
    $buyer = Taxpayer::factory()->create();
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
