<?php

namespace App\Http\Requests\Buyer;

use App\Models\User;
use App\Support\BuyerIdentity;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class RegisterBuyerRequest extends FormRequest
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
        if ($this->input('email') === '') $this->merge(['email' => null]);
        if (($phone = BuyerIdentity::phoneOrNull($this->input('phone'))) !== null) $this->merge(['phone' => $phone]);
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['bail', 'nullable', 'string', 'email', 'max:254',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (User::query()->whereRaw('LOWER(email) = ?', [$value])->exists()) {
                        $fail(__('buyer.email_used'));
                    }
                },
            ],
            'phone' => ['bail', 'required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                $phone = BuyerIdentity::phoneOrNull($value);
                if (!$phone) $fail(__('buyer.phone_invalid'));
                elseif (BuyerIdentity::phoneQuery($phone)->exists()) $fail(__('buyer.phone_used'));
            }],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.*' => __('buyer.phone_invalid'),
            'name.required' => __('buyer.name_required'),
            'name.string' => __('buyer.name_invalid'),
            'name.max' => __('buyer.name_invalid'),
            'email.required' => __('buyer.email_invalid'),
            'email.string' => __('buyer.email_invalid'),
            'email.email' => __('buyer.email_invalid'),
            'email.max' => __('buyer.email_invalid'),
            'password.required' => __('buyer.password_invalid'),
            'password.string' => __('buyer.password_invalid'),
            'password.min' => __('buyer.password_invalid'),
            'password.max' => __('buyer.password_invalid'),
            'password.confirmed' => __('buyer.password_confirmation_invalid'),
        ];
    }
}
