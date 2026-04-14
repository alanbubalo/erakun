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
        return [
            'supplier_id' => Taxpayer::factory(),
            'buyer_id' => Taxpayer::factory(),
            'invoice_number' => fake()->unique()->numerify('EINV-####-####'),
            'issue_date' => fake()->dateTimeBetween('-6 months'),
            'status' => fake()->randomElement(InvoiceStatus::cases()),
            'direction' => fake()->randomElement(InvoiceDirection::cases()),
            'total_amount' => fake()->randomFloat(2, 100, 50000),
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

    public function outbound(): static
    {
        return $this->state(['direction' => InvoiceDirection::Outbound]);
    }

    public function inbound(): static
    {
        return $this->state(['direction' => InvoiceDirection::Inbound]);
    }
}
