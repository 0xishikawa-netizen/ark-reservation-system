<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Product\CreateProduct;
use App\Actions\Product\ToggleProductActive;
use App\Actions\Product\UpdateProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Models\TaxCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Products/Index', [
            'products' => Product::query()
                ->with('taxCategory:id,name')
                ->orderByDesc('is_active')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (Product $product): array => [
                    ...$product->only(['id', 'code', 'name', 'price', 'is_active', 'sort_order']),
                    'tax_category_name' => $product->taxCategory?->name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Products/Create', ['taxCategories' => $this->taxCategories()]);
    }

    public function store(StoreProductRequest $request, CreateProduct $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user());

        return redirect()->route('admin.products.index')->with('success', __('messages.product.created'));
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('Admin/Products/Edit', [
            'product' => $product->only(['id', 'code', 'name', 'price', 'tax_category_id', 'is_active', 'sort_order']),
            'taxCategories' => $this->taxCategories(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $action): RedirectResponse
    {
        $action->execute($product, $request->validated(), $request->user());

        return redirect()->route('admin.products.index')->with('success', __('messages.product.updated'));
    }

    public function setActive(Request $request, Product $product, ToggleProductActive $action): RedirectResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $active = (bool) $validated['active'];
        $action->execute($product, $active, $request->user());

        return back()->with('success', __($active ? 'messages.product.activated' : 'messages.product.deactivated'));
    }

    /** @return list<array{id:int,name:string,is_active:bool}> */
    private function taxCategories(): array
    {
        return TaxCategory::query()
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (TaxCategory $category): array => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'is_active' => (bool) $category->is_active,
            ])->all();
    }
}
