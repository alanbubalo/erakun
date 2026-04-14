<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxpayerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'oib' => ['required', 'string', 'digits:11', 'unique:taxpayers,oib'],
            'name' => ['required', 'string', 'max:255'],
            'is_vat_registered' => ['sometimes', 'boolean'],
        ];
    }
}
