@php
  $variantLabel = $variant->attributeValues->filter(fn ($value) => $value->attribute !== null)
      ->map(fn ($value) => $value->attribute->name . ': ' . $value->value)->implode(' / ');
@endphp
{{ $variantLabel !== '' ? $variantLabel : $variant->sku }}
