<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHarvestRequest;
use App\Models\Batch;
use App\Models\Farm;
use App\Models\Harvest;
use App\Services\FreshQuantity;
use App\Services\FreshStockService;
use App\Services\HarvestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class HarvestController extends Controller
{
    public function index(Request $request, FreshStockService $stock)
    {
        Gate::authorize('access-section', 'harvests');
        $filter = $request->validate(['batch_id' => 'nullable|integer|exists:batches,id', 'location' => 'nullable|string|max:100', 'variety' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1']);
        if (! empty($filter['from']) && ! empty($filter['to']) && $filter['to'] < $filter['from']) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        $harvests = Harvest::with('batch');
        $farm = Farm::firstOrFail();
        if (isset($filter['batch_id'])) {
            $harvests->where('batch_id', $filter['batch_id']);
        }
        if ($farm->isAdvanced() && isset($filter['location'])) {
            $harvests->whereHas('batch', fn ($q) => $q->where('location', $filter['location']));
        }
        if ($filter['variety'] ?? null) {
            $harvests->where('variety', $filter['variety']);
        }
        if ($filter['from'] ?? null) {
            $harvests->where('harvested_on', '>=', $filter['from']);
        }
        if ($filter['to'] ?? null) {
            $harvests->where('harvested_on', '<=', $filter['to']);
        }
        $totals = $stock->totals();

        return view('harvests.index', [
            'farm' => $farm, 'section' => 'harvests', 'harvests' => $harvests->orderByDesc('harvested_on')->orderByDesc('id')->paginate(15)->withQueryString(),
            'locations' => $farm->isAdvanced() ? Batch::recordedLocations()->pluck('location') : collect(),
            'historyBatches' => $farm->tracksBatches() ? Batch::select('id', 'variety', 'reference', 'code', 'started_on')->orderByDesc('id')->get() : collect(),
            'batches' => Batch::select('id', 'variety', 'started_on', 'location', 'reference', 'code')->where('stage', '!=', 'completed')->where('production_status', '!=', 'voided')->whereRaw('bags > sold_bags + damaged_bags + contaminated_bags + discarded_bags')->orderByDesc('id')->get(),
            'totals' => array_map(fn ($value) => FreshQuantity::kg($value), $totals),
        ]);
    }

    public function store(StoreHarvestRequest $request, HarvestService $service)
    {
        $service->record($request->user(), $request->validated());

        return redirect()->route('harvests.index')->with('success', 'Harvest recorded. Only saleable mushrooms added to fresh stock, once.');
    }
}
