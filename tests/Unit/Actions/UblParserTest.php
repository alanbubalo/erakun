<?php

use App\Actions\UblGenerator;
use App\Actions\UblParser;
use App\Enums\VatCategory;
use Tests\Fixtures\InvoiceFixture;

it('round-trips a factory invoice through generator and parser', function () {
    $invoice = InvoiceFixture::outbound();
    $xml = app(UblGenerator::class)->execute($invoice)->saveXML();

    $parsed = app(UblParser::class)->parse($xml);

    expect($parsed->invoiceNumber)->toBe($invoice->invoice_number);
    expect($parsed->issueDate)->toBe($invoice->issue_date->format('Y-m-d'));
    expect($parsed->dueDate)->toBe($invoice->due_date->format('Y-m-d'));
    expect($parsed->currency)->toBe($invoice->currency);
    expect($parsed->netAmount)->toBe($invoice->net_amount);
    expect($parsed->taxAmount)->toBe($invoice->tax_amount);
    expect($parsed->totalAmount)->toBe($invoice->total_amount);

    expect($parsed->supplier->oib)->toBe($invoice->supplier->oib);
    expect($parsed->supplier->name)->toBe($invoice->supplier->name);
    expect($parsed->supplier->addressLine)->toBe($invoice->supplier->address_line);
    expect($parsed->supplier->city)->toBe($invoice->supplier->city);
    expect($parsed->supplier->postcode)->toBe($invoice->supplier->postcode);
    expect($parsed->supplier->countryCode)->toBe($invoice->supplier->country_code);
    expect($parsed->supplier->isVatRegistered)->toBe($invoice->supplier->is_vat_registered);
    expect($parsed->supplier->iban)->toBe($invoice->supplier->iban);

    expect($parsed->buyer->oib)->toBe($invoice->buyer->oib);
    expect($parsed->buyer->name)->toBe($invoice->buyer->name);
    expect($parsed->buyer->addressLine)->toBe($invoice->buyer->address_line);
    expect($parsed->buyer->city)->toBe($invoice->buyer->city);
    expect($parsed->buyer->isVatRegistered)->toBe($invoice->buyer->is_vat_registered);

    expect($parsed->lines)->toHaveCount($invoice->lines->count());
    $sourceLine = $invoice->lines->first();
    $parsedLine = $parsed->lines[0];
    expect($parsedLine->description)->toBe($sourceLine->description);
    expect($parsedLine->quantity)->toBe($sourceLine->quantity);
    expect($parsedLine->unitPrice)->toBe($sourceLine->unit_price);
    expect($parsedLine->lineTotal)->toBe($sourceLine->line_total);
    expect($parsedLine->vatRate)->toBe($sourceLine->vat_rate);
    expect($parsedLine->vatCategory)->toBe(VatCategory::Standard);
    expect($parsedLine->unitCode)->toBe($sourceLine->unit_code);
    expect($parsedLine->kpdCode)->toBe($sourceLine->kpd_code);
});

it('parses the canonical valid HR-CIUS fixture', function () {
    $xml = file_get_contents(__DIR__.'/../../Fixtures/ubl/valid-hr-cius.xml');

    $parsed = app(UblParser::class)->parse($xml);

    expect($parsed->invoiceNumber)->toBe('RN-2026-00001');
    expect($parsed->currency)->toBe('EUR');
    expect($parsed->supplier->oib)->toBe('22222222226');
    expect($parsed->buyer->oib)->toBe('11111111119');
    expect($parsed->supplier->iban)->toBe('HR1723600001101234565');
    expect($parsed->lines)->toHaveCount(1);
    expect($parsed->lines[0]->kpdCode)->toBe('622020');
});
