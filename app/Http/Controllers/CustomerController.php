<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustCustomerBalanceRequest;
use App\Http\Requests\SaveCustomerRequest;
use App\Models\Customer;
use App\Models\Farm;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Customer::class);
        $filter = $request->validate(['search' => 'nullable|string|max:150', 'type' => 'nullable|in:grocery,restaurant,wholesale,individual', 'status' => 'nullable|in:active,inactive', 'page' => 'nullable|integer|min:1']);
        $customers = Customer::withSum('entries as outstanding_balance', 'amount')
            ->when($filter['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($filter['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('customers.index', ['farm' => Farm::firstOrFail(), 'section' => 'customers', 'customers' => $customers]);
    }

    public function create()
    {
        Gate::authorize('create', Customer::class);

        return $this->form(new Customer(['is_active' => true, 'type' => 'individual', 'credit_limit' => '0']));
    }

    public function edit(Customer $customer)
    {
        Gate::authorize('update', $customer);

        return $this->form($customer);
    }

    private function form(Customer $customer)
    {
        return view('customers.form', ['farm' => Farm::firstOrFail(), 'section' => 'customers', 'customer' => $customer]);
    }

    public function store(SaveCustomerRequest $request, CustomerService $service)
    {
        $customer = $service->save($request->user(), $request->validated());

        return redirect()->route('customers.show', $customer)->with('success', 'Customer created.');
    }

    public function update(SaveCustomerRequest $request, Customer $customer, CustomerService $service)
    {
        $service->save($request->user(), $request->validated(), $customer);

        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    public function show(Customer $customer, CustomerService $service)
    {
        Gate::authorize('view', $customer);

        return view('customers.show', ['farm' => Farm::firstOrFail(), 'section' => 'customers', 'customer' => $customer,
            'balance' => (string) $service->balance($customer), 'sales' => $customer->sales()->orderByDesc('sold_on')->orderByDesc('id')->paginate(10, ['*'], 'sales_page')->withQueryString(),
            'invoices' => $customer->invoices()->orderByDesc('sold_on')->orderByDesc('id')->paginate(10, ['*'], 'invoices_page')->withQueryString(),
            'entries' => $customer->entries()->with('user')->orderByDesc('id')->paginate(15, ['*'], 'entries_page')->withQueryString()]);
    }

    public function adjust(AdjustCustomerBalanceRequest $request, Customer $customer, CustomerService $service)
    {
        $service->adjust($request->user(), $customer, $request->validated());

        return back()->with('success', 'Balance adjustment recorded.');
    }

    public function destroy(Request $request, Customer $customer, CustomerService $service)
    {
        $service->archive($request->user(), $customer);

        return redirect()->route('customers.index')->with('success', 'Customer archived. History is preserved.');
    }
}
