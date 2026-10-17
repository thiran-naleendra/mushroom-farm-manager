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
use App\Models\Sale;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\CustomerService;
use App\Services\MaterialService;
use App\Services\PackagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->farm = Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple', 'grocery_sales' => true]);
        $this->actingAs($this->owner);
    }

    private function url(string $report, array $extra = []): string
    {
        return '/reports/analytics?'.http_build_query($extra + ['report' => $report, 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()]);
    }

    private function sale(): Invoice
    {
        $customer = app(CustomerService::class)->save($this->owner, ['name' => 'Grocery', 'type' => 'grocery', 'credit_limit' => '10000', 'is_active' => true]);
        $harvest = Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->toDateString(), 'weight' => '10']);
        $product = Product::create(['name' => 'Oyster 200g', 'sku' => 'OYS200', 'variety' => 'Oyster', 'type' => 'packaged', 'pack_size_grams' => 200, 'is_active' => true]);
        $category = MaterialCategory::create(['name' => 'Retail', 'is_active' => true]);
        $poly = app(MaterialService::class)->save($this->owner, ['name' => '=2+3', 'sku' => 'POLY', 'material_category_id' => $category->id, 'material_unit_id' => MaterialUnit::where('symbol', 'pc')->value('id'), 'supply_type' => 'retail', 'quantity' => 100, 'minimum_stock' => 60, 'unit_cost' => 2, 'is_active' => true]);
        app(PackagingService::class)->record($this->owner, ['request_id' => (string) Str::uuid(), 'product_id' => $product->id, 'harvest_id' => $harvest->id, 'packaged_on' => today()->toDateString(), 'packet_count' => 50, 'wastage' => '0', 'polythene_id' => $poly->id, 'packaging_cost' => '0', 'fresh_cost_per_kg' => '100']);
        $this->post('/invoices', ['request_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'channel' => 'grocery', 'sale_type' => 'credit', 'amount_paid' => '0', 'sold_on' => today()->toDateString(), 'lines' => [['product_id' => $product->id, 'quantity' => 10, 'selling_price' => 40, 'discount' => 5]]])->assertSessionHasNoErrors();
        Expense::create(['description' => 'Power', 'spent_on' => today()->toDateString(), 'amount' => 25, 'currency' => 'LKR']);

        return Invoice::firstOrFail();
    }

    public function test_all_reports_have_empty_states_and_print_and_csv_endpoints(): void
    {
        foreach (array_keys(AnalyticsService::REPORTS) as $report) {
            $this->get($this->url($report))->assertOk()->assertSee('No records for these filters.');
            $this->get($this->url($report, ['format' => 'print']))->assertOk()->assertSee('Print report');
            $response = $this->get($this->url($report, ['format' => 'csv']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
            $this->assertNotEmpty($response->streamedContent());
        }
    }

    public function test_financial_reports_use_original_costs_returns_and_separate_payments(): void
    {
        $invoice = $this->sale();
        $this->post('/invoices/'.$invoice->id.'/returns', ['request_id' => (string) Str::uuid(), 'returned_on' => today()->toDateString(), 'reason' => 'Saleable', 'lines' => [['invoice_line_id' => $invoice->lines()->value('id'), 'quantity' => 2, 'restock' => true]]])->assertSessionHasNoErrors();
        $this->post('/invoices/'.$invoice->id.'/payments', ['request_id' => (string) Str::uuid(), 'type' => 'payment', 'method' => 'cash', 'amount' => 100, 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();
        foreach (['daily', 'monthly', 'yearly'] as $group) {
            $this->get($this->url('sales', ['group' => $group]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->revenue == 316);
        }
        $this->get($this->url('profits'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()->revenue == 316 && $rows->first()->cogs == 176 && $rows->first()->gross_profit == 140 && $rows->first()->net_profit == 115);
        $this->get($this->url('products'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->quantity == 8 && $rows->first()->gross_profit == 140);
        $this->get($this->url('outstanding'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->balance == 216);
        Product::firstOrFail()->update(['selling_price' => 999]);
        $this->get($this->url('products'))->assertViewHas('rows', fn ($rows) => $rows->first()->revenue == 316);
    }

    public function test_stock_consumption_and_csv_escape_use_ledger_values(): void
    {
        $this->sale();
        $this->get($this->url('stock', ['low_stock' => 1]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()->quantity == 50 && $rows->first()->inventory_value == 100 && $rows->first()->low_stock == 1);
        $this->get($this->url('consumption'))->assertViewHas('rows', fn ($rows) => $rows->first()->quantity == 50 && $rows->first()->recorded_cost == 100);
        $csv = $this->get($this->url('stock', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=2+3", $csv);
        $this->assertStringContainsString('50.000000', $csv);
        $this->get($this->url('stock', ['to' => today()->subDay()->toDateString(), 'from' => today()->subMonth()->toDateString()]))->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        app(MaterialService::class)->save($this->owner, ['name' => 'Trace ingredient', 'sku' => 'TRACE', 'material_category_id' => MaterialCategory::firstOrFail()->id, 'material_unit_id' => MaterialUnit::where('symbol', 'kg')->value('id'), 'supply_type' => 'growing', 'quantity' => '0.000001', 'minimum_stock' => 0, 'unit_cost' => 100, 'is_active' => true]);
        $this->get($this->url('stock'))->assertOk()->assertSee('0.000001');
    }

    public function test_operational_roles_cannot_read_or_export_financial_fields(): void
    {
        $this->sale();
        Role::where('name', 'manager')->update(['is_active' => true]);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager);
        $this->get('/reports/analytics')->assertOk();
        foreach (['sales', 'products', 'profits', 'costs', 'outstanding'] as $report) {
            foreach (['csv', 'print'] as $format) {
                $this->get($this->url($report, ['format' => $format]))->assertForbidden();
            }
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($this->url('stock'))->assertOk()->assertDontSee('Recorded stock value')->assertDontSee('Financial calculations');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        $this->assertStringNotContainsString('value_change', $queries);
        $this->assertStringNotContainsString('inventory_value', $queries);
        DB::disableQueryLog();
        $csv = $this->get($this->url('consumption', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('carrying cost', $csv);
        Role::where('name', 'worker')->update(['is_active' => true]);
        $worker = User::factory()->create(['role' => 'worker', 'can_work_harvest' => true]);
        $this->actingAs($worker)->get($this->url('harvests'))->assertOk();
        $this->get($this->url('production'))->assertForbidden();
        $this->get($this->url('stock'))->assertForbidden();
    }

    public function test_csv_and_print_include_all_rows_beyond_pagination_and_queries_stay_bounded(): void
    {
        for ($index = 0; $index < 35; $index++) {
            Harvest::create(['variety' => 'Variety '.$index, 'harvested_on' => today()->toDateString(), 'weight' => '1']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($this->url('harvests'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 35 && $rows->count() === 30);
        $this->assertLessThanOrEqual(18, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $csv = $this->get($this->url('harvests', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertSame(36, count(explode("\n", trim($csv))));
        $this->get($this->url('harvests', ['format' => 'print']))->assertOk()->assertSee('Variety 34')->assertSee('Variety 0');
        $this->get($this->url('harvests', ['from' => today()->addDay()->toDateString()]))->assertSessionHasErrors('to');
        $this->get($this->url('sales', ['group' => 'unsafe']))->assertSessionHasErrors('group');
    }

    public function test_production_and_damage_keep_unknown_costs_and_exclude_voided_records(): void
    {
        $batch = Batch::create(['code' => 'REAL-INTERNAL-ID', 'variety' => 'Oyster', 'bags' => 100, 'location' => '', 'started_on' => today()->toDateString(), 'contaminated_bags' => 4]);
        $batch->forceFill(['damaged_bags' => 3, 'discarded_bags' => 2])->save();
        $voided = Batch::create(['code' => 'VOIDED', 'variety' => 'Oyster', 'bags' => 500, 'location' => '', 'started_on' => today()->toDateString()]);
        $voided->forceFill(['production_status' => 'voided'])->save();
        $this->get($this->url('damage'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()->bags == 100 && $rows->first()->damaged == 3 && $rows->first()->contaminated == 4 && $rows->first()->discarded == 2);
        $this->get($this->url('costs'))->assertOk()->assertSee('Unknown')->assertDontSee('REAL-INTERNAL-ID');
        $this->assertNull($batch->fresh()->total_cost);
    }

    public function test_future_payments_do_not_reduce_historical_outstanding_and_unknown_costs_are_flagged(): void
    {
        $invoice = $this->sale();
        $this->travel(1)->days();
        $this->post('/invoices/'.$invoice->id.'/payments', ['request_id' => (string) Str::uuid(), 'type' => 'payment', 'method' => 'cash', 'amount' => 100, 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->get($this->url('outstanding', ['from' => today()->subDay()->toDateString(), 'to' => today()->subDay()->toDateString()]))->assertViewHas('rows', fn ($rows) => $rows->first()->balance == 395);
        Sale::create(['customer' => 'Legacy', 'sold_on' => today()->toDateString(), 'weight' => 1, 'price_per_kg' => 100]);
        $this->get($this->url('profits'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->where('period', today()->toDateString())->first()->unknown_costs == 1);
    }
}
