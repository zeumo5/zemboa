<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = app(TenantContext::class)->storeId();

       $categoryId = $this->route('category');
        return [
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'slug' => [
                'required',
                'string',
                'max:180',

                Rule::unique('categories', 'slug')
                    ->where('store_id', $storeId)
                    ->ignore($categoryId),
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'status' => [
                'required',
                Rule::in(['ACTIVE', 'INACTIVE']),
            ],
        ];
    }
}