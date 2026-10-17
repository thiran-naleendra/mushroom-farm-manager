<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoicePaymentRequest;
use App\Http\Requests\InvoiceReturnRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\VoidInvoiceRequest;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Services\InvoicePdfService;
use App\Services\InvoiceService;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Invoice::class);
        $filters = $request->validate(['search' => 'nullable|string|max:150', 'customer_id' => 'nullable|integer|exists:customers,id', 'channel' => 'nullable|in:direct,grocery', 'status' => 'nullable|in:confirmed,voided',
            'payment_status' => 'nullable|in:paid,partial,unpaid,credit_due', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'page' => 'nullable|integer|min:1']);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => 'The end date must follow the start date.']);
        }
        $base = Invoice::withSum(['payments as paid_sum' => fn ($q) => $q->where('type', 'payment')], 'amount')->withSum(['payments as refunds_sum' => fn ($q) => $q->where('type', 'refund')], 'amount')->withSum('returns as credits_sum', 'credit');
        $query = Invoice::fromSub($base, 'invoices');
        foreach (['customer_id', 'channel', 'status'] as $key) {
            if ($filters[$key] ?? null) {
                $query->where($key, $filters[$key]);
            }
        }
        if ($filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('number', 'like', '%'.$filters['search'].'%')->orWhere('customer_snapshot->name', 'like', '%'.$filters['search'].'%'));
        }
        if ($filters['from'] ?? null) {
            $query->where('sold_on', '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->where('sold_on', '<=', $filters['to']);
        }
        $balance = '(total - COALESCE(credits_sum, 0) - COALESCE(paid_sum, 0) + COALESCE(refunds_sum, 0))';
        if ($status = $filters['payment_status'] ?? null) {
            $query->whereRaw(match ($status) {
                'paid' => "$balance = 0", 'credit_due' => "$balance < 0", 'partial' => "$balance > 0 AND COALESCE(paid_sum, 0) > COALESCE(refunds_sum, 0)", default => "$balance > 0 AND COALESCE(paid_sum, 0) <= COALESCE(refunds_sum, 0)"
            });
        }

        return view('invoices.index', $this->context() + ['invoices' => $query->orderByDesc('sold_on')->orderByDesc('id')->paginate(15)->withQueryString(), 'customers' => Customer::withTrashed()->orderBy('name')->get(['id', 'name']), 'legacySales' => Sale::orderByDesc('sold_on')->paginate(10, ['*'], 'legacy_page')->withQueryString()]);
    }

    private function context(): array
    {
        return ['farm' => Farm::firstOrFail(), 'section' => 'sales'];
    }

    public function create(ProductService $stock)
    {
        Gate::authorize('create', Invoice::class);
        $products = Product::where('is_active', true)->withSum('movements as stock', 'quantity')->retailFirst()->get();

        return view('invoices.create', $this->context() + ['products' => $products, 'stockLevels' => $stock->stockLevels($products), 'customers' => Customer::where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(StoreInvoiceRequest $request, InvoiceService $service)
    {
        $invoice = $service->create($request->user(), $request->validated());

        return redirect()->route('invoices.show', $invoice)->with('success', 'Invoice confirmed. Stock deducted once.');
    }

    public function show(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        return view('invoices.show', $this->context() + ['invoice' => $invoice->load(['lines.returns', 'payments', 'returns.lines']), 'summary' => $invoice->summary()]);
    }

    public function payment(InvoicePaymentRequest $request, Invoice $invoice, InvoiceService $service)
    {
        $service->payment($request->user(), $invoice, $request->validated());

        return back()->with('success', 'Payment recorded separately from sales revenue.');
    }

    public function returnItems(InvoiceReturnRequest $request, Invoice $invoice, InvoiceService $service)
    {
        $service->returnItems($request->user(), $invoice, $request->validated());

        return back()->with('success', 'Return credited. Only saleable returned items marked for restocking were restored.');
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice, InvoiceService $service)
    {
        $service->void($request->user(), $invoice, $request->validated('reason'));

        return back()->with('success', 'Invoice voided. Remaining quantities restored; any refund due remains in payment history.');
    }

    public function pdf(Invoice $invoice, InvoicePdfService $pdf)
    {
        Gate::authorize('view', $invoice);

        return response($pdf->render($invoice->load(['lines', 'payments', 'returns'])), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    public function print(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        return view('invoices.print', ['invoice' => $invoice->load('lines'), 'summary' => $invoice->summary()]);
    }
}
