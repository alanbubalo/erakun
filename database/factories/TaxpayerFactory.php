<?php

namespace Database\Factories;

use App\Models\Taxpayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Taxpayer>
 */
class TaxpayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'oib' => $this->generateOib(),
            'name' => fake()->company(),
            'is_vat_registered' => fake()->boolean(70),
            'address_line' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postcode' => fake()->numerify('#####'),
            'country_code' => 'HR',
            'iban' => fake()->iban('HR'),
        ];
    }

    /**
     * Generate a valid Croatian OIB (11-digit number with mod-10 check digit).
     */
    private function generateOib(): string
    {
        $digits = [];
        for ($i = 0; $i < 10; $i++) {
            $digits[] = fake()->numberBetween(0, 9);
        }

        // ISO 7064, MOD 11,10 check digit
        $remainder = 10;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder + $digits[$i]) % 10;
            if ($remainder === 0) {
                $remainder = 10;
            }
            $remainder = ($remainder * 2) % 11;
        }

        $checkDigit = (11 - $remainder) % 10;
        $digits[] = $checkDigit;

        return implode('', $digits);
    }

    public function vatRegistered(): static
    {
        return $this->state(['is_vat_registered' => true]);
    }

    public function notVatRegistered(): static
    {
        return $this->state(['is_vat_registered' => false]);
    }
}
