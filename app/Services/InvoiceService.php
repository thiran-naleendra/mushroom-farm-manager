<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Farm;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceReturn;
use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class InvoiceService
{
    public function __construct(private ProductService $stock, private CustomerService $customers) {}

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function retry($existing, User $actor, string $hash, ?int $invoiceId = null): void
    {
        if ($existing->user_id !== $actor->id || $existing->payload_hash !== $hash || ($invoiceId !== null && $existing->invoice_id !== $invoiceId)) {
            throw ValidationException::withMessages(['request_id' => 'This reference has already been used. Reload the form.']);
        }
    }

    public function create(User $actor, array $data): Invoice
    {
        return DB::transaction(function () use ($actor, $data) {
            $farm = Farm::lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('create', Invoice::class);
            $hash = $this->hash($data);
            if ($existing = Invoice::where('request_id', $data['request_id'])->first()) {
                $this->retry($existing, $actor, $hash);

                return $existing;
            }
            if ($data['channel'] === 'grocery' && ! $farm->grocery_sales) {
                throw ValidationException::withMessages(['channel' => 'Enable grocery sales in Farm Settings first.']);
            }
            $customer = empty($data['customer_id']) ? null : Customer::lockForUpdate()->find($data['customer_id']);
            if ((! empty($data['customer_id']) && (! $customer || ! $customer->is_active || $customer->currency !== $farm->currency)) || ((! $customer) && ($data['sale_type'] === 'credit' || $data['channel'] === 'grocery'))) {
                throw ValidationException::withMessages(['customer_id' => 'Choose an active customer with the current farm currency.']);
            }
            if ($data['sale_type'] === 'credit' && BigDecimal::of($data['amount_paid'])->isPositive()) {
                throw ValidationException::withMessages(['amount_paid' => 'Credit invoices start unpaid. Record subsequent cash or bank payments separately.']);
            }
            $products = Product::whereIn('id', array_column($data['lines'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $subtotal = BigDecimal::of('0.00');
            $discount = BigDecimal::of('0.00');
            foreach ($data['lines'] as $input) {
                $product = $products->get($input['product_id']);
                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages(['lines' => 'Choose active products.']);
                }
                $quantity = BigDecimal::of($input['quantity'])->toScale(3);
                $price = PurchaseAmounts::money($input['selling_price']);
                $gross = $quantity->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);
                $reduction = PurchaseAmounts::money($input['discount']);
                if ($reduction->isGreaterThan($gross)) {
                    throw ValidationException::withMessages(['lines' => 'A line discount cannot exceed its subtotal.']);
                }
                $lines[] = ['product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'variety' => $product->variety, 'type' => $product->type,
                    'unit' => $product->type === 'fresh' ? $product->unit : ($product->type === 'packaged' ? 'packet' : 'bag'), 'pack_size_grams' => $product->pack_size_grams,
                    'quantity' => (string) $quantity, 'stock_quantity' => $this->stock->stockQuantity($product->type, $product->unit, (string) $quantity),
                    'selling_price' => (string) $price, 'subtotal' => (string) $gross, 'discount' => (string) $reduction, 'total' => (string) $gross->minus($reduction)];
                $subtotal = $subtotal->plus($gross);
                $discount = $discount->plus($reduction);
            }
            if ($subtotal->isGreaterThan('99999999999999.99')) {
                throw ValidationException::withMessages(['lines' => 'Invoice total exceeds the supported limit.']);
            }
            $total = $subtotal->minus($discount);
            if (! $customer && BigDecimal::of($data['amount_paid'])->isLessThan($total)) {
                throw ValidationException::withMessages(['customer_id' => 'Select a customer account for partial or unpaid sales.']);
            }
            if (BigDecimal::of($data['amount_paid'])->isGreaterThan($total)) {
                throw ValidationException::withMessages(['amount_paid' => 'Initial payment cannot exceed the invoice total.']);
            }
            $invoice = Invoice::create(['number' => 'INV-'.Str::ulid(), 'user_id' => $actor->id, 'customer_id' => $customer?->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash,
                'sold_on' => $data['sold_on'], 'channel' => $data['channel'], 'sale_type' => $data['sale_type'], 'currency' => $farm->currency,
                'seller_snapshot' => $farm->only(['name', 'address', 'phone', 'email']), 'customer_snapshot' => $customer?->only(['name', 'address', 'phone', 'email']) ?? ['name' => $data['customer_name']],
                'subtotal' => (string) $subtotal, 'discount' => (string) $discount, 'total' => (string) $total, 'notes' => $data['notes'] ?? null]);
            foreach ($lines as $line) {
                $this->stock->allocate($actor, $invoice->lines()->create($line), $invoice->sold_on);
            }
            $this->account($actor, $invoice, (string) $total, 'invoice', $invoice->id);
            if (BigDecimal::of($data['amount_paid'])->isPositive()) {
                $this->payment($actor, $invoice, ['request_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'invoice-initial/'.$invoice->id), 'type' => 'payment', 'method' => $data['sale_type'], 'amount' => $data['amount_paid'], 'paid_on' => $invoice->sold_on, 'reference' => $data['payment_reference'] ?? null]);
            }
            $this->checkCreditLimit($invoice);

            return $invoice;
        });
    }

    private function locked(User $actor, Invoice $invoice, string $ability): Invoice
    {
        Farm::lockForUpdate()->firstOrFail();
        $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
        Gate::forUser($actor->fresh())->authorize($ability, $invoice);

        return $invoice;
    }

    public function payment(User $actor, Invoice $invoice, array $data): InvoicePayment
    {
        return DB::transaction(function () use ($actor, $invoice, $data) {
            $invoice = $this->locked($actor, $invoice, 'pay');
            $hash = $this->hash($data);
            if ($existing = InvoicePayment::where('request_id', $data['request_id'])->first()) {
                $this->retry($existing, $actor, $hash, $invoice->id);

                return $existing;
            }
            if ($data['paid_on'] < $invoice->sold_on) {
                throw ValidationException::withMessages(['paid_on' => 'Payment date cannot precede the invoice.']);
            }
            $amount = PurchaseAmounts::money($data['amount']);
            $now = PurchaseAmounts::money($invoice->summary()['balance']);
            $then = PurchaseAmounts::money($invoice->summary($data['paid_on'])['balance']);
            $refund = $data['type'] === 'refund';
            $limit = $refund ? BigDecimal::min($now->negated(), $then->negated()) : BigDecimal::min($now, $then);
            if (! $amount->isPositive() || $amount->isGreaterThan($limit) || (! $refund && $invoice->status === 'voided')) {
                throw ValidationException::withMessages(['amount' => $refund ? 'Refund cannot exceed the refundable credit available on this date.' : 'Payment cannot exceed the outstanding invoice balance on this date.']);
            }
            $payment = $invoice->payments()->create(['user_id' => $actor->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'type' => $data['type'], 'method' => $data['method'], 'paid_on' => $data['paid_on'], 'amount' => (string) $amount, 'reference' => $data['reference'] ?? null]);
            $this->account($actor, $invoice, (string) ($refund ? $amount : $amount->negated()), $refund ? 'invoice_refund' : 'invoice_payment', $payment->id);
            $this->checkCreditLimit($invoice);

            return $payment;
        });
    }

    public function returnItems(User $actor, Invoice $invoice, array $data): InvoiceReturn
    {
        return DB::transaction(function () use ($actor, $invoice, $data) {
            $invoice = $this->locked($actor, $invoice, 'returnItems');
            $hash = $this->hash($data);
            if ($existing = InvoiceReturn::where('request_id', $data['request_id'])->first()) {
                $this->retry($existing, $actor, $hash, $invoice->id);

                return $existing;
            }
            if ($invoice->status === 'voided' || $data['returned_on'] < $invoice->sold_on) {
                throw ValidationException::withMessages(['returned_on' => 'Returns must follow a confirmed invoice date.']);
            }
            $return = $invoice->returns()->create(['user_id' => $actor->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'returned_on' => $data['returned_on'], 'reason' => $data['reason'], 'credit' => '0.00']);
            $credit = BigDecimal::of('0.00');
            foreach ($data['lines'] as $input) {
                $line = $invoice->lines()->lockForUpdate()->find($input['invoice_line_id']);
                if (! $line) {
                    throw ValidationException::withMessages(['lines' => 'Returned lines must belong to this invoice.']);
                }
                $quantity = $this->stock->stockQuantity($line->type, $line->unit, (string) $input['quantity']);
                $previous = (int) $line->returns()->sum('stock_quantity');
                if ($quantity <= 0 || $previous + $quantity > $line->stock_quantity) {
                    throw ValidationException::withMessages(['lines' => 'Return quantity exceeds the remaining sold quantity.']);
                }
                // Cumulative rounding makes a full series of partial returns equal the original discounted total.
                $cumulative = BigDecimal::of($line->total)->multipliedBy($previous + $quantity)->dividedBy($line->stock_quantity, 2, RoundingMode::HalfUp);
                $lineCredit = $cumulative->minus(PurchaseAmounts::money($line->returns()->sum('credit')));
                $returned = $return->lines()->create(['invoice_line_id' => $line->id, 'quantity' => (string) BigDecimal::of($input['quantity'])->toScale(3), 'stock_quantity' => $quantity, 'credit' => (string) $lineCredit, 'restock' => $input['restock']]);
                $this->stock->restore($actor, $line, $returned, $data['returned_on']);
                $credit = $credit->plus($lineCredit);
            }
            DB::table('invoice_returns')->where('id', $return->id)->update(['credit' => (string) $credit]);
            $this->account($actor, $invoice, (string) $credit->negated(), 'invoice_return', $return->id);

            return $return->refresh();
        });
    }

    public function void(User $actor, Invoice $invoice, string $reason): void
    {
        DB::transaction(function () use ($actor, $invoice, $reason) {
            $invoice = $this->locked($actor, $invoice, 'void');
            if ($invoice->status === 'voided') {
                return;
            }
            $lines = [];
            foreach ($invoice->lines as $line) {
                $remaining = $line->stock_quantity - (int) $line->returns()->sum('stock_quantity');
                if ($remaining > 0) {
                    $qty = $line->type === 'fresh' && $line->unit === 'kg' ? FreshQuantity::kg($remaining) : (string) $remaining;
                    $lines[] = ['invoice_line_id' => $line->id, 'quantity' => $qty, 'restock' => true];
                }
            }
            if ($lines !== []) {
                $this->returnItems($actor, $invoice, ['request_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'invoice-void/'.$invoice->id), 'returned_on' => today()->toDateString(), 'reason' => 'Void: '.Str::substr($reason, 0, 994), 'lines' => $lines]);
            }
            $invoice->update(['status' => 'voided', 'void_reason' => $reason, 'voided_by' => $actor->id, 'voided_at' => now()]);
        });
    }

    private function account(User $actor, Invoice $invoice, string $amount, string $type, int $source): void
    {
        if (! $invoice->customer_id) {
            return;
        }
        $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($invoice->customer_id);
        $customer->entries()->create(['user_id' => $actor->id, 'invoice_id' => $invoice->id, 'invoice_payment_id' => in_array($type, ['invoice_payment', 'invoice_refund']) ? $source : null,
            'invoice_return_id' => $type === 'invoice_return' ? $source : null, 'request_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $type.'/'.$source), 'type' => $type, 'amount' => $amount, 'currency' => $invoice->currency, 'reason' => $invoice->number.' · '.str_replace('_', ' ', $type)]);
    }

    private function checkCreditLimit(Invoice $invoice): void
    {
        if ($invoice->customer_id) {
            $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($invoice->customer_id);
            if ($this->customers->balance($customer)->isGreaterThan($customer->credit_limit)) {
                throw ValidationException::withMessages(['customer_id' => 'This invoice would exceed the customer credit limit.']);
            }
        }
    }
}
