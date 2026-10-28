<?php

namespace App\Http\Requests;

use App\Support\CatalogPricing;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CatalogPricing::writable($this->user('web'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('promotion_timezone') !== CatalogPricing::TIMEZONE) {
            return;
        }
        foreach (['promo_starts_at', 'promo_ends_at'] as $field) {
            $value = $this->input($field);
            if ($value !== null && $value !== '') {
                Validator::make([$field => $value], [$field => ['date_format:Y-m-d\TH:i']])->validate();
                $date = Carbon::createFromFormat('!Y-m-d\TH:i', $value, CatalogPricing::TIMEZONE);
                $this->merge([$field => $date->utc()->format('Y-m-d H:i:s')]);
            }
        }
    }

    public function rules(): array
    {
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
                    ),
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
