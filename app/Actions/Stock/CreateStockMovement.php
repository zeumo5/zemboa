<?php

namespace App\Actions\Stock;



use App\Models\User;
use App\Support\TenantContext;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

class CreateStockMovement
{
    private const ALLOWED_TYPES = [
        'RECEIPT',
        'SALE',
        'RETURN',
        'ADJUSTMENT_IN',
        'ADJUSTMENT_OUT',
    ];

    public function execute(array $data): StockMovement
    {
        $variant = ProductVariant::query()
            ->find($data['product_variant_id']);

        if ($variant === null) {
            throw ValidationException::withMessages([
                'product_variant_id' =>
                    'La variante sélectionnée n’appartient pas à cette boutique.',
            ]);
        }

       if (
    ! isset($data['quantity']) ||
    filter_var($data['quantity'], FILTER_VALIDATE_INT) === false ||
    (int) $data['quantity'] <= 0
) {
    throw ValidationException::withMessages([
        'quantity' => 'La quantité doit être un nombre entier supérieur à zéro.',
    ]);
}

$data['quantity'] = (int) $data['quantity'];

        if (
            !isset($data['type'])
            || !in_array($data['type'], self::ALLOWED_TYPES, true)
        ) {
            throw ValidationException::withMessages([
                'type' => 'Le type de mouvement de stock est invalide.',
            ]);
        }

        if (isset($data['created_by'])) {
    $currentStoreId = app(TenantContext::class)->storeId();

    $creatorBelongsToCurrentStore = User::query()
        ->whereKey($data['created_by'])
        ->where('store_id', $currentStoreId)
        ->exists();

    if (!$creatorBelongsToCurrentStore) {
        throw ValidationException::withMessages([
            'created_by' =>
                'L’utilisateur associé au mouvement n’appartient pas à cette boutique.',
        ]);
    }
}

        return StockMovement::create($data);
    }
}