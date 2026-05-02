<?php

namespace Tests\Fixtures;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Enums\VatCategory;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Taxpayer;

class InvoiceFixture
{
    public static function outbound(): Invoice
    {
        $supplier = Taxpayer::factory()->create([
            'oib' => '22222222226',
            'name' => 'TVRTKA A d.o.o.',
            'is_vat_registered' => true,
            'address_line' => 'Ulica 1',
            'city' => 'ZAGREB',
            'postcode' => '10000',
            'country_code' => 'HR',
            'iban' => 'HR1723600001101234565',
        ]);

        $buyer = Taxpayer::factory()->create([
            'oib' => '11111111119',
            'name' => 'Tvrtka B d.o.o.',
            'is_vat_registered' => true,
            'address_line' => 'Ulica 2',
            'city' => 'RIJEKA',
            'postcode' => '51000',
            'country_code' => 'HR',
        ]);

        $invoice = Invoice::factory()->create([
            'supplier_id' => $supplier->id,
            'buyer_id' => $buyer->id,
            'invoice_number' => 'RN-2026-00001',
            'issue_date' => '2026-04-15',
            'due_date' => '2026-05-15',
            'status' => InvoiceStatus::Draft,
            'direction' => InvoiceDirection::Outbound,
            'currency' => 'EUR',
            'net_amount' => '100.00',
            'tax_amount' => '25.00',
            'total_amount' => '125.00',
        ]);

        InvoiceLine::factory()->for($invoice)->create([
            'description' => 'Proizvod',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'line_total' => '100.00',
            'vat_rate' => '25.00',
            'vat_category' => VatCategory::Standard,
            'unit_code' => 'H87',
            'kpd_code' => '622020',
        ]);

        return $invoice->load('supplier', 'buyer', 'lines');
    }
}
