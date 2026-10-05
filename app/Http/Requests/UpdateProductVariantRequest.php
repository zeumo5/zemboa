<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $variant = $this->route('productVariant');

        return [
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('product_variants', 'sku')
                    ->where(
                        fn ($query) => $query->where(
                            'store_id',
                            app(TenantContext::class)->storeId()
                        )
                    )
                    ->ignore($variant->id),
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'promo_price' => [
                'nullable',
                'numeric',
                'min:0',
                'lt:price',
            ],

            'promo_starts_at' => [
                'nullable',
                'date',
            ],

            'promo_ends_at' => [
                'nullable',
                'date',
                'after_or_equal:promo_starts_at',
            ],

            'is_default' => [
                'sometimes',
                'boolean',
            ],

            'status' => [
                'required',
                'string',
                'in:ACTIVE,INACTIVE',
            ],
        ];
    }
}