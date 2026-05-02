<?php

namespace Database\Factories;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Taxpayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $issueDate = fake()->dateTimeBetween('-6 months');
        $netAmount = fake()->randomFloat(2, 100, 50000);
        $taxAmount = round($netAmount * 0.25, 2);

        return [
            'supplier_id' => Taxpayer::factory(),
            'buyer_id' => Taxpayer::factory(),
            'invoice_number' => fake()->unique()->numerify('EINV-####-####'),
            'issue_date' => $issueDate,
            'due_date' => fake()->dateTimeBetween($issueDate, '+30 days'),
            'status' => fake()->randomElement(InvoiceStatus::cases()),
            'direction' => fake()->randomElement(InvoiceDirection::cases()),
            'currency' => 'EUR',
            'net_amount' => $netAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => round($netAmount + $taxAmount, 2),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => InvoiceStatus::Draft]);
    }

    public function delivered(): static
    {
        return $this->state(['status' => InvoiceStatus::Delivered]);
    }

    public function received(): static
    {
        return $this->state(['status' => InvoiceStatus::Received]);
    }

    public function outbound(): static
    {
        return $this->state(['direction' => InvoiceDirection::Outbound]);
    }

    public function inbound(): static
    {
        return $this->state(['direction' => InvoiceDirection::Inbound]);
    }
}
