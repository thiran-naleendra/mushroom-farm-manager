<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePackagingRequest;
use App\Models\Farm;
use App\Models\Material;
use App\Models\PackagingRun;
use App\Models\Product;
use App\Services\FreshStockService;
use App\Services\PackagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PackagingController extends Controller
{
    public function index(Request $request, FreshStockService $stock)
    {
        Gate::authorize('access-section', 'packaging');
        $filter = $request->validate(['search' => 'nullable|string|max:150', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1']);
        if (! empty($filter['from']) && ! empty($filter['to']) && $filter['to'] < $filter['from']) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        $runs = PackagingRun::when($filter['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('product_name', 'like', '%'.$search.'%')->orWhere('variety', 'like', '%'.$search.'%')))
            ->when($filter['from'] ?? null, fn ($q, $date) => $q->where('packaged_on', '>=', $date))
            ->when($filter['to'] ?? null, fn ($q, $date) => $q->where('packaged_on', '<=', $date))
            ->orderByDesc('packaged_on')->orderByDesc('id')->paginate(15)->withQueryString();

        return view('packaging.index', ['farm' => Farm::firstOrFail(), 'section' => 'packaging', 'runs' => $runs, 'totals' => $stock->totals(),
            'lots' => $stock->lots(), 'products' => Product::where('is_active', true)->where('type', 'packaged')->retailFirst()->get(),
            'materials' => Material::with('unit')->where('supply_type', 'retail')->where('is_active', true)->whereHas('unit', fn ($q) => $q->where('dimension', 'count')->where('is_active', true))->orderBy('name')->get()]);
    }

    public function store(StorePackagingRequest $request, PackagingService $service)
    {
        $run = $service->record($request->user(), $request->validated());

        return redirect()->route('packaging.show', $run)->with('success', 'Packaging recorded. Fresh stock and materials deducted once; finished packets added.');
    }

    public function show(PackagingRun $packaging)
    {
        Gate::authorize('access-section', 'packaging');

        return view('packaging.show', ['run' => $packaging->load('materials'), 'farm' => Farm::firstOrFail(), 'section' => 'packaging']);
    }
}
