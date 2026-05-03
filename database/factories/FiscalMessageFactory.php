<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FiscalMessageState;
use App\Enums\FiscalMessageType;
use App\Models\FiscalMessage;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalMessage>
 */
class FiscalMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'reporter_oib' => fake()->numerify('###########'),
            'message_type' => FiscalMessageType::Fis,
            'state' => FiscalMessageState::Requested,
            'submitted_at' => now(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'state' => FiscalMessageState::Accepted,
            'service_message_id' => fake()->uuid(),
            'settled_at' => now(),
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (): array => [
            'state' => FiscalMessageState::Error,
            'error_code' => 'V001',
            'error_message' => fake()->sentence(),
            'settled_at' => now(),
        ]);
    }
}
