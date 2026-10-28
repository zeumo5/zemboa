<?php

namespace App\Http\Requests\Professional;

use App\Http\Requests\UpdateProductVariantRequest;
use App\Support\CatalogPricing;

final class SavePromotionRequest extends UpdateProductVariantRequest
{
    public function authorize(): bool
    {
        return CatalogPricing::writable($this->user('web'));
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'size:64'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'promo_price' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'promo_starts_at' => ['present', 'nullable', 'date'],
            'promo_ends_at' => ['present', 'nullable', 'date'],
            'promotion_timezone' => ['required', 'in:'.CatalogPricing::TIMEZONE],
        ];
    }
}
