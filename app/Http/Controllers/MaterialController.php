<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustMaterialRequest;
use App\Http\Requests\InventoryFilterRequest;
use App\Http\Requests\SaveMaterialRequest;
use App\Models\Farm;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Services\MaterialService;
use App\Services\MaterialStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaterialController extends Controller
{
    private function context(): array
    {
        return ['farm' => Farm::firstOrFail(), 'section' => 'inventory'];
    }

    public function index(InventoryFilterRequest $request)
    {
        $filter = $request->validated();
        $query = Material::with(['category', 'unit']);
        if (($filter['status'] ?? '') === 'archived') {
            $query->onlyTrashed();
        }
        if ($filter['search'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$filter['search'].'%')->orWhere('sku', 'like', '%'.$filter['search'].'%'));
        }
        if ($filter['category'] ?? null) {
            $query->where('material_category_id', $filter['category']);
        }
        if ($filter['supply_type'] ?? null) {
            $query->where('supply_type', $filter['supply_type']);
        }
        if (in_array($filter['status'] ?? '', ['active', 'inactive'])) {
            $query->where('is_active', $filter['status'] === 'active');
        }
        if ($filter['low_stock'] ?? null) {
            $query->where('is_active', true)->whereColumn('quantity', '<=', 'minimum_stock');
        }

        return view('inventory.index', $this->context() + ['materials' => $query->orderBy('name')->orderBy('id')->paginate(15)->withQueryString(), 'categories' => MaterialCategory::orderBy('name')->get(), 'lowCount' => Material::where('is_active', true)->whereColumn('quantity', '<=', 'minimum_stock')->count()]);
    }

    public function create()
    {
        Gate::authorize('create', Material::class);

        return $this->form(new Material);
    }

    public function edit(Material $material)
    {
        Gate::authorize('update', $material);

        return $this->form($material);
    }

    private function form(Material $material)
    {
        return view('inventory.form', $this->context() + compact('material') + [
            'categories' => MaterialCategory::where('is_active', true)->orWhere('id', $material->material_category_id)->orderBy('name')->get(),
            'units' => MaterialUnit::where('is_active', true)->orWhere('id', $material->material_unit_id)->orderBy('name')->get(),
        ]);
    }

    public function store(SaveMaterialRequest $request, MaterialService $service)
    {
        $material = $service->save($request->user(), $request->validated());

        return redirect()->route('inventory.show', $material)->with('success', 'Material created. Opening stock recorded once.');
    }

    public function update(SaveMaterialRequest $request, Material $material, MaterialService $service)
    {
        $service->save($request->user(), $request->validated(), $material);

        return redirect()->route('inventory.show', $material)->with('success', 'Material updated. Use adjustments to change stock.');
    }

    public function show(Request $request, Material $material)
    {
        Gate::authorize('view', $material);
        $material->load(['category', 'unit']);
        $units = MaterialUnit::where('is_active', true)->where('dimension', $material->unit->dimension)->when($material->unit->dimension === 'custom', fn ($query) => $query->whereKey($material->material_unit_id))->orderBy('name')->get();

        return view('inventory.show', $this->context() + compact('material', 'units') + ['movements' => $material->movements()->with(['user', 'unit'])->orderByDesc('id')->paginate(20)]);
    }

    public function adjust(AdjustMaterialRequest $request, Material $material, MaterialStockService $service)
    {
        $service->adjust($request->user(), $material, $request->validated());

        return redirect()->route('inventory.show', $material)->with('success', 'Stock adjustment recorded.');
    }

    public function destroy(Request $request, Material $material, MaterialService $service)
    {
        $service->archive($request->user(), $material);

        return redirect()->route('inventory.index')->with('success', 'Material archived. Its stock history is preserved.');
    }
}
