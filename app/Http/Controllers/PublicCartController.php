<?php
namespace App\Http\Controllers;


use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCartController extends Controller
{
    public function __construct(
        private readonly CartService $cart
    ) {}

    // Consulter le panier.
    public function index(string $shopSlug): JsonResponse
    {
        return $this->response();
    }

    // Ajouter une variante au panier.
    public function store(
        Request $request,
        string $shopSlug
    ): JsonResponse {
        $data = $request->validate([
            'product_variant_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $this->cart->add(
            (int) $data['product_variant_id'],
            (int) $data['quantity']
        );

        return $this->response();
    }

    // Modifier la quantité d'un article.
    public function update(
        Request $request,
        string $shopSlug,
        int $variantId
    ): JsonResponse {
        $data = $request->validate([
            'quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $this->cart->update(
            $variantId,
            (int) $data['quantity']
        );

        return $this->response();
    }

    // Retirer un article du panier.
    public function destroy(
        string $shopSlug,
        int $variantId
    ): JsonResponse {
        $this->cart->remove($variantId);

        return $this->response();
    }

    // Vider le panier.
    public function clear(string $shopSlug): JsonResponse
    {
        $this->cart->clear();

        return $this->response();
    }


private function response(): JsonResponse
{
    $summary = $this->cart->summary();

    return response()->json([
        'data' => [
            'items' => $this->cart->items(),
            'details' => $summary['details'],
            'totals' => $summary['totals'],
            'can_checkout' => $summary['can_checkout'],
        ],
    ]);
}

}
