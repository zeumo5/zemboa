<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\BuyerIdentity;
use Closure;

final class LoginBuyerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['identifier' => BuyerIdentity::identifier($this->input('identifier', $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            'identifier' => ['bail', 'required', 'string', 'max:254', function (string $attribute, mixed $value, Closure $fail): void {
                if (!validator(['email' => $value], ['email' => 'email'])->passes() && BuyerIdentity::phoneOrNull($value) === null) $fail(__('buyer.identifier_invalid'));
            }],
            'password' => ['required', 'string', 'max:255'],
            'enable_buyer' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.*' => __('buyer.identifier_invalid'),
            'password.*' => __('buyer.credentials_invalid'),
            'enable_buyer.boolean' => __('buyer.consent_invalid'),
        ];
    }
}
