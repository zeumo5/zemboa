<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class LoginBuyerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:255'],
            'enable_buyer' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.*' => __('buyer.email_invalid'),
            'password.*' => __('buyer.credentials_invalid'),
            'enable_buyer.boolean' => __('buyer.consent_invalid'),
        ];
    }
}
