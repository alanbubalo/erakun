<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Party;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Create a set of parties (mix of VAT registered and not)
        $parties = Party::factory(10)->create();

        // Generate invoices between random pairs of parties
        $parties->each(function (Party $supplier) use ($parties): void {
            $buyers = $parties->where('id', '!=', $supplier->id)->random(random_int(1, 3));

            $buyers->each(function (Party $buyer) use ($supplier): void {
                $invoice = Invoice::factory()
                    ->for($supplier, 'supplier')
                    ->for($buyer, 'buyer')
                    ->create();

                // Each invoice gets 1-5 lines, then update totals
                $lines = InvoiceLine::factory(random_int(1, 5))
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
