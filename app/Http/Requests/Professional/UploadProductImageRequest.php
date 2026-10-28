<?php

namespace App\Http\Requests\Professional;

use App\Support\CatalogAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UploadProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        CatalogAccess::context($this, 'products.update', true);

        return Gate::forUser($this->user('web'))->allows('update', $this->route('product'));
    }

    public function rules(): array
    {
        return ['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=16,min_height=16,max_width=4096,max_height=4096'], 'alt_text' => ['nullable', 'string', 'max:255'], 'is_primary' => ['sometimes', 'boolean']];
    }
}
