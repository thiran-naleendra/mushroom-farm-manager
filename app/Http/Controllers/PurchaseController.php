<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseFilterRequest;
use App\Http\Requests\PurchasePaymentRequest;
use App\Http\Requests\PurchaseReturnRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Models\Farm;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseAmounts;
use App\Services\PurchaseReportService;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PurchaseController extends Controller
{
    private function context(): array
    {
        return ['farm' => Farm::firstOrFail(), 'section' => 'purchases'];
    }

    public function index(PurchaseFilterRequest $request, PurchaseReportService $reports)
    {
        $filters = $request->validated();
        $query = $reports->query();
        if ($filters['search'] ?? null) {
            $query->where('reference', 'like', '%'.$filters['search'].'%');
        }
        if ($filters['supplier_id'] ?? null) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        $balance = '(total - COALESCE(credits_sum, 0) - COALESCE(paid_sum, 0) + COALESCE(refunds_sum, 0))';
        if ($status = $filters['payment_status'] ?? null) {
            $query->whereRaw(match ($status) {
                'paid' => "$balance = 0", 'credit_due' => "$balance < 0",
                'partial' => "$balance > 0 AND COALESCE(paid_sum, 0) > COALESCE(refunds_sum, 0)",
                default => "$balance > 0 AND COALESCE(paid_sum, 0) <= COALESCE(refunds_sum, 0)",
            });
        }

        return view('purchases.index', $this->context() + ['purchases' => $query->orderByDesc('purchased_on')->orderByDesc('id')->paginate(15)->withQueryString(), 'suppliers' => Supplier::withTrashed()->orderBy('name')->get()]);
    }

    public function create()
    {
        Gate::authorize('create', Purchase::class);

        return view('purchases.create', $this->context() + ['suppliers' => Supplier::orderBy('name')->get(), 'materials' => Material::with('unit')->where('is_active', true)->orderBy('name')->get(), 'units' => MaterialUnit::where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(StorePurchaseRequest $request, PurchaseService $service)
    {
        $purchase = $service->create($request->user(), $request->validated());

        return redirect()->route('purchases.show', $purchase)->with('success', 'Purchase saved. Receive it when the materials arrive.');
    }

    public function show(Purchase $purchase)
    {
        Gate::authorize('view', $purchase);
        $purchase->load(['supplier', 'lines.unit', 'lines.returns', 'payments']);

        return view('purchases.show', $this->context() + compact('purchase') + ['amounts' => PurchaseAmounts::summary($purchase)]);
    }

    public function receive(Request $request, Purchase $purchase, PurchaseService $service)
    {
        $service->receive($request->user(), $purchase);

        return back()->with('success', 'Purchase received. Stock updated once.');
    }

    public function payment(PurchasePaymentRequest $request, Purchase $purchase, PurchaseService $service)
    {
        $service->payment($request->user(), $purchase, $request->validated());

        return back()->with('success', 'Supplier payment transaction recorded.');
    }

    public function returnItems(PurchaseReturnRequest $request, Purchase $purchase, PurchaseService $service)
    {
        $service->returnItems($request->user(), $purchase, $request->validated());

        return back()->with('success', 'Return recorded. Stock and supplier credit updated.');
    }
}
