<?php

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Taxpayer;
use Illuminate\Support\Facades\DB;

class CreateInvoice
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $supplier = Taxpayer::where('oib', $data['supplier_oib'])->firstOrFail();
            $buyer = Taxpayer::where('oib', $data['buyer_oib'])->firstOrFail();

            $lines = [];
            $totalAmount = '0.00';

            foreach ($data['lines'] as $line) {
                $lineTotal = bcmul((string) $line['quantity'], (string) $line['unit_price'], 2);
                $totalAmount = bcadd($totalAmount, $lineTotal, 2);

                $lines[] = [
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $lineTotal,
                ];
            }

            $invoice = Invoice::create([
                'supplier_id' => $supplier->id,
                'buyer_id' => $buyer->id,
                'invoice_number' => $data['invoice_number'],
                'issue_date' => $data['issue_date'],
                'direction' => $data['direction'],
                'status' => InvoiceStatus::Draft,
                'total_amount' => $totalAmount,
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            return $invoice->load('supplier', 'buyer', 'lines');
        });
    }
}
