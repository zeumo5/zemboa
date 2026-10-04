<?php

namespace App\Http\Controllers;

use App\Actions\Category\CreateCategory;
use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
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
}