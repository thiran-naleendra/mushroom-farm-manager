<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Expense;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Invoice;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\MaterialService;
use App\Services\PackagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->farm = Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->actingAs($this->owner);
    }

    private function batch(): Batch
    {
        $batch = Batch::create(['code' => 'INTERNAL', 'variety' => 'Oyster', 'location' => 'Room', 'bags' => 100, 'stage' => 'harvesting', 'started_on' => today()->toDateString()]);
        $batch->forceFill(['production_status' => 'confirmed', 'currency' => 'LKR', 'total_cost' => '1000', 'cost_per_bag' => '10', 'labour_cost' => '100', 'additional_cost' => '50'])->save();

        return $batch;
    }

    private function report(): array
    {
        return app(FinancialService::class)->report($this->farm, today()->startOfMonth()->toDateString(), today()->toDateString());
    }

    public function test_expense_crud_categories_filters_audit_and_empty_reports(): void
    {
        $this->get('/reports')->assertOk()->assertSee('0.00')->assertSee('Profit is an estimate');
        $this->post('/expense-categories', ['name' => 'Electricity'])->assertSessionHasNoErrors();
        $input = ['description' => 'Power', 'spent_on' => today()->toDateString(), 'amount' => '12.34', 'expense_category_id' => DB::table('expense_categories')->value('id'), 'treatment' => 'operating'];
        $this->post('/expenses', $input)->assertSessionHasNoErrors();
        $expense = Expense::firstOrFail();
        $this->get('/expenses?search=Power')->assertOk()->assertSee('Power');
        $this->get('/expenses/create')->assertOk();
        $this->get('/expenses/'.$expense->id.'/edit')->assertOk()->assertSee('created');
        $this->put('/expenses/'.$expense->id, array_replace($input, ['amount' => '14.56', 'revision' => 1]))->assertSessionHasNoErrors();
        $this->put('/expenses/'.$expense->id, array_replace($input, ['revision' => 1]))->assertSessionHasErrors('revision');
        $this->assertSame('14.56', $this->report()['operating_expenses']);
        $this->delete('/expenses/'.$expense->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
        $this->assertDatabaseCount('expense_audits', 3);
        $this->assertSame('0.00', $this->report()['operating_expenses']);
    }

    public function test_capitalized_payments_are_capped_and_not_expensed_twice(): void
    {
        $batch = $this->batch();
        $input = ['description' => 'Production labour paid', 'spent_on' => today()->toDateString(), 'amount' => '100', 'batch_id' => $batch->id, 'treatment' => 'production_payment'];
        $this->post('/expenses', $input)->assertSessionHasNoErrors();
        $this->post('/expenses', array_replace($input, ['amount' => '50.01']))->assertSessionHasErrors('amount');
        $report = $this->report();
        $this->assertSame('0.00', $report['operating_expenses']);
        $this->assertSame('100.00', $report['expense_payments']);
        $this->assertSame('1000.000000', $batch->fresh()->total_cost);
        $this->get('/reports')->assertOk()->assertSee('100.00');
    }

    public function test_cost_transfers_sales_payments_and_returns_use_original_allocations(): void
    {
        $batch = $this->batch();
        $harvest = Harvest::create(['batch_id' => $batch->id, 'harvested_on' => today()->toDateString(), 'weight' => '10.000']);
        $this->post('/reports/harvests/'.$harvest->id.'/cost', ['amount' => '600'])->assertSessionHasNoErrors();
        $this->assertSame('60.000000', $harvest->fresh()->cost_per_kg);
        $this->assertSame('4.000000', app(FinancialService::class)->bagUnitCost($batch));
        $second = Harvest::create(['batch_id' => $batch->id, 'harvested_on' => today()->toDateString(), 'weight' => '5.000']);
        $this->post('/reports/harvests/'.$second->id.'/cost', ['amount' => '400.01'])->assertSessionHasErrors('amount');
        $this->post('/reports/harvests/'.$harvest->id.'/cost', ['amount' => '600'])->assertSessionHasErrors('amount');
        $product = Product::create(['name' => 'Fresh', 'sku' => 'F', 'type' => 'fresh', 'unit' => 'kg', 'weight' => 1, 'variety' => 'Oyster', 'is_active' => true]);
        $this->post('/invoices', ['request_id' => (string) Str::uuid(), 'sold_on' => today()->toDateString(), 'channel' => 'direct', 'sale_type' => 'cash', 'customer_name' => 'Buyer', 'amount_paid' => '200', 'lines' => [['product_id' => $product->id, 'quantity' => '2', 'selling_price' => '100', 'discount' => '0']]])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $report = $this->report();
        $this->assertSame('200.00', $report['revenue']);
        $this->assertSame('120.00', $report['cogs']);
        $this->assertSame('80.00', $report['net_profit']);
        $this->assertSame('200.00', $report['receipts']);
        $this->assertSame(0, $report['unknown_costs']);
        $this->get('/')->assertViewHas('metrics', fn ($m) => $m['profit'] == 80);
        $line = $invoice->lines()->firstOrFail();
        $this->post('/invoices/'.$invoice->id.'/returns', ['request_id' => (string) Str::uuid(), 'returned_on' => today()->toDateString(), 'reason' => 'Saleable return', 'lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'restock' => true]]])->assertSessionHasNoErrors();
        $report = $this->report();
        $this->assertSame('100.00', $report['revenue']);
        $this->assertSame('60.00', $report['cogs']);
        $this->assertSame('40.00', $report['net_profit']);
        $this->assertSame('200.00', $report['receipts']);
        $this->post('/reports/harvests/'.$second->id.'/cost', ['amount' => '10'])->assertSessionHasNoErrors();
    }

    public function test_financial_actions_are_owner_only_and_validate_input(): void
    {
        $this->post('/expenses', ['description' => 'Invalid', 'spent_on' => today()->addDay()->toDateString(), 'amount' => '-1'])->assertSessionHasErrors(['spent_on', 'amount']);
        $manager = User::factory()->create(['role' => 'manager']);
        Role::where('name', 'manager')->update(['is_active' => true]);
        $this->actingAs($manager);
        $this->get('/reports')->assertForbidden();
        $this->get('/expenses')->assertForbidden();
        $this->post('/expense-categories', ['name' => 'Rent'])->assertForbidden();
        $this->post('/expenses', [])->assertForbidden();
    }

    public function test_packaging_transfers_cost_and_wasted_returns_do_not_reverse_cogs(): void
    {
        $batch = $this->batch();
        $harvest = Harvest::create(['batch_id' => $batch->id, 'harvested_on' => today()->toDateString(), 'weight' => '10.000']);
        app(FinancialService::class)->allocateHarvest($this->owner, $harvest, '600');
        $product = Product::create(['name' => 'Packets', 'sku' => 'P', 'type' => 'packaged', 'pack_size_grams' => 200, 'variety' => 'Oyster', 'is_active' => true]);
        $category = MaterialCategory::create(['name' => 'Retail', 'is_active' => true]);
        $material = app(MaterialService::class)->save($this->owner, ['name' => 'Food bags', 'sku' => 'POLY', 'material_category_id' => $category->id, 'material_unit_id' => MaterialUnit::where('symbol', 'pc')->value('id'), 'supply_type' => 'retail', 'is_active' => true, 'quantity' => 20, 'minimum_stock' => 0, 'unit_cost' => 2]);
        $run = app(PackagingService::class)->record($this->owner, ['request_id' => (string) Str::uuid(), 'product_id' => $product->id, 'harvest_id' => $harvest->id, 'packaged_on' => today()->toDateString(), 'packet_count' => 10, 'wastage' => '0', 'polythene_id' => $material->id, 'packaging_cost' => '10', 'fresh_cost_per_kg' => '999']);
        $this->assertSame('120.000000', $run->fresh_cost);
        $this->assertSame('150.000000', $run->total_cost);
        $this->assertSame('15.000000', $run->cost_per_packet);
        $this->assertSame('0.00', $this->report()['operating_expenses']);
        $this->post('/invoices', ['request_id' => (string) Str::uuid(), 'sold_on' => today()->toDateString(), 'channel' => 'direct', 'sale_type' => 'cash', 'customer_name' => 'Buyer', 'amount_paid' => '100', 'lines' => [['product_id' => $product->id, 'quantity' => '5', 'selling_price' => '20', 'discount' => '0']]])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('75.00', $this->report()['cogs']);
        $this->assertSame(['quantity' => 5, 'cost_per_packet' => '15.00', 'profit_per_packet' => '5.00'], $this->report()['retail_packets']);
        $line = $invoice->lines()->firstOrFail();
        $this->post('/invoices/'.$invoice->id.'/returns', ['request_id' => (string) Str::uuid(), 'returned_on' => today()->toDateString(), 'reason' => 'Spoiled', 'lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'restock' => false]]])->assertSessionHasNoErrors();
        $this->assertSame('75.00', $this->report()['cogs']);
        $this->assertSame('5.00', $this->report()['net_profit']);
        $this->assertSame(['quantity' => 4, 'cost_per_packet' => '18.75', 'profit_per_packet' => '1.25'], $this->report()['retail_packets']);
        $this->post('/invoices/'.$invoice->id.'/payments', ['request_id' => (string) Str::uuid(), 'type' => 'refund', 'method' => 'cash', 'paid_on' => today()->toDateString(), 'amount' => '20'])->assertSessionHasNoErrors();
        $this->assertSame('80.00', $this->report()['receipts']);
        $this->assertSame('5.00', $this->report()['net_profit']);
    }
}
