<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\As4MessageDirection;
use App\Enums\As4MessageState;
use App\Models\As4Message;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<As4Message>
 */
class As4MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'direction' => As4MessageDirection::Outbound,
            'message_id' => Str::uuid()->toString().'@erakun',
            'from_oib' => fake()->numerify('###########'),
            'to_oib' => fake()->numerify('###########'),
            'state' => As4MessageState::Queued,
            'envelope_xml' => '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"/>',
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => As4MessageDirection::Inbound,
            'state' => As4MessageState::Received,
            'received_at' => now(),
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'state' => As4MessageState::Sent,
            'sent_at' => now(),
        ]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (): array => [
            'state' => As4MessageState::Acknowledged,
            'ref_to_message_id' => Str::uuid()->toString().'@erakun',
            'sent_at' => now(),
            'acknowledged_at' => now(),
            'receipt_xml' => '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"/>',
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'direction' => As4MessageDirection::Inbound,
            'state' => As4MessageState::Delivered,
            'received_at' => now(),
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (): array => [
            'state' => As4MessageState::Error,
            'error_code' => 'EBMS:0004',
            'error_message' => fake()->sentence(),
        ]);
    }
}
