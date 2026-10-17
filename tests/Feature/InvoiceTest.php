<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\FinishedStockMovement;
use App\Models\Harvest;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceReturn;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\FreshStockService;
use App\Services\MaterialService;
use App\Services\PackagingService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private Product $fresh;

    private Product $pack;

    private Product $bag;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'owner']);
        Farm::create(['owner_id' => $this->owner->id, 'name' => 'Original farm', 'mode' => 'simple', 'grocery_sales' => true]);
        $this->customer = app(CustomerService::class)->save($this->owner, ['name' => 'Original buyer', 'type' => 'grocery', 'credit_limit' => '10000', 'is_active' => true]);
        $base = ['variety' => 'Oyster', 'is_active' => true];
        $this->fresh = Product::create($base + ['name' => 'Fresh Oyster', 'sku' => 'FRESH', 'type' => 'fresh', 'unit' => 'kg', 'weight' => 1, 'selling_price' => 500]);
        $this->pack = Product::create($base + ['name' => 'Oyster packets', 'sku' => 'PACK', 'type' => 'packaged', 'pack_size_grams' => 200, 'selling_price' => 40]);
        $this->bag = Product::create($base + ['name' => 'Growing bags', 'sku' => 'BAG', 'type' => 'cultivation_bag', 'unit' => 'bag', 'selling_price' => 10]);
        $this->batch = Batch::create(['code' => 'HIDDEN-INTERNAL', 'variety' => 'Oyster', 'location' => 'Room', 'bags' => 100, 'stage' => 'prepared', 'started_on' => today()->subDays(7)->toDateString()]);
        $this->batch->forceFill(['cost_per_bag' => 2])->save();
        $harvest = Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->subDays(2)->toDateString(), 'weight' => '20.000']);
        $category = MaterialCategory::create(['name' => 'Retail', 'is_active' => true]);
        $poly = app(MaterialService::class)->save($this->owner, ['name' => 'Food bags', 'sku' => 'FOOD', 'material_category_id' => $category->id, 'material_unit_id' => MaterialUnit::where('symbol', 'pc')->value('id'), 'supply_type' => 'retail', 'is_active' => true, 'quantity' => 100, 'minimum_stock' => 0, 'unit_cost' => 2]);
        app(PackagingService::class)->record($this->owner, ['request_id' => (string) Str::uuid(), 'product_id' => $this->pack->id, 'harvest_id' => $harvest->id, 'packaged_on' => today()->subDay()->toDateString(), 'expires_on' => today()->toDateString(), 'packet_count' => 50, 'wastage' => '0', 'polythene_id' => $poly->id, 'packaging_cost' => '0', 'fresh_cost_per_kg' => '100']);
        $this->actingAs($this->owner);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'sold_on' => today()->toDateString(), 'channel' => 'grocery', 'sale_type' => 'cash', 'amount_paid' => '200.00', 'lines' => [
            ['product_id' => $this->fresh->id, 'quantity' => '1.000', 'selling_price' => '500.00', 'discount' => '10.00'],
            ['product_id' => $this->pack->id, 'quantity' => 5, 'selling_price' => '40.00', 'discount' => '0'],
            ['product_id' => $this->bag->id, 'quantity' => 3, 'selling_price' => '10.00', 'discount' => '0'],
        ]], $changes);
    }

    private function invoice(array $changes = []): Invoice
    {
        $this->post('/invoices', $this->input($changes))->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    private function payment(Invoice $invoice, array $changes = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'type' => 'payment', 'method' => 'bank', 'paid_on' => today()->toDateString(), 'amount' => '100.00', 'reference' => 'Bank receipt'], $changes);
    }

    private function returned(Invoice $invoice, array $lines, array $changes = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'returned_on' => today()->toDateString(), 'reason' => 'Customer return', 'lines' => $lines], $changes);
    }

    public function test_multi_product_invoice_deducts_each_inventory_once_and_preserves_prices(): void
    {
        $input = $this->input();
        $this->post('/invoices', $input)->assertSessionHasNoErrors();
        $this->post('/invoices', $input)->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('730.00', $invoice->subtotal);
        $this->assertSame('10.00', $invoice->discount);
        $this->assertSame('720.00', $invoice->total);
        $this->assertSame('520.00', $invoice->summary()['balance']);
        $this->assertSame('partial', $invoice->summary()['payment_status']);
        $this->assertSame(9000, app(FreshStockService::class)->totals()['available']);
        $this->assertSame(45, (int) FinishedStockMovement::sum('quantity'));
        $this->assertSame(97, $this->batch->fresh()->availableBags());
        $this->assertSame('520.00', (string) app(CustomerService::class)->balance($this->customer));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_lines', 3);
        $this->assertDatabaseCount('invoice_payments', 1);
        $this->assertDatabaseCount('invoice_allocations', 3);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->get('/invoices/'.$invoice->id)->assertOk()->assertSee('720.00')->assertDontSee('HIDDEN-INTERNAL');
        $this->get('/invoices/'.$invoice->id.'/print')->assertOk()->assertSee('200g Pack × 5');
        $this->get('/')->assertViewHas('metrics', fn ($m) => $m['retail_packets'] === 45);
        $this->get('/sales')->assertOk()->assertSee($invoice->number);
        $this->get('/sales/create')->assertOk()->assertSee('Amount paid at sale');
    }

    public function test_partial_full_and_retried_payments_do_not_change_stock_or_revenue(): void
    {
        $invoice = $this->invoice();
        $input = $this->payment($invoice, ['amount' => '200']);
        $this->post('/invoices/'.$invoice->id.'/payments', $input)->assertSessionHasNoErrors();
        $this->post('/invoices/'.$invoice->id.'/payments', $input)->assertSessionHasNoErrors();
        $this->assertSame('320.00', $invoice->summary()['balance']);
        $this->post('/invoices/'.$invoice->id.'/payments', array_replace($input, ['amount' => '201']))->assertSessionHasErrors('request_id');
        $this->post('/invoices/'.$invoice->id.'/payments', $this->payment($invoice, ['amount' => '320']))->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->summary()['payment_status']);
        $this->post('/invoices/'.$invoice->id.'/payments', $this->payment($invoice))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('invoice_payments', 3);
        $this->assertSame('0.00', (string) app(CustomerService::class)->balance($this->customer));
        $this->assertSame(9000, app(FreshStockService::class)->totals()['available']);
        $this->assertSame(45, (int) FinishedStockMovement::sum('quantity'));
        $this->get('/')->assertViewHas('metrics', fn ($m) => $m['revenue'] == 720 && $m['outstanding'] == 0);
    }

    public function test_insufficient_stock_in_each_type_rolls_back_all_sales_and_payments(): void
    {
        foreach ([0 => 11, 1 => 51, 2 => 101] as $index => $quantity) {
            $input = $this->input();
            $input['lines'][$index]['quantity'] = $quantity;
            $this->post('/invoices', $input)->assertSessionHasErrors('lines');
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('customer_account_entries', 0);
        $this->assertSame(10000, app(FreshStockService::class)->totals()['available']);
        $this->assertSame(50, (int) FinishedStockMovement::sum('quantity'));
        $this->assertSame(100, $this->batch->fresh()->availableBags());
    }

    public function test_partial_returns_restore_original_stock_once_and_credit_discounted_prices(): void
    {
        $invoice = $this->invoice();
        $lines = $invoice->lines;
        $input = $this->returned($invoice, [
            ['invoice_line_id' => $lines[0]->id, 'quantity' => '0.500', 'restock' => true],
            ['invoice_line_id' => $lines[1]->id, 'quantity' => 2, 'restock' => true],
            ['invoice_line_id' => $lines[2]->id, 'quantity' => 1, 'restock' => true],
        ]);
        $this->post('/invoices/'.$invoice->id.'/returns', $input)->assertSessionHasNoErrors();
        $this->post('/invoices/'.$invoice->id.'/returns', $input)->assertSessionHasNoErrors();
        $this->assertSame('335.00', InvoiceReturn::first()->credit);
        $this->assertSame('185.00', $invoice->summary()['balance']);
        $this->assertSame(9500, app(FreshStockService::class)->totals()['available']);
        $this->assertSame(47, (int) FinishedStockMovement::sum('quantity'));
        $this->assertSame(98, $this->batch->fresh()->availableBags());
        $this->assertSame('22.000000', FinishedStockMovement::whereNotNull('invoice_return_line_id')->first()->unit_cost);
        $this->assertDatabaseCount('invoice_returns', 1);
        $this->assertDatabaseCount('harvests', 1);
        $this->assertSame(20000, app(FreshStockService::class)->totals()['gross']);
        $bad = $this->returned($invoice, [['invoice_line_id' => $lines[0]->id, 'quantity' => 1, 'restock' => true]]);
        $this->post('/invoices/'.$invoice->id.'/returns', $bad)->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('invoice_returns', 1);
    }

    public function test_void_restores_remaining_stock_once_and_keeps_refunds_separate(): void
    {
        $invoice = $this->invoice();
        $line = $invoice->lines()->first();
        $this->post('/invoices/'.$invoice->id.'/returns', $this->returned($invoice, [['invoice_line_id' => $line->id, 'quantity' => '0.5', 'restock' => true]]))->assertSessionHasNoErrors();
        $this->post('/invoices/'.$invoice->id.'/void', ['reason' => 'Order cancelled'])->assertSessionHasNoErrors();
        $this->post('/invoices/'.$invoice->id.'/void', ['reason' => 'Order cancelled'])->assertSessionHasNoErrors();
        $this->assertSame('voided', $invoice->fresh()->status);
        $this->assertSame('-200.00', $invoice->summary()['balance']);
        $this->assertSame('0.00', $invoice->summary()['net']);
        $this->assertSame(10000, app(FreshStockService::class)->totals()['available']);
        $this->assertSame(50, (int) FinishedStockMovement::sum('quantity'));
        $this->assertSame(100, $this->batch->fresh()->availableBags());
        $this->assertDatabaseCount('invoice_returns', 2);
        $refund = $this->payment($invoice, ['type' => 'refund', 'amount' => 200]);
        $this->post('/invoices/'.$invoice->id.'/payments', $refund)->assertSessionHasNoErrors();
        $this->post('/invoices/'.$invoice->id.'/payments', $refund)->assertSessionHasNoErrors();
        $this->assertSame('0.00', $invoice->summary()['balance']);
        $this->assertSame('0.00', (string) app(CustomerService::class)->balance($this->customer));
        $this->assertDatabaseCount('invoice_payments', 2);
        $this->get('/')->assertViewHas('metrics', fn ($m) => $m['revenue'] == 0 && $m['outstanding'] == 0);
    }

    public function test_non_saleable_returns_credit_the_customer_without_restoring_stock(): void
    {
        $invoice = $this->invoice();
        $line = $invoice->lines()->where('type', 'fresh')->first();
        $this->post('/invoices/'.$invoice->id.'/returns', $this->returned($invoice, [['invoice_line_id' => $line->id, 'quantity' => 1, 'restock' => false]]))->assertSessionHasNoErrors();
        $this->assertSame(9000, app(FreshStockService::class)->totals()['available']);
        $this->assertSame('490.00', InvoiceReturn::first()->credit);
        $this->assertSame('30.00', $invoice->summary()['balance']);
    }

    public function test_cumulative_return_rounding_never_exceeds_the_original_discounted_total(): void
    {
        $invoice = $this->invoice(['amount_paid' => 0, 'sale_type' => 'credit', 'lines' => [['product_id' => $this->pack->id, 'quantity' => 3, 'selling_price' => '.01', 'discount' => '.01']]]);
        $line = $invoice->lines()->first();
        foreach (['0.01', '0.00', '0.01'] as $expected) {
            $this->post('/invoices/'.$invoice->id.'/returns', $this->returned($invoice, [['invoice_line_id' => $line->id, 'quantity' => 1, 'restock' => true]]))->assertSessionHasNoErrors();
            $this->assertSame($expected, InvoiceReturn::latest('id')->first()->credit);
        }
        $this->assertSame('0.02', $invoice->summary()['returns']);
        $this->assertSame('0.00', $invoice->summary()['net']);
        $this->assertSame(50, (int) FinishedStockMovement::sum('quantity'));
    }

    public function test_history_and_pdf_preserve_original_product_customer_and_seller_details(): void
    {
        $invoice = $this->invoice();
        $this->fresh->update(['name' => 'New product name', 'selling_price' => 900]);
        $this->customer->update(['name' => 'New customer name']);
        Farm::first()->update(['name' => 'New farm name']);
        $this->get('/invoices/'.$invoice->id)->assertOk()->assertSee('Original farm')->assertSee('Original buyer')->assertSee('Fresh Oyster')->assertSee('500.00');
        $this->get('/invoices/'.$invoice->id.'/print')->assertOk()->assertSee('Original buyer')->assertSee('Print / save PDF');
        $response = $this->get('/invoices/'.$invoice->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Original buyer', $pdf);
        $this->assertStringContainsString('xref', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->get('/customers/'.$this->customer->id)->assertOk()->assertSee($invoice->number);
    }

    public function test_credit_limits_and_inactive_customers_roll_back_the_entire_invoice(): void
    {
        $this->customer->update(['credit_limit' => 0]);
        $this->post('/invoices', $this->input())->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(50, (int) FinishedStockMovement::sum('quantity'));
        $this->invoice(['amount_paid' => 720]);
        $this->assertSame('0.00', (string) app(CustomerService::class)->balance($this->customer));
        $this->customer->update(['is_active' => false]);
        $this->post('/invoices', $this->input(['amount_paid' => 720]))->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_fresh_varieties_units_and_whole_packet_bag_quantities_are_enforced(): void
    {
        $this->fresh->update(['variety' => 'Shiitake']);
        $this->post('/invoices', $this->input())->assertSessionHasErrors('lines');
        $this->fresh->update(['variety' => 'Oyster', 'unit' => 'g']);
        $this->post('/invoices', $this->input(['lines' => [['product_id' => $this->fresh->id, 'quantity' => '.5', 'selling_price' => 1, 'discount' => 0]], 'amount_paid' => 0]))->assertSessionHasErrors('lines');
        foreach ([$this->pack, $this->bag] as $product) {
            $this->post('/invoices', $this->input(['lines' => [['product_id' => $product->id, 'quantity' => '.5', 'selling_price' => 1, 'discount' => 0]], 'amount_paid' => 0]))->assertSessionHasErrors('lines');
        }
        $this->invoice(['lines' => [['product_id' => $this->fresh->id, 'quantity' => 100, 'selling_price' => 1, 'discount' => 0]], 'amount_paid' => 0]);
        $this->assertSame(9900, app(FreshStockService::class)->totals()['available']);
    }

    public function test_dates_overpayments_grocery_flag_and_duplicate_products_are_validated(): void
    {
        $this->post('/invoices', $this->input(['amount_paid' => 721]))->assertSessionHasErrors('amount_paid');
        $this->post('/invoices', $this->input(['sold_on' => today()->subDays(3)->toDateString()]))->assertSessionHasErrors('lines');
        Farm::first()->update(['grocery_sales' => false]);
        $this->post('/invoices', $this->input())->assertSessionHasErrors('channel');
        $this->post('/invoices', $this->input(['channel' => 'direct', 'lines' => [['product_id' => $this->fresh->id, 'quantity' => 1, 'selling_price' => 1, 'discount' => 2]]]))->assertSessionHasErrors('lines');
        $data = $this->input(['channel' => 'direct']);
        $data['lines'][] = $data['lines'][0];
        $this->post('/invoices', $data)->assertSessionHasErrors('lines.0.product_id');
        $invoice = $this->invoice(['channel' => 'direct']);
        $this->post('/invoices/'.$invoice->id.'/payments', $this->payment($invoice, ['paid_on' => today()->subDay()->toDateString()]))->assertSessionHasErrors('paid_on');
        $this->post('/invoices/'.$invoice->id.'/payments', $this->payment($invoice, ['type' => 'refund']))->assertSessionHasErrors('amount');
        $this->post('/invoices/'.$invoice->id.'/returns', $this->returned($invoice, [['invoice_line_id' => $invoice->lines()->first()->id, 'quantity' => 1, 'restock' => true]], ['returned_on' => today()->subDay()->toDateString()]))->assertSessionHasErrors('returned_on');
    }

    public function test_direct_cash_walk_in_sales_require_full_payment_without_a_customer_account(): void
    {
        $input = $this->input(['customer_id' => null, 'customer_name' => 'Walk-in buyer', 'channel' => 'direct', 'amount_paid' => 720]);
        $this->post('/invoices', array_replace($input, ['amount_paid' => 0]))->assertSessionHasErrors('customer_id');
        $this->post('/invoices', $input)->assertSessionHasNoErrors();
        $this->assertNull(Invoice::first()->customer_id);
        $this->assertSame('paid', Invoice::first()->summary()['payment_status']);
        $this->assertDatabaseCount('customer_account_entries', 0);
    }

    public function test_manager_permissions_worker_restrictions_and_owner_only_voids(): void
    {
        Role::where('name', 'manager')->update(['is_active' => true]);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $invoice = $this->invoice();
        $this->get('/invoices/'.$invoice->id)->assertOk();
        $this->post('/invoices/'.$invoice->id.'/payments', $this->payment($invoice))->assertSessionHasNoErrors();
        $this->post('/invoices/'.$invoice->id.'/void', ['reason' => 'Cancel'])->assertForbidden();
        Role::where('name', 'worker')->update(['is_active' => true]);
        $worker = User::factory()->create(['role' => 'worker']);
        $this->actingAs($worker)->get('/sales')->assertForbidden();
        $this->get('/invoices/'.$invoice->id)->assertForbidden();
        $this->get('/invoices/'.$invoice->id.'/pdf')->assertForbidden();
        $this->get('/invoices/'.$invoice->id.'/print')->assertForbidden();
        $this->post('/invoices', $this->input())->assertForbidden();
        $this->post('/invoices/'.$invoice->id.'/payments', $this->payment($invoice))->assertForbidden();
        $this->post('/invoices/'.$invoice->id.'/returns', [])->assertForbidden();
        Role::where('name', 'manager')->update(['is_active' => false]);
        $this->actingAs($manager)->get('/sales')->assertRedirect('/login');
    }

    public function test_invoice_filters_and_payment_states_use_real_totals(): void
    {
        $invoice = $this->invoice(['sale_type' => 'credit', 'amount_paid' => 0]);
        $this->get('/sales?payment_status=unpaid&search=Original')->assertOk()->assertSee($invoice->number);
        $this->get('/sales?payment_status=paid')->assertOk()->assertSee('No invoices recorded.');
        $this->get('/sales?channel=direct')->assertOk()->assertSee('No invoices recorded.');
        $this->get('/sales?customer_id='.$this->customer->id)->assertOk()->assertSee($invoice->number);
        $this->get('/sales?from='.today()->toDateString().'&to='.today()->subDay()->toDateString())->assertSessionHasErrors('to');
    }

    public function test_returns_recover_archived_catalog_entries(): void
    {
        $invoice = $this->invoice(['amount_paid' => 0, 'sale_type' => 'credit', 'lines' => [['product_id' => $this->pack->id, 'quantity' => 50, 'selling_price' => 1, 'discount' => 0]]]);
        app(ProductService::class)->archive($this->owner, $this->pack);
        $line = $invoice->lines()->first();
        $this->post('/invoices/'.$invoice->id.'/returns', $this->returned($invoice, [['invoice_line_id' => $line->id, 'quantity' => 1, 'restock' => true]]))->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($this->pack);
        $this->assertFalse($this->pack->fresh()->is_active);
        $this->assertSame(1, (int) FinishedStockMovement::sum('quantity'));
    }

    public function test_immutable_invoice_lines_payments_and_returns_preserve_audit_history(): void
    {
        $invoice = $this->invoice();
        $line = $invoice->lines()->first();
        $this->post('/invoices/'.$invoice->id.'/returns', $this->returned($invoice, [['invoice_line_id' => $line->id, 'quantity' => '.5', 'restock' => true]]))->assertSessionHasNoErrors();
        foreach ([$invoice, $line, InvoicePayment::first(), InvoiceReturn::first()] as $record) {
            try {
                $record->delete();
                $this->fail('History deletion was permitted.');
            } catch (LogicException) {
                $this->assertTrue($record->exists);
            }
        }
        $this->expectException(LogicException::class);
        $invoice->update(['total' => 1]);
    }

    public function test_expired_packets_remain_on_hand_but_cannot_be_sold(): void
    {
        $this->travel(1)->days();
        $this->post('/invoices', $this->input(['amount_paid' => 0, 'sale_type' => 'credit', 'lines' => [['product_id' => $this->pack->id, 'quantity' => 1, 'selling_price' => 1, 'discount' => 0]]]))->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(50, (int) FinishedStockMovement::sum('quantity'));
    }

    public function test_backdated_refunds_cannot_precede_the_return_credit(): void
    {
        $invoice = $this->invoice(['sold_on' => today()->subDay()->toDateString()]);
        $this->post('/invoices/'.$invoice->id.'/void', ['reason' => str_repeat('x', 1000)])->assertSessionHasNoErrors();
        $this->post('/invoices/'.$invoice->id.'/payments', $this->payment($invoice, ['type' => 'refund', 'amount' => 200, 'paid_on' => today()->subDay()->toDateString()]))->assertSessionHasErrors('amount');
        $this->assertSame($this->owner->id, $invoice->fresh()->voided_by);
        $this->assertNotNull($invoice->fresh()->voided_at);
        $this->assertSame(1000, strlen(InvoiceReturn::first()->reason));
    }

    public function test_legacy_sales_with_retry_tokens_do_not_deduct_stock_or_charge_twice(): void
    {
        $input = ['request_id' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'amount_paid' => 0, 'sold_on' => today()->toDateString(), 'weight' => 1, 'price_per_kg' => 100];
        $this->post('/sales', $input)->assertSessionHasNoErrors();
        $this->post('/sales', $input)->assertSessionHasNoErrors();
        $this->post('/sales', array_replace($input, ['weight' => 2]))->assertSessionHasErrors('request_id');
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(9000, app(FreshStockService::class)->totals()['available']);
        $this->assertSame('100.00', (string) app(CustomerService::class)->balance($this->customer));
    }

    public function test_backdated_invoices_cannot_take_stock_needed_by_existing_invoices(): void
    {
        $this->invoice(['amount_paid' => 0, 'sale_type' => 'credit', 'lines' => [['product_id' => $this->pack->id, 'quantity' => 45, 'selling_price' => 1, 'discount' => 0]]]);
        $this->post('/invoices', $this->input(['sold_on' => today()->subDay()->toDateString(), 'amount_paid' => 0, 'sale_type' => 'credit', 'lines' => [['product_id' => $this->pack->id, 'quantity' => 10, 'selling_price' => 1, 'discount' => 0]]]))->assertSessionHasErrors('lines');
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(5, (int) FinishedStockMovement::sum('quantity'));
    }

    public function test_production_corrections_cannot_clear_bags_allocated_to_invoices(): void
    {
        $this->invoice();
        $this->patch('/production/'.$this->batch->id.'/correct', ['sold_bags' => 0, 'damaged_bags' => 0, 'reason' => 'Attempt to clear sold bags'])->assertSessionHasErrors('sold_bags');
        $this->assertSame(3, $this->batch->fresh()->sold_bags);
    }

    public function test_pdf_pagination_and_cross_reference_offsets_are_valid(): void
    {
        $invoice = $this->invoice(['notes' => str_repeat('Long invoice note (escaped) \\ ', 60)]);
        $pdf = $this->get('/invoices/'.$invoice->id.'/pdf')->assertOk()->getContent();
        $this->assertStringContainsString('/Count 2', $pdf);
        preg_match('/startxref\n(\d+)/', $pdf, $match);
        $this->assertSame('xref', substr($pdf, (int) $match[1], 4));
        preg_match_all('/(\d{10}) 00000 n/', $pdf, $offsets);
        foreach ($offsets[1] as $index => $offset) {
            $this->assertStringStartsWith(($index + 1).' 0 obj', substr($pdf, (int) $offset));
        }
    }
}
