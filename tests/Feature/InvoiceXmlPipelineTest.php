<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Tests\Fixtures\InvoiceFixture;

it('persists signed UBL XML when transitioning Draft to Queued (outbound)', function (): void {
    $invoice = InvoiceFixture::outbound();

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'queued',
    ]);

    $response->assertOk()->assertJsonPath('data.status', 'queued');

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::Queued);
    expect($fresh->ubl_xml)->toBeString()->toContain('<cbc:ID>RN-2026-00001</cbc:ID>');
    expect($fresh->ubl_xml)->toContain('<ds:Signature');
});

it('blocks Draft to Queued and returns validation_report when generated XML is invalid', function (): void {
    $invoice = InvoiceFixture::outbound();
    Invoice::query()->whereKey($invoice->id)->update(['net_amount' => '999.00']);

    $response = $this->patchJson("/api/invoices/{$invoice->id}/status", [
        'status' => 'queued',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Invoice failed UBL/EN 16931 validation.')
        ->assertJsonStructure(['validation_report' => [['source', 'rule', 'severity', 'message', 'location']]]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->fresh()->ubl_xml)->toBeNull();
});

it('returns persisted XML on GET /xml for queued invoice', function (): void {
    $invoice = InvoiceFixture::outbound();
    $this->patchJson("/api/invoices/{$invoice->id}/status", ['status' => 'queued'])->assertOk();

    $response = $this->get("/api/invoices/{$invoice->id}/xml");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/xml');
    expect($response->getContent())->toBe($invoice->fresh()->ubl_xml);
});

it('returns generated preview on GET /xml for Draft outbound invoice', function (): void {
    $invoice = InvoiceFixture::outbound();

    $response = $this->get("/api/invoices/{$invoice->id}/xml");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/xml');
    expect($response->getContent())->toContain('<cbc:ID>RN-2026-00001</cbc:ID>');

    expect($invoice->fresh()->ubl_xml)->toBeNull();
});

it('returns 404 on GET /xml for an unknown invoice', function (): void {
    $this->get('/api/invoices/9999/xml')->assertNotFound();
});
