<?php

namespace App\Http\Requests;

use App\Enums\InvoiceDirection;
use App\Enums\VatCategory;
use App\Models\Taxpayer;
use App\Rules\Oib;
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
            'supplier_oib' => ['required', 'string', new Oib, 'exists:taxpayers,oib'],
            'buyer_oib' => ['required', 'string', new Oib, 'different:supplier_oib', 'exists:taxpayers,oib'],
            'invoice_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('invoices', 'invoice_number')->where(
                    'supplier_id',
                    Taxpayer::where('oib', $this->input('supplier_oib'))->value('id')
                ),
            ],
            'issue_date' => ['required', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:issue_date'],
            'direction' => ['required', Rule::enum(InvoiceDirection::class)],
            'currency' => ['sometimes', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'lines.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.vat_category' => ['required', Rule::enum(VatCategory::class)],
            'lines.*.unit_code' => ['sometimes', 'string', 'size:3'],
            'lines.*.kpd_code' => ['required', 'string', 'size:6'],
        ];
    }
}
