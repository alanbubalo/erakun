<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Taxpayer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Create a set of taxpayers (mix of VAT registered and not)
        $taxpayers = Taxpayer::factory(10)->create();

        // Generate invoices between random pairs of taxpayers
        $taxpayers->each(function (Taxpayer $supplier) use ($taxpayers) {
            $buyers = $taxpayers->where('id', '!=', $supplier->id)->random(rand(1, 3));

            $buyers->each(function (Taxpayer $buyer) use ($supplier) {
                $invoice = Invoice::factory()
                    ->for($supplier, 'supplier')
                    ->for($buyer, 'buyer')
                    ->create();

                // Each invoice gets 1-5 lines, then update totals
                $lines = InvoiceLine::factory(rand(1, 5))
                    ->for($invoice)
                    ->create();

                $netAmount = (string) $lines->sum('line_total');
                $taxAmount = '0.00';
                foreach ($lines as $line) {
                    $taxAmount = bcadd(
                        $taxAmount,
                        bcdiv(bcmul((string) $line->line_total, (string) $line->vat_rate, 4), '100', 2),
                        2
                    );
                }

                $invoice->update([
                    'net_amount' => $netAmount,
                    'tax_amount' => $taxAmount,
                    'total_amount' => bcadd($netAmount, $taxAmount, 2),
                ]);
            });
        });
    }
}
