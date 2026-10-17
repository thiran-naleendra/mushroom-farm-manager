<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveProductRequest;
use App\Models\Farm;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    public function index(Request $request, ProductService $service)
    {
        Gate::authorize('viewAny', Product::class);
        $filter = $request->validate(['search' => 'nullable|string|max:150', 'type' => 'nullable|in:fresh,packaged,cultivation_bag', 'status' => 'nullable|in:active,inactive', 'page' => 'nullable|integer|min:1']);
        $products = Product::withSum('movements as stock', 'quantity')
            ->when($filter['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%')->orWhere('variety', 'like', '%'.$search.'%')))
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->when($filter['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->retailFirst()->paginate(15)->withQueryString();

        return view('products.index', ['farm' => Farm::firstOrFail(), 'section' => 'products', 'products' => $products, 'stockLevels' => $service->stockLevels($products->getCollection())]);
    }

    public function create()
    {
        Gate::authorize('create', Product::class);

        return $this->form(new Product(['is_active' => true, 'pack_size_grams' => Product::DEFAULT_PACK_SIZE_GRAMS]));
    }

    public function edit(Product $product)
    {
        Gate::authorize('update', $product);

        return $this->form($product);
    }

    private function form(Product $product)
    {
        return view('products.form', ['product' => $product, 'farm' => Farm::firstOrFail(), 'section' => 'products']);
    }

    public function store(SaveProductRequest $request, ProductService $service)
    {
        $service->save($request->user(), $request->validated());

        return redirect()->route('products.index')->with('success', 'Product created.');
    }

    public function update(SaveProductRequest $request, Product $product, ProductService $service)
    {
        $service->save($request->user(), $request->validated(), $product);

        return redirect()->route('products.index')->with('success', 'Product updated. Historical packaging costs are preserved.');
    }

    public function destroy(Request $request, Product $product, ProductService $service)
    {
        $service->archive($request->user(), $product);

        return redirect()->route('products.index')->with('success', 'Product archived.');
    }
}
