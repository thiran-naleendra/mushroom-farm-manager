<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProductionRequest;
use App\Models\Batch;
use App\Models\Farm;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CultivationController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('access-section', 'cultivation');
        $data = $request->validate(['stage' => ['nullable', Rule::in(Batch::STAGES)], 'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $batches = Batch::where('production_status', '!=', 'voided')->withCount('harvests')->withSum('harvests', 'weight');
        if ($data['stage'] ?? null) {
            $batches->where('stage', $data['stage']);
        }
        if ($data['search'] ?? null) {
            $batches->where(fn ($q) => $q->where('variety', 'like', '%'.$data['search'].'%')->orWhere('location', 'like', '%'.$data['search'].'%'));
        }

        return view('cultivation.index', ['farm' => Farm::firstOrFail(), 'section' => 'cultivation', 'batches' => $batches->orderByDesc('id')->paginate(15)->withQueryString()]);
    }

    public function show(Batch $batch)
    {
        Gate::authorize('access-section', 'cultivation');
        $batch->load(['audits.user']);

        return view('cultivation.show', ['farm' => Farm::firstOrFail(), 'section' => 'cultivation', 'batch' => $batch, 'harvests' => $batch->harvests()->with('batch')->orderByDesc('harvested_on')->orderByDesc('id')->paginate(15)]);
    }

    public function update(UpdateProductionRequest $request, Batch $batch, ProductionService $service)
    {
        Gate::authorize('access-section', 'cultivation');
        $service->update($request->user(), $batch, $request->validated());

        return back()->with('success', 'Cultivation updated. Bag counts, dates and reason retained in audit history.');
    }
}
