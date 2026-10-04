<?php

namespace App\Actions\AttributeValue;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Validation\ValidationException;

class CreateAttributeValue
{
    public function execute(array $data): AttributeValue
    {
        $attribute = Attribute::query()
            ->find($data['attribute_id']);

        if ($attribute === null) {
            throw ValidationException::withMessages([
                'attribute_id' => 'L’attribut sélectionné n’appartient pas à cette boutique.',
            ]);
        }

        return AttributeValue::create($data);
    }
}