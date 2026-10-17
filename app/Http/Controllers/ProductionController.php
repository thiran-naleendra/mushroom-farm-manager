<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmProductionRequest;
use App\Http\Requests\CorrectProductionRequest;
use App\Http\Requests\PreviewProductionRequest;
use App\Http\Requests\VoidProductionRequest;
use App\Models\Batch;
use App\Models\Farm;
use App\Models\Recipe;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProductionController extends Controller
{
    private function context(): array
    {
        return ['farm' => Farm::firstOrFail(), 'section' => 'production'];
    }

    public function index(Request $request)
    {
        Gate::authorize('access-section', 'production');
        $filter = $request->validate(['search' => 'nullable|string|max:100', 'location' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $farm = Farm::firstOrFail();
        $batches = Batch::withSum('harvests', 'weight');
        if ($filter['search'] ?? null) {
            $batches->where(function ($query) use ($filter, $farm) {
                $query->where('variety', 'like', '%'.$filter['search'].'%')->orWhere('recipe_name', 'like', '%'.$filter['search'].'%');
                if ($farm->tracksBatches()) {
                    $query->orWhere('reference', 'like', '%'.$filter['search'].'%')->orWhere('code', 'like', '%'.$filter['search'].'%');
                }
            });
        }
        if ($farm->isAdvanced() && isset($filter['location'])) {
            $batches->where('location', $filter['location']);
        }

        return view('production.index', $this->context() + ['batches' => $batches->orderByDesc('id')->paginate(15)->withQueryString(), 'locations' => $farm->isAdvanced() ? Batch::recordedLocations()->pluck('location') : collect()]);
    }

    public function create()
    {
        Gate::authorize('access-section', 'production');

        return view('production.create', $this->context() + ['recipes' => Recipe::orderBy('name')->get(), 'locations' => Farm::firstOrFail()->isAdvanced() ? Batch::recordedLocations()->pluck('location') : collect()]);
    }

    public function preview(PreviewProductionRequest $request, ProductionService $service)
    {
        $quote = $service->preview($request->user(), $request->validated());
        $request->session()->put('production_quote', $quote);

        return redirect()->route('production.confirm');
    }

    public function confirmation(Request $request, ProductionService $service)
    {
        Gate::authorize('access-section', 'production');
        $quote = $request->session()->get('production_quote');
        if (! $quote) {
            return redirect()->route('production.create');
        }
        $preview = $service->decode($quote);
        abort_unless($preview['actor'] === $request->user()->id, 403);

        return view('production.confirm', $this->context() + compact('quote', 'preview'));
    }

    public function store(ConfirmProductionRequest $request, ProductionService $service)
    {
        $batch = $service->confirm($request->user(), $request->validated('quote'));
        $request->session()->forget('production_quote');

        return redirect()->route('production.show', $batch)->with('success', 'Production confirmed. Materials deducted once.');
    }

    public function show(Batch $batch)
    {
        Gate::authorize('view', $batch);
        $batch->load(['ingredients', 'audits.user']);

        return view('production.show', $this->context() + compact('batch'));
    }

    public function correct(CorrectProductionRequest $request, Batch $batch, ProductionService $service)
    {
        $service->update($request->user(), $batch, $request->validated(), true);

        return back()->with('success', 'Production correction recorded with its reason and author.');
    }

    public function void(VoidProductionRequest $request, Batch $batch, ProductionService $service)
    {
        Gate::authorize('void', $batch);
        $data = $request->validated();
        $service->void($request->user(), $batch, $data['reason']);

        return back()->with('success', 'Production voided. Original ingredient quantities and costs restored to inventory.');
    }
}
