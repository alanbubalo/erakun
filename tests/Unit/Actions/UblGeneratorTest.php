<?php

use App\Actions\InvoiceSigner;
use App\Actions\UblGenerator;
use App\Validation\UblValidator;
use Tests\Fixtures\InvoiceFixture;

it('generates UBL XML matching the canonical snapshot', function (): void {
    $xml = resolve(UblGenerator::class)->execute(InvoiceFixture::outbound())->saveXML();

    expect($xml)->toMatchSnapshot();
});

it('produces XML that passes UBL/EN 16931/HR-CIUS validation', function (): void {
    $dom = resolve(UblGenerator::class)->execute(InvoiceFixture::outbound());
    $signed = resolve(InvoiceSigner::class)->execute($dom);

    $report = resolve(UblValidator::class)->validate($signed->saveXML());

    expect($report->isValid())->toBeTrue(
        'Validation failed: '.collect($report->issues)
            ->map(fn ($i): string => "[{$i->source}/{$i->rule}] {$i->message}")
            ->implode("\n")
    );
});
