<?php

namespace App\Http\Controllers;

use App\Actions\Category\UpdateCategory;
use App\Http\Requests\UpdateCategoryRequest;
use App\Actions\Category\CreateCategory;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{

public function index(): JsonResponse
{
    Gate::authorize('viewAny', Category::class);

    $categories = Category::query()
        ->orderBy('name')
        ->get();

    return response()->json([
        'data' => $categories,
    ]);
}
    public function store(
        StoreCategoryRequest $request,
        CreateCategory $createCategory
    ): JsonResponse {
        Gate::authorize('create', Category::class);

        $category = $createCategory->execute(
            $request->validated()
        );

        return response()->json([
            'message' => 'Catégorie créée avec succès.',
            'data' => $category,
        ], 201);
    }

    public function update(
    UpdateCategoryRequest $request,
    Category $category,
    UpdateCategory $updateCategory
): JsonResponse {
    Gate::authorize('update', $category);

    $category = $updateCategory->execute(
        $category,
        $request->validated()
    );

    return response()->json([
        'message' => 'Catégorie modifiée avec succès.',
        'data' => $category,
    ]);
}
}