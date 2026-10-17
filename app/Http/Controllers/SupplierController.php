<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSupplierRequest;
use App\Models\Farm;
use App\Models\Supplier;
use App\Services\PurchaseReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    private function context(): array
    {
        return ['farm' => Farm::firstOrFail(), 'section' => 'purchases'];
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Supplier::class);
        $filters = $request->validate(['search' => 'nullable|string|max:120', 'archived' => 'nullable|in:1', 'page' => 'nullable|integer|min:1']);
        $suppliers = Supplier::query();
        if ($filters['archived'] ?? null) {
            $suppliers->onlyTrashed();
        }
        if ($filters['search'] ?? null) {
            $suppliers->where('name', 'like', '%'.$filters['search'].'%');
        }

        return view('suppliers.index', $this->context() + ['suppliers' => $suppliers->withCount('purchases')->orderBy('name')->paginate(15)->withQueryString()]);
    }

    public function create()
    {
        Gate::authorize('create', Supplier::class);

        return view('suppliers.form', $this->context() + ['supplier' => new Supplier]);
    }

    public function edit(Supplier $supplier)
    {
        Gate::authorize('update', $supplier);

        return view('suppliers.form', $this->context() + compact('supplier'));
    }

    public function store(SaveSupplierRequest $request)
    {
        DB::transaction(fn () => Supplier::create($request->validated()));

        return redirect()->route('suppliers.index')->with('success', 'Supplier created.');
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier)
    {
        DB::transaction(fn () => $supplier->update($request->validated()));

        return redirect()->route('suppliers.index')->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier)
    {
        Gate::authorize('delete', $supplier);
        DB::transaction(fn () => Supplier::lockForUpdate()->findOrFail($supplier->id)->delete());

        return redirect()->route('suppliers.index')->with('success', 'Supplier archived. Purchase history remains available.');
    }

    public function show(Supplier $supplier, PurchaseReportService $reports)
    {
        Gate::authorize('view', $supplier);
        Gate::authorize('view-financials');

        return view('suppliers.show', $this->context() + compact('supplier') + ['totals' => $reports->supplierTotals($supplier->id), 'purchases' => $reports->query()->where('supplier_id', $supplier->id)->orderByDesc('purchased_on')->orderByDesc('id')->paginate(15)]);
    }
}
