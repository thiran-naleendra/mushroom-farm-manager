<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFarmRecordRequest;
use App\Http\Requests\UpdateProductionRequest;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Farm;
use App\Models\Sale;
use App\Services\CustomerService;
use App\Services\FreshQuantity;
use App\Services\FreshStockService;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FarmController extends Controller
{
    public function index(Request $request, string $section)
    {
        Gate::authorize('access-section', $section);
        $farm = Farm::firstOrFail();
        $batches = Batch::withSum('harvests', 'weight')->latest()->get();
        $stock = app(FreshStockService::class)->totals();
        $harvested = FreshQuantity::kg($stock['saleable']);
        $sold = FreshQuantity::kg($stock['sold']);
        $records = match ($section) {
            'sales' => Sale::orderByDesc('sold_on')->paginate(15),
            'expenses' => Expense::orderByDesc('spent_on')->paginate(15),
            default => null,
        };

        $availableFresh = FreshQuantity::kg($stock['available']);
        $customers = $section === 'sales' ? Customer::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect();

        return view('farm', compact('farm', 'section', 'batches', 'harvested', 'sold', 'records', 'customers', 'availableFresh'));
    }

    public function store(StoreFarmRecordRequest $request, string $type)
    {
        Gate::authorize('access-section', $type);
        $data = $request->validated();
        DB::transaction(function () use ($type, $data, $request) {
            Farm::lockForUpdate()->firstOrFail();
            if ($type === 'sales') {
                $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
                if (! empty($data['request_id']) && ($existing = Sale::where('request_id', $data['request_id'])->first())) {
                    if ($existing->user_id !== $request->user()->id || $existing->payload_hash !== $hash) {
                        throw ValidationException::withMessages(['request_id' => 'This sales reference has already been used.']);
                    }

                    return;
                }
                $data['payload_hash'] = $hash;
                $data['user_id'] = $request->user()->id;
                app(FreshStockService::class)->assertSaleFits((string) $data['weight'], $data['sold_on']);
                $customer = empty($data['customer_id']) ? null : Customer::lockForUpdate()->findOrFail($data['customer_id']);
                if ($customer) {
                    $data['customer'] = $customer->name;
                }
                $sale = Sale::create($data);
                if ($customer) {
                    app(CustomerService::class)->recordSale($request->user(), $customer, $sale);
                }
                app(FreshStockService::class)->assertTimeline();
            } else {
                Expense::create($data);
            }
        });

        return back()->with('success', 'Record saved.');
    }

    public function stage(UpdateProductionRequest $request, Batch $batch, ProductionService $service)
    {
        Gate::authorize('access-section', 'production');
        $data = $request->validated();
        $service->update($request->user(), $batch, $data);

        return back()->with('success', 'Production stage updated.');
    }

    public function label(Batch $batch)
    {
        Gate::authorize('access-section', 'production');
        $farm = Farm::firstOrFail();
        abort_unless($farm->printsQr(), 403);

        return view('label', compact('farm', 'batch'));
    }
}
