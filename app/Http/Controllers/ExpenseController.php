<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductionReportRequest;
use App\Http\Requests\SaveExpenseRequest;
use App\Models\Batch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Farm;
use App\Models\Harvest;
use App\Services\ExpenseService;
use App\Services\FinancialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ExpenseController extends Controller
{
    public function productionReport(ProductionReportRequest $request, FinancialService $service)
    {
        $farm = Farm::firstOrFail();

        return view('expenses.production-report', ['farm' => $farm, 'section' => 'reports', 'locations' => Batch::recordedLocations()->pluck('location')] + $service->productionReport($farm, $request->validated()));
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Expense::class);
        $filter = $request->validate(['search' => 'nullable|string|max:150', 'category' => 'nullable|exists:expense_categories,id', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $query = Expense::with('category')->when($filter['search'] ?? null, fn ($q, $s) => $q->where('description', 'like', '%'.$s.'%'))->when($filter['category'] ?? null, fn ($q, $id) => $q->where('expense_category_id', $id))->when($filter['from'] ?? null, fn ($q, $date) => $q->where('spent_on', '>=', $date))->when($filter['to'] ?? null, fn ($q, $date) => $q->where('spent_on', '<=', $date));

        return view('expenses.index', ['farm' => Farm::firstOrFail(), 'section' => 'expenses', 'expenses' => $query->orderByDesc('spent_on')->orderByDesc('id')->paginate(15)->withQueryString(), 'categories' => ExpenseCategory::orderBy('name')->get()]);
    }

    public function create()
    {
        Gate::authorize('create', Expense::class);

        return $this->form(new Expense(['spent_on' => today()->toDateString(), 'treatment' => 'operating']));
    }

    public function edit(Expense $expense)
    {
        Gate::authorize('update', $expense);

        return $this->form($expense);
    }

    private function form(Expense $expense)
    {
        return view('expenses.form', ['farm' => Farm::firstOrFail(), 'section' => 'expenses', 'expense' => $expense, 'categories' => ExpenseCategory::orderBy('name')->get(), 'batches' => Batch::where('production_status', '!=', 'voided')->orderByDesc('id')->get(['id', 'variety', 'started_on']), 'audits' => $expense->exists ? DB::table('expense_audits')->where('expense_id', $expense->id)->orderByDesc('id')->paginate(10) : null]);
    }

    public function store(SaveExpenseRequest $request, ExpenseService $service)
    {
        $service->save($request->user(), $request->validated());

        return redirect()->route('expenses.index')->with('success', 'Expense saved.');
    }

    public function update(SaveExpenseRequest $request, Expense $expense, ExpenseService $service)
    {
        $service->save($request->user(), $request->validated(), $expense);

        return redirect()->route('expenses.index')->with('success', 'Expense updated.');
    }

    public function destroy(Request $request, Expense $expense, ExpenseService $service)
    {
        $service->remove($request->user(), $expense);

        return redirect()->route('expenses.index')->with('success', 'Expense deleted. Audit history preserved.');
    }

    public function category(Request $request)
    {
        Gate::authorize('create', Expense::class);
        ExpenseCategory::create($request->validate(['name' => 'required|string|max:100|unique:expense_categories,name']));

        return back()->with('success', 'Category added.');
    }

    public function reports(Request $request, FinancialService $service)
    {
        Gate::authorize('view-financials');
        $dates = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = $dates['from'] ?? today()->startOfMonth()->toDateString();
        $to = $dates['to'] ?? today()->toDateString();
        abort_if($from > $to, 422, 'Invalid report dates.');
        $farm = Farm::firstOrFail();

        return view('expenses.reports', ['farm' => $farm, 'section' => 'reports', 'from' => $from, 'to' => $to, 'report' => $service->report($farm, $from, $to)]);
    }

    public function allocate(Request $request, Harvest $harvest, FinancialService $service)
    {
        Gate::authorize('view-financials');
        $data = $request->validate(['amount' => 'required|numeric|min:0|max:100000000|decimal:0,2']);
        $service->allocateHarvest($request->user(), $harvest, $data['amount']);

        return back()->with('success', 'Production cost transferred to harvest.');
    }
}
