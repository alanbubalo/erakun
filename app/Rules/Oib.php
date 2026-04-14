<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Oib implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\d{11}$/', $value)) {
            $fail('The :attribute must be a valid OIB.');

            return;
        }

        $remainder = 10;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder + (int) $value[$i]) % 10 ?: 10;
            $remainder = ($remainder * 2) % 11;
        }

        if ((11 - $remainder) % 10 !== (int) $value[10]) {
            $fail('The :attribute must be a valid OIB.');
        }
    }
}
