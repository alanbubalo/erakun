<?php

namespace App\Http\Requests;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'direction' => ['sometimes', Rule::enum(InvoiceDirection::class)],
            'supplier_oib' => ['sometimes', 'string', 'digits:11'],
            'buyer_oib' => ['sometimes', 'string', 'digits:11'],
        ];
    }
}
