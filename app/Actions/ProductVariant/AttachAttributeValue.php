<?php

namespace App\Actions\ProductVariant;

use App\Models\AttributeValue;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class AttachAttributeValue
{
    public function execute(
    int $productVariantId,
    int $attributeValueId
): ProductVariant {
    // 1. La variante doit appartenir à la boutique active.
    $variant = ProductVariant::query()
        ->find($productVariantId);

    if ($variant === null) {
        throw ValidationException::withMessages([
            'product_variant_id' =>
                'La variante sélectionnée n’appartient pas à cette boutique.',
        ]);
    }

    // 2. La valeur doit appartenir à la boutique active.
    $attributeValue = AttributeValue::query()
        ->find($attributeValueId);

    if ($attributeValue === null) {
        throw ValidationException::withMessages([
            'attribute_value_id' =>
                'La valeur d’attribut sélectionnée n’appartient pas à cette boutique.',
        ]);
    }

    // 3. Vérifier si cette variante possède déjà
    // une AUTRE valeur appartenant au même attribut.
    $alreadyHasValueForAttribute = $variant->attributeValues()
        ->where(
            'attribute_values.attribute_id',
            $attributeValue->attribute_id
        )
        ->where(
            'attribute_values.id',
            '!=',
            $attributeValue->id
        )
        ->exists();

    if ($alreadyHasValueForAttribute) {
        throw ValidationException::withMessages([
            'attribute_value_id' =>
                'Cette variante possède déjà une valeur pour cet attribut.',
        ]);
    }

    // 4. Association sans créer de doublon.
    $variant->attributeValues()->syncWithoutDetaching([
        $attributeValue->id,
    ]);

    return $variant;
}
}