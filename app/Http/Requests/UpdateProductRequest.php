<?php

namespace App\Http\Requests;

use App\Support\CatalogPricing;
use App\Support\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ! $this->has('is_featured') || CatalogPricing::writable($this->user('web'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');

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
                    )
                    ->ignore($product->id),
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
        ];
    }
}
