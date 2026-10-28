<?php

namespace App\Http\Controllers\Professional;

use App\Actions\Category\CreateCategory;
use App\Actions\Category\DeleteCategory;
use App\Actions\Category\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Support\CatalogAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class CategoryCatalogController extends Controller
{
    public function index(Request $request): View
    {
        $context = CatalogAccess::context($request, 'categories.view');
        Gate::authorize('viewAny', Category::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:ACTIVE,INACTIVE']]);
        $categories = Category::query()->withCount('products')->when(filled($filters['q'] ?? null), fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%'))->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))->orderBy('name')->paginate(15)->withQueryString();

        return view('professional.catalog.categories', $context + compact('categories', 'filters'));
    }

    public function create(Request $request): View
    {
        $context = CatalogAccess::context($request, 'categories.create', true);
        Gate::authorize('create', Category::class);

        return view('professional.catalog.category', $context + ['category' => new Category]);
    }

    public function edit(Request $request, Category $category): View
    {
        $context = CatalogAccess::context($request, 'categories.update', true);
        Gate::authorize('update', $category);

        return view('professional.catalog.category', $context + compact('category'));
    }

    public function store(StoreCategoryRequest $request, CreateCategory $action): RedirectResponse
    {
        CatalogAccess::context($request, 'categories.create', true);
        Gate::authorize('create', Category::class);
        CatalogAccess::persist(fn () => $action->execute($request->validated()));

        return redirect()->route('professional.categories.index')->with('success', __('catalog.saved'));
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategory $action): RedirectResponse
    {
        CatalogAccess::context($request, 'categories.update', true);
        Gate::authorize('update', $category);
        CatalogAccess::mutate($category, $request, fn ($current) => $action->execute($current, $request->validated()));

        return redirect()->route('professional.categories.index')->with('success', __('catalog.saved'));
    }

    public function destroy(Request $request, Category $category, DeleteCategory $action): RedirectResponse
    {
        CatalogAccess::context($request, 'categories.delete', true);
        Gate::authorize('delete', $category);
        CatalogAccess::mutate($category, $request, fn ($current) => $action->execute($current));

        return redirect()->route('professional.categories.index')->with('success', __('catalog.deleted'));
    }
}
