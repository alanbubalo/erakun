<?php

use App\Actions\InvoiceSigner;
use App\Actions\UblGenerator;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Taxpayer;
use Tests\Fixtures\InvoiceFixture;

function buildSignedInboundXml(): string
{
    $invoice = InvoiceFixture::outbound();
    $signed = resolve(InvoiceSigner::class)->execute(resolve(UblGenerator::class)->execute($invoice));
    $xml = $signed->saveXML();

    Invoice::query()->delete();
    Taxpayer::where('oib', $invoice->supplier->oib)->delete();

    return $xml;
}

function postInboundXml(string $xml)
{
    return test()->call(
        'POST',
        '/api/invoices/inbound',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/xml', 'HTTP_ACCEPT' => 'application/json'],
        $xml,
    );
}

it('persists a fresh inbound invoice and auto-creates the supplier (201)', function (): void {
    $xml = buildSignedInboundXml();

    $response = postInboundXml($xml);

    $response->assertStatus(201)
        ->assertJsonPath('data.invoice_number', 'RN-2026-00001')
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.direction', 'inbound')
        ->assertJsonPath('data.supplier.oib', '22222222226')
        ->assertJsonPath('data.buyer.oib', '11111111119');

    $invoice = Invoice::firstWhere('invoice_number', 'RN-2026-00001');
    expect($invoice)->not->toBeNull();
    expect($invoice->direction)->toBe(InvoiceDirection::Inbound);
    expect($invoice->status)->toBe(InvoiceStatus::Received);
    expect($invoice->ubl_xml)->toBe($xml);
    expect($invoice->lines)->toHaveCount(1);

    expect(Taxpayer::where('oib', '22222222226')->exists())->toBeTrue();
});

it('returns 200 on idempotent re-receipt without duplicating the invoice', function (): void {
    $xml = buildSignedInboundXml();

    postInboundXml($xml)->assertStatus(201);
    $second = postInboundXml($xml);

    $second->assertStatus(200)
        ->assertJsonPath('data.invoice_number', 'RN-2026-00001');

    expect(Invoice::where('invoice_number', 'RN-2026-00001')->count())->toBe(1);
});

it('rejects inbound XML when buyer OIB is unknown (422)', function (): void {
    $xml = buildSignedInboundXml();
    Taxpayer::where('oib', '11111111119')->delete();

    $response = postInboundXml($xml);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Invoice failed UBL/EN 16931 validation.')
        ->assertJsonStructure(['validation_report' => [['source', 'rule', 'severity', 'message', 'location']]])
        ->assertJsonPath('validation_report.0.rule', 'buyer-not-registered');

    expect(Invoice::where('invoice_number', 'RN-2026-00001')->exists())->toBeFalse();
});

it('rejects malformed XML with a validation_report (422)', function (): void {
    $response = postInboundXml('<not-an-invoice/>');

    $response->assertStatus(422)
        ->assertJsonStructure(['validation_report' => [['source', 'rule', 'severity', 'message', 'location']]]);

    $report = $response->json('validation_report');
    expect($report)->not->toBeEmpty();
    expect($report[0]['rule'])->toStartWith('XSD-');

    expect(Invoice::query()->exists())->toBeFalse();
});
