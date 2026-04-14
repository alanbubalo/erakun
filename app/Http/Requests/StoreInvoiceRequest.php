<?php

namespace App\Http\Requests;

use App\Enums\InvoiceDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_oib' => ['required', 'string', 'digits:11', 'exists:taxpayers,oib'],
            'buyer_oib' => ['required', 'string', 'digits:11', 'exists:taxpayers,oib'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'direction' => ['required', Rule::enum(InvoiceDirection::class)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
