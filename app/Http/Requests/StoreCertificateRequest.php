<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A PKCS#12 (.p12/.pfx) upload, or a raw PEM string bundling cert + key.
            'certificate' => ['required_without:certificate_pem', 'file', 'max:51200'],
            'certificate_pem' => ['required_without:certificate', 'string'],
            'password' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
