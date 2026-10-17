<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveInventoryOptionRequest;
use App\Models\Farm;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Services\InventoryOptionService;
use Illuminate\Support\Facades\Gate;

class InventoryOptionController extends Controller
{
    public function index()
    {
        Gate::authorize('create', Material::class);

        return view('inventory.options', ['farm' => Farm::firstOrFail(), 'section' => 'inventory', 'categories' => MaterialCategory::orderBy('name')->get(), 'units' => MaterialUnit::orderBy('name')->get()]);
    }

    public function save(SaveInventoryOptionRequest $request, InventoryOptionService $service, string $kind, ?int $option = null)
    {
        $service->save($request->user(), $kind, $request->validated(), $option);

        return redirect()->route('inventory.options')->with('success', 'Inventory configuration saved.');
    }
}
