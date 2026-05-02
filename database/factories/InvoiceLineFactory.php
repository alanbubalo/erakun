<?php

namespace Database\Factories;

use App\Enums\VatCategory;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 100);
        $unitPrice = fake()->randomFloat(2, 10, 5000);
        $lineTotal = round($quantity * $unitPrice, 2);
        $vatRate = fake()->randomElement([25.00, 13.00, 5.00, 0.00]);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'vat_rate' => $vatRate,
            'vat_category' => $vatRate > 0 ? VatCategory::Standard : VatCategory::ZeroRated,
            'unit_code' => 'H87',
            'kpd_code' => fake()->numerify('######'),
        ];
    }
}
