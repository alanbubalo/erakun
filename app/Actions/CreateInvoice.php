<?php

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Party;
use Illuminate\Support\Facades\DB;

class CreateInvoice
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $supplier = Party::where('oib', $data['supplier_oib'])->firstOrFail();
            $buyer = Party::where('oib', $data['buyer_oib'])->firstOrFail();

            $lines = [];
            $netAmount = '0.00';
            $taxAmount = '0.00';

            foreach ($data['lines'] as $line) {
                $lineTotal = bcmul((string) $line['quantity'], (string) $line['unit_price'], 2);
                $lineTax = bcdiv(bcmul($lineTotal, (string) $line['vat_rate'], 4), '100', 2);

                $netAmount = bcadd($netAmount, $lineTotal, 2);
                $taxAmount = bcadd($taxAmount, $lineTax, 2);

                $lines[] = [
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $lineTotal,
                    'vat_rate' => $line['vat_rate'],
                    'vat_category' => $line['vat_category'],
                    'unit_code' => $line['unit_code'] ?? 'H87',
                    'kpd_code' => $line['kpd_code'] ?? null,
                ];
            }

            $totalAmount = bcadd($netAmount, $taxAmount, 2);

            $invoice = new Invoice([
                'supplier_id' => $supplier->id,
                'buyer_id' => $buyer->id,
                'invoice_number' => $data['invoice_number'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'direction' => $data['direction'],
                'currency' => $data['currency'] ?? 'EUR',
                'net_amount' => $netAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
            ]);

            // status is intentionally not mass-assignable; Draft is the birth state.
            $invoice->status = InvoiceStatus::Draft;
            $invoice->save();

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            return $invoice->load('supplier', 'buyer', 'lines');
        });
    }
}
