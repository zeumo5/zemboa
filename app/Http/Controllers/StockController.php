<?php

namespace App\Http\Controllers;

use App\Actions\Stock\AdjustStockIn;
use App\Actions\Stock\AdjustStockOut;
use App\Actions\Stock\ReceiveStock;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\StockLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class StockController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', StockLevel::class);

        $stockLevels = StockLevel::query()
            ->with('productVariant.product')
            ->get();

        return response()->json($stockLevels);
    }

    public function show(StockLevel $stockLevel): JsonResponse
    {
        Gate::authorize('view', $stockLevel);

        $stockLevel->load('productVariant.product');

        return response()->json($stockLevel);
    }

    public function receive(
        StockAdjustmentRequest $request,
        StockLevel $stockLevel,
        ReceiveStock $receiveStock
    ): RedirectResponse {
        Gate::authorize('manage', $stockLevel);

        $data = $request->validated();

        $receiveStock->execute(
            $stockLevel->product_variant_id,
            $data['quantity'],
            $request->user()->id,
            $data['reason'] ?? null
        );

        return redirect()
            ->route('stock.show', $stockLevel)
            ->with('success', 'Stock reçu avec succès.');
    }

    public function adjustIn(
        StockAdjustmentRequest $request,
        StockLevel $stockLevel,
        AdjustStockIn $adjustStockIn
    ): RedirectResponse {
        Gate::authorize('manage', $stockLevel);

        $data = $request->validated();

        $adjustStockIn->execute(
            $stockLevel->product_variant_id,
            $data['quantity'],
            $request->user()->id,
            $data['reason'] ?? null
        );

        return redirect()
            ->route('stock.show', $stockLevel)
            ->with('success', 'Stock ajusté à la hausse avec succès.');
    }

    public function adjustOut(
        StockAdjustmentRequest $request,
        StockLevel $stockLevel,
        AdjustStockOut $adjustStockOut
    ): RedirectResponse {
        Gate::authorize('manage', $stockLevel);

        $data = $request->validated();

        $adjustStockOut->execute(
            $stockLevel->product_variant_id,
            $data['quantity'],
            $request->user()->id,
            $data['reason'] ?? null
        );

        return redirect()
            ->route('stock.show', $stockLevel)
            ->with('success', 'Stock ajusté à la baisse avec succès.');
    }
}