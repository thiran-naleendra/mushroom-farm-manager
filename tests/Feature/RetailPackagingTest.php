<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\FinishedStockMovement;
use App\Models\Harvest;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialMovement;
use App\Models\MaterialUnit;
use App\Models\PackagingRun;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\FreshStockService;
use App\Services\MaterialService;
use App\Services\MaterialStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class RetailPackagingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private Harvest $harvest;

    private Material $polythene;

    private Material $labels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'owner']);
        Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $category = MaterialCategory::create(['name' => 'Retail supplies', 'is_active' => true]);
        $base = ['material_category_id' => $category->id, 'material_unit_id' => MaterialUnit::where('symbol', 'pc')->value('id'), 'supply_type' => 'retail', 'minimum_stock' => 0, 'is_active' => true, 'quantity' => 100];
        $this->polythene = app(MaterialService::class)->save($this->owner, $base + ['name' => 'Retail food bags', 'sku' => 'FOOD', 'unit_cost' => 2]);
        $this->labels = app(MaterialService::class)->save($this->owner, $base + ['name' => 'Labels', 'sku' => 'LABEL', 'unit_cost' => 1]);
        $this->product = Product::create($this->productInput());
        $this->harvest = Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->subDays(2)->toDateString(), 'weight' => '10.000']);
        $this->actingAs($this->owner);
    }

    private function productInput(array $changes = []): array
    {
        return array_replace(['name' => 'Oyster retail', 'sku' => 'OYS200', 'variety' => 'Oyster', 'pack_size_grams' => 200, 'is_active' => true], $changes);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['product_id' => $this->product->id, 'harvest_id' => $this->harvest->id, 'packaged_on' => today()->toDateString(), 'packet_count' => 50,
            'wastage' => '0', 'polythene_id' => $this->polythene->id, 'label_id' => $this->labels->id, 'packaging_cost' => '25.00', 'fresh_cost_per_kg' => '100.00',
            'request_id' => (string) Str::uuid()], $changes);
    }

    public function test_ten_kg_makes_fifty_200g_packets_and_deducts_materials_once(): void
    {
        $data = $this->input();
        $this->post('/packaging', $data)->assertSessionHasNoErrors()->assertRedirect('/packaging/1');
        $this->post('/packaging', $data)->assertSessionHasNoErrors()->assertRedirect('/packaging/1');
        $run = PackagingRun::firstOrFail();
        $this->assertSame(10000, $run->fresh_grams);
        $this->assertSame(10000, $run->packed_grams);
        $this->assertSame(0, $run->wastage_grams);
        $this->assertSame('150.000000', $run->materials_cost);
        $this->assertSame('1175.000000', $run->total_cost);
        $this->assertSame('23.500000', $run->cost_per_packet);
        $this->assertSame('50.000000', $this->polythene->fresh()->quantity);
        $this->assertSame('50.000000', $this->labels->fresh()->quantity);
        $this->assertSame('100.000000', $this->polythene->fresh()->inventory_value);
        $this->assertSame(0, app(FreshStockService::class)->totals()['available']);
        $this->assertDatabaseCount('packaging_runs', 1);
        $this->assertDatabaseCount('finished_stock_movements', 1);
        $this->assertDatabaseCount('material_movements', 4);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('23.500000', FinishedStockMovement::first()->unit_cost);
        $this->get('/')->assertViewHas('metrics', fn ($metrics) => $metrics['packaged_stock'] === 50 && $metrics['retail_packets'] === 50 && $metrics['fresh_kg'] == 0)->assertSeeInOrder(['Available 200g packets', 'Active cultivation bags']);
        $this->get('/packaging/1')->assertOk()->assertSee('1175.000000');
        $this->get('/products')->assertOk()->assertSee('50');
        $this->post('/sales', ['customer' => 'Buyer', 'sold_on' => today()->toDateString(), 'weight' => '0.001', 'price_per_kg' => 100])->assertSessionHasErrors('weight');
    }

    public function test_wastage_and_unknown_fresh_cost_are_recorded_without_inventing_a_full_cost(): void
    {
        $this->post('/packaging', $this->input(['packet_count' => 45, 'wastage' => '1.000', 'fresh_cost_per_kg' => null, 'label_id' => null]))->assertSessionHasNoErrors();
        $run = PackagingRun::firstOrFail();
        $this->assertSame(9000, $run->packed_grams);
        $this->assertSame(1000, $run->wastage_grams);
        $this->assertSame(10000, $run->fresh_grams);
        $this->assertNull($run->total_cost);
        $this->assertNull($run->cost_per_packet);
        $this->assertSame('90.000000', $run->materials_cost);
        $this->assertSame('100.000000', $this->labels->fresh()->quantity);
        $this->get('/packaging/1')->assertOk()->assertSee('Incomplete');
    }

    public function test_insufficient_fresh_or_supplies_roll_back_every_ledger(): void
    {
        $this->post('/packaging', $this->input(['packet_count' => 51]))->assertSessionHasErrors('weight');
        $this->labels->forceFill(['is_active' => false])->save();
        $this->post('/packaging', $this->input())->assertSessionHasErrors('polythene_id');
        $this->labels->forceFill(['is_active' => true])->save();
        app(MaterialStockService::class)->adjust($this->owner, $this->labels, ['type' => 'remove', 'quantity' => 60, 'material_unit_id' => $this->labels->material_unit_id, 'request_id' => (string) Str::uuid(), 'reason' => 'Damaged labels']);
        $this->post('/packaging', $this->input())->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('packaging_runs', 0);
        $this->assertDatabaseCount('packaging_materials', 0);
        $this->assertDatabaseCount('finished_stock_movements', 0);
        $this->assertSame('100.000000', $this->polythene->fresh()->quantity);
        $this->assertSame(10000, app(FreshStockService::class)->totals()['available']);
    }

    public function test_retry_reference_cannot_be_reused_with_changed_quantities(): void
    {
        $data = $this->input(['packet_count' => 10]);
        $this->post('/packaging', $data)->assertSessionHasNoErrors();
        $this->post('/packaging', array_replace($data, ['packet_count' => 11]))->assertSessionHasErrors('request_id');
        $this->assertDatabaseCount('packaging_runs', 1);
        $this->assertSame(8000, app(FreshStockService::class)->totals()['available']);
    }

    public function test_varieties_dates_inactive_products_and_retail_materials_are_validated(): void
    {
        $this->product->update(['variety' => 'Shiitake']);
        $this->post('/packaging', $this->input())->assertSessionHasErrors('harvest_id');
        $this->product->update(['variety' => 'Oyster', 'is_active' => false]);
        $this->post('/packaging', $this->input())->assertSessionHasErrors('harvest_id');
        $this->product->update(['is_active' => true]);
        $this->post('/packaging', $this->input(['packaged_on' => today()->subDays(3)->toDateString()]))->assertSessionHasErrors('harvest_id');
        $this->post('/packaging', $this->input(['expires_on' => today()->subDay()->toDateString()]))->assertSessionHasErrors('expires_on');
        $this->post('/packaging', $this->input(['wastage' => '-0.001']))->assertSessionHasErrors('wastage');
        $this->post('/packaging', $this->input(['packet_count' => 1.5]))->assertSessionHasErrors('packet_count');
        $this->post('/packaging', $this->input(['label_id' => $this->polythene->id]))->assertSessionHasErrors('label_id');
        $this->polythene->update(['supply_type' => 'growing']);
        $this->post('/packaging', $this->input())->assertSessionHasErrors('polythene_id');
        $this->assertDatabaseCount('packaging_runs', 0);
    }

    public function test_fresh_sales_use_fifo_lots_and_packaging_cannot_take_an_already_sold_lot(): void
    {
        Harvest::create(['variety' => 'Shiitake', 'harvested_on' => today()->subDay()->toDateString(), 'weight' => '10.000']);
        Sale::create(['customer' => 'Buyer', 'sold_on' => today()->subDay()->toDateString(), 'weight' => '8.000', 'price_per_kg' => 100]);
        $lots = app(FreshStockService::class)->lots();
        $this->assertSame(2000, $lots->firstWhere('id', $this->harvest->id)->available_grams);
        $this->post('/packaging', $this->input(['packet_count' => 11]))->assertSessionHasErrors('weight');
        $this->post('/packaging', $this->input(['packet_count' => 10]))->assertSessionHasNoErrors();
        $this->assertSame(10000, app(FreshStockService::class)->totals()['available']);
    }

    public function test_backdated_packaging_cannot_take_stock_needed_by_a_later_sale(): void
    {
        Sale::create(['customer' => 'Buyer', 'sold_on' => today()->toDateString(), 'weight' => '9.000', 'price_per_kg' => 100]);
        $this->post('/packaging', $this->input(['packaged_on' => today()->subDay()->toDateString(), 'packet_count' => 10]))->assertSessionHasErrors('weight');
        $this->assertDatabaseCount('packaging_runs', 0);
        $this->assertSame('100.000000', $this->polythene->fresh()->quantity);
    }

    public function test_backdated_sales_cannot_take_the_source_lot_of_a_later_packaging_run(): void
    {
        Harvest::create(['variety' => 'Shiitake', 'harvested_on' => today()->subDay()->toDateString(), 'weight' => '10.000']);
        $this->post('/packaging', $this->input())->assertSessionHasNoErrors();
        $this->post('/sales', ['customer' => 'Buyer', 'sold_on' => today()->subDay()->toDateString(), 'weight' => '1.000', 'price_per_kg' => 100])->assertSessionHasErrors('weight');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(10000, app(FreshStockService::class)->totals()['available']);
    }

    public function test_custom_sizes_crud_search_and_historical_identity_are_preserved(): void
    {
        $this->get('/products/create')->assertOk()->assertSee('1000')->assertSee('250');
        $this->post('/products', $this->productInput(['sku' => 'CUSTOM', 'pack_size_grams' => 175]))->assertSessionHasNoErrors();
        $this->get('/products?search=CUSTOM')->assertOk()->assertSee('175g');
        $custom = Product::where('sku', 'CUSTOM')->firstOrFail();
        $this->get('/products/'.$custom->id.'/edit')->assertOk();
        $this->delete('/products/'.$custom->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted($custom);
        $this->post('/packaging', $this->input())->assertSessionHasNoErrors();
        $this->put('/products/'.$this->product->id, $this->productInput(['pack_size_grams' => 500]))->assertSessionHasErrors('pack_size_grams');
        $this->put('/products/'.$this->product->id, $this->productInput(['name' => 'New name', 'is_active' => false]))->assertSessionHasNoErrors();
        $this->delete('/products/'.$this->product->id)->assertSessionHasErrors('product');
        $this->polythene->update(['unit_cost' => 999, 'name' => 'Renamed bags']);
        $run = PackagingRun::firstOrFail();
        $this->assertSame('Oyster retail', $run->product_name);
        $this->assertSame('1175.000000', $run->total_cost);
        $this->assertSame('Retail food bags', $run->materials()->where('purpose', 'polythene')->first()->material_name);
    }

    public function test_count_unit_conversion_preserves_whole_packet_usage(): void
    {
        $roll = MaterialUnit::create(['name' => 'Hundred labels', 'symbol' => 'roll100', 'dimension' => 'count', 'factor' => 100, 'is_active' => true]);
        $new = $this->labels->replicate()->fill(['sku' => 'ROLL', 'material_unit_id' => $roll->id]);
        $new = app(MaterialService::class)->save($this->owner, $new->only(['name', 'sku', 'material_category_id', 'material_unit_id', 'supply_type', 'minimum_stock', 'is_active']) + ['quantity' => 1, 'unit_cost' => 100]);
        $this->post('/packaging', $this->input(['label_id' => $new->id]))->assertSessionHasNoErrors();
        $this->assertSame('0.500000', $new->fresh()->quantity);
        $this->assertSame('150.000000', PackagingRun::first()->materials_cost);
    }

    public function test_manager_can_package_but_workers_and_disabled_roles_cannot(): void
    {
        Role::where('name', 'manager')->update(['is_active' => true]);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/packaging')->assertOk();
        $this->get('/products')->assertOk();
        $this->post('/packaging', $this->input(['packet_count' => 1]))->assertSessionHasNoErrors();
        Role::where('name', 'worker')->update(['is_active' => true]);
        $worker = User::factory()->create(['role' => 'worker', 'can_work_harvest' => true]);
        $this->actingAs($worker)->get('/packaging')->assertForbidden();
        $this->get('/packaging/1')->assertForbidden();
        $this->get('/products')->assertForbidden();
        $this->post('/packaging', $this->input())->assertForbidden();
        Role::where('name', 'manager')->update(['is_active' => false]);
        $this->actingAs($manager)->post('/packaging', $this->input())->assertRedirect('/login');
        $this->assertDatabaseCount('packaging_runs', 1);
    }

    public function test_immutable_finished_ledger_and_run_preserve_history(): void
    {
        $this->post('/packaging', $this->input())->assertSessionHasNoErrors();
        foreach ([PackagingRun::first(), FinishedStockMovement::first(), MaterialMovement::where('type', 'packaging')->first(), PackagingRun::first()->materials()->first()] as $record) {
            try {
                $record->delete();
                $this->fail('History deletion was permitted.');
            } catch (LogicException) {
                $this->assertTrue($record->exists);
            }
        }
    }

    public function test_packaging_uses_inventory_value_instead_of_a_changed_reference_price(): void
    {
        $this->polythene->update(['unit_cost' => 999]);
        $this->post('/packaging', $this->input())->assertSessionHasNoErrors();
        $this->assertSame('150.000000', PackagingRun::first()->materials_cost);
        $this->assertSame('100.000000', $this->polythene->fresh()->inventory_value);
    }

    public function test_imprecise_count_conversion_is_validation_failure_and_rolls_back(): void
    {
        $unit = MaterialUnit::create(['name' => 'Three labels', 'symbol' => 'triple', 'dimension' => 'count', 'factor' => 3, 'is_active' => true]);
        $labels = app(MaterialService::class)->save($this->owner, [
            'name' => 'Label triples', 'sku' => 'THREE', 'material_category_id' => $this->labels->material_category_id, 'material_unit_id' => $unit->id,
            'supply_type' => 'retail', 'minimum_stock' => 0, 'is_active' => true, 'quantity' => 100, 'unit_cost' => 3,
        ]);
        $this->post('/packaging', $this->input(['packet_count' => 1, 'label_id' => $labels->id]))->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('packaging_runs', 0);
        $this->assertSame('100.000000', $this->polythene->fresh()->quantity);
        $this->assertSame(10000, app(FreshStockService::class)->totals()['available']);
    }

    public function test_stock_screen_has_empty_states_and_independent_date_filters(): void
    {
        $this->get('/packaging?to='.today()->toDateString())->assertOk()->assertSee('No packaging recorded yet.')->assertDontSee('@js(');
        $this->get('/packaging?from='.today()->toDateString().'&to='.today()->subDay()->toDateString())->assertSessionHasErrors('to');
        $this->get('/packaging?search=absent')->assertOk()->assertSee('No packaging recorded yet.');
    }

    public function test_retail_defaults_prioritize_200g_without_changing_custom_products(): void
    {
        $custom = Product::create($this->productInput(['name' => 'A custom pack', 'sku' => 'CUSTOM', 'pack_size_grams' => 350]));
        Product::create(['name' => 'A loose mushroom', 'sku' => 'LOOSE', 'type' => 'fresh', 'unit' => 'kg', 'variety' => 'Oyster', 'is_active' => true]);
        $this->get('/products/create')->assertOk()->assertViewHas('product', fn ($p) => $p->type === 'packaged' && $p->pack_size_grams === 200)->assertSee('Selling unit: Packet (pcs)');
        $this->get('/sales/create')->assertOk()->assertViewHas('products', fn ($p) => $p->first()->id === $this->product->id)->assertSee('Packet count (pcs)');
        $this->get('/packaging')->assertOk()->assertViewHas('products', fn ($p) => $p->first()->id === $this->product->id);
        $this->post('/packaging', $this->input(['product_id' => $custom->id, 'packet_count' => 10]))->assertSessionHasNoErrors();
        $this->assertSame(3500, PackagingRun::firstOrFail()->fresh_grams);
        $this->assertSame(0, app(FreshStockService::class)->totals()['retail_packets']);
        $this->assertSame(10, app(FreshStockService::class)->totals()['finished']);
        $this->assertSame(350, $custom->fresh()->pack_size_grams);
    }

    public function test_dashboard_available_packets_excludes_expired_and_inactive_stock(): void
    {
        $this->post('/packaging', $this->input(['expires_on' => today()->toDateString()]))->assertSessionHasNoErrors();
        $this->assertSame(50, app(FreshStockService::class)->totals()['retail_packets']);
        $this->product->update(['is_active' => false]);
        $this->assertSame(0, app(FreshStockService::class)->totals()['retail_packets']);
        $this->product->update(['is_active' => true]);
        $this->travel(1)->days();
        $this->assertSame(0, app(FreshStockService::class)->totals()['retail_packets']);
        $this->assertSame(50, app(FreshStockService::class)->totals()['finished']);
        $this->assertDatabaseCount('finished_stock_movements', 1);
    }
}
