<?php

namespace App\Http\Requests;

use App\Rules\UniqueCustomerPhone;
use App\Rules\CameroonianPhone;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'phone' => [
                'required',
                'string',
                'max:30',
                new CameroonianPhone(),
                new UniqueCustomerPhone(
                    $this->route('customer')?->id
                ),
            ],

            'email' => [
                'nullable',
                'email',
                'max:150',
            ],

            'status' => [
                'required',
                'string',
                'in:ACTIVE,INACTIVE',
            ],
        ];
    }
}
