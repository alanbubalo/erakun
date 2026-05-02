<?php

namespace App\Actions;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceValidationException;
use App\Models\Invoice;
use App\Models\Taxpayer;
use App\Validation\ValidationIssue;
use App\Validation\ValidationReport;
use Illuminate\Support\Facades\DB;

final class ReceiveInbound
{
    public function execute(ParsedInvoice $parsed, string $rawXml): Invoice
    {
        return DB::transaction(function () use ($parsed, $rawXml) {
            $supplier = Taxpayer::firstOrCreate(
                ['oib' => $parsed->supplier->oib],
                [
                    'name' => $parsed->supplier->name,
                    'is_vat_registered' => $parsed->supplier->isVatRegistered,
                    'address_line' => $parsed->supplier->addressLine,
                    'city' => $parsed->supplier->city,
                    'postcode' => $parsed->supplier->postcode,
                    'country_code' => $parsed->supplier->countryCode,
                    'iban' => $parsed->supplier->iban,
                ],
            );

            $buyer = Taxpayer::where('oib', $parsed->buyer->oib)->first();
            if ($buyer === null) {
                throw new InvoiceValidationException(new ValidationReport([
                    new ValidationIssue(
                        source: 'business',
                        rule: 'buyer-not-registered',
                        severity: 'error',
                        message: "Buyer OIB {$parsed->buyer->oib} is not registered with this intermediary.",
                    ),
                ]));
            }

            $existing = Invoice::where('supplier_id', $supplier->id)
                ->where('buyer_id', $buyer->id)
                ->where('invoice_number', $parsed->invoiceNumber)
                ->first();

            if ($existing !== null) {
                return $existing->load('supplier', 'buyer', 'lines');
            }

            $invoice = Invoice::create([
                'supplier_id' => $supplier->id,
                'buyer_id' => $buyer->id,
                'invoice_number' => $parsed->invoiceNumber,
                'issue_date' => $parsed->issueDate,
                'due_date' => $parsed->dueDate,
                'direction' => InvoiceDirection::Inbound,
                'status' => InvoiceStatus::Received,
                'currency' => $parsed->currency,
                'net_amount' => $parsed->netAmount,
                'tax_amount' => $parsed->taxAmount,
                'total_amount' => $parsed->totalAmount,
                'ubl_xml' => $rawXml,
            ]);

            foreach ($parsed->lines as $line) {
                $invoice->lines()->create([
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'line_total' => $line->lineTotal,
                    'vat_rate' => $line->vatRate,
                    'vat_category' => $line->vatCategory,
                    'unit_code' => $line->unitCode,
                    'kpd_code' => $line->kpdCode,
                ]);
            }

            return $invoice->load('supplier', 'buyer', 'lines');
        });
    }
}
