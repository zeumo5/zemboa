<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
{
    return [
        'category_id' => [
            'required',
            'integer',
        ],

        'name' => [
            'required',
            'string',
            'max:180',
        ],

        'slug' => [
    'required',
    'string',
    'max:200',
    Rule::unique('products', 'slug')
        ->where(
            fn ($query) => $query->where(
                'store_id',
                app(TenantContext::class)->storeId()
            )
        ),
],

        'description' => [
            'nullable',
            'string',
        ],

        'status' => [
            'required',
            'string',
            'in:ACTIVE,INACTIVE',
        ],

        'is_featured' => [
            'sometimes',
            'boolean',
        ],

        'default_variant' => [
            'required',
            'array',
        ],

        'default_variant.price' => [
            'required',
            'numeric',
            'min:0',
        ],

        'default_variant.sku' => [
            'nullable',
            'string',
            'max:100',
        ],
    ];
}

}
