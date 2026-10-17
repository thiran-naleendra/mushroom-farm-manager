<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\MaterialQuantity;
use App\Services\MaterialStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaterialInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private MaterialCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['role' => 'owner'])->save();
        Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->category = MaterialCategory::create(['name' => 'Growing inputs', 'is_active' => true]);
        $this->actingAs($this->owner);
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Sawdust', 'sku' => 'SAW-001', 'material_category_id' => $this->category->id,
            'material_unit_id' => MaterialUnit::where('symbol', 'kg')->value('id'), 'supply_type' => 'growing',
            'quantity' => '1.000000', 'unit_cost' => '50.00', 'minimum_stock' => '0.500000', 'is_active' => true, 'notes' => 'Dry storage',
        ], $overrides);
    }

    private function material(array $data = []): Material
    {
        $this->post('/inventory', $this->data($data))->assertSessionHasNoErrors();

        return Material::latest('id')->firstOrFail();
    }

    private function adjustment(array $overrides = []): array
    {
        return array_replace(['type' => 'add', 'quantity' => '250', 'material_unit_id' => MaterialUnit::where('symbol', 'g')->value('id'), 'reason' => 'Physical count correction', 'request_id' => (string) Str::uuid()], $overrides);
    }

    public function test_inventory_screens_and_empty_state_use_no_business_seed_data(): void
    {
        $this->assertDatabaseCount('materials', 0);
        $this->get('/inventory')->assertOk()->assertSee('No materials match these filters.');
        $this->get('/inventory/create')->assertOk()->assertSee('Opening quantity');
        $this->get('/inventory/options')->assertOk()->assertSee('Kilogram')->assertSee('Categories');
    }

    public function test_material_creation_and_update_preserve_opening_ledger_and_cost_snapshot(): void
    {
        $material = $this->material();
        $this->get('/inventory/'.$material->id)->assertOk()->assertSee('Opening stock')->assertSee('Stock movement history');
        $this->get('/inventory/'.$material->id.'/edit')->assertOk();
        $this->assertSame('1.000000', $material->quantity);
        $this->assertDatabaseHas('material_movements', ['material_id' => $material->id, 'type' => 'opening', 'unit_cost' => '50.00', 'quantity_micros' => 1000000, 'user_id' => $this->owner->id]);
        $edit = $this->data(['name' => 'Updated Sawdust', 'unit_cost' => '65.00']);
        unset($edit['quantity']);
        $this->put('/inventory/'.$material->id, $edit)->assertSessionHasNoErrors();
        $this->assertSame('Updated Sawdust', $material->fresh()->name);
        $this->assertSame('1.000000', $material->fresh()->quantity);
        $this->assertDatabaseCount('material_movements', 1);
        $this->assertSame('50.00', $material->movements()->first()->unit_cost);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_weight_and_volume_conversions_are_exact_and_stock_is_not_double_counted(): void
    {
        $material = $this->material();
        $first = $this->adjustment();
        $url = '/inventory/'.$material->id.'/adjust';
        $this->post($url, $first)->assertSessionHasNoErrors();
        $this->post($url, $first)->assertSessionHasNoErrors();
        $this->assertSame('1.250000', $material->fresh()->quantity);
        $this->assertDatabaseCount('material_movements', 2);
        $this->post($url, $this->adjustment(['type' => 'remove', 'quantity' => '500']))->assertSessionHasNoErrors();
        $this->assertSame('0.750000', $material->fresh()->quantity);
        $volume = $this->material(['name' => 'Liquid input', 'sku' => 'LIQ-1', 'material_unit_id' => MaterialUnit::where('symbol', 'L')->value('id')]);
        $this->post('/inventory/'.$volume->id.'/adjust', $this->adjustment(['material_unit_id' => MaterialUnit::where('symbol', 'ml')->value('id')]))->assertSessionHasNoErrors();
        $this->assertSame('1.250000', $volume->fresh()->quantity);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_exact_six_decimal_arithmetic_and_reverse_conversion(): void
    {
        $material = $this->material(['quantity' => '0']);
        $kg = MaterialUnit::where('symbol', 'kg')->firstOrFail();
        $g = MaterialUnit::where('symbol', 'g')->firstOrFail();
        $this->assertSame(1250000, MaterialQuantity::convert('1250', $g, $kg));
        $this->assertSame(1250000000, MaterialQuantity::convert('1.25', $kg, $g));
        foreach (range(1, 3) as $i) {
            $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment(['quantity' => '0.000001', 'material_unit_id' => $kg->id]))->assertSessionHasNoErrors();
        }
        $this->assertSame('0.000003', $material->fresh()->quantity);
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment(['type' => 'remove', 'quantity' => '0.000003', 'material_unit_id' => $kg->id]))->assertSessionHasNoErrors();
        $this->assertSame('0.000000', $material->fresh()->quantity);
    }

    public function test_negative_stock_incompatible_units_and_precision_loss_are_rejected(): void
    {
        $material = $this->material();
        $url = '/inventory/'.$material->id.'/adjust';
        $this->post($url, $this->adjustment(['type' => 'remove', 'quantity' => '1001']))->assertSessionHasErrors('quantity');
        $this->post($url, $this->adjustment(['material_unit_id' => MaterialUnit::where('symbol', 'L')->value('id')]))->assertSessionHasErrors('material_unit_id');
        $this->post($url, $this->adjustment(['quantity' => '0.000001']))->assertSessionHasErrors('quantity');
        $this->assertSame('1.000000', $material->fresh()->quantity);
        $this->assertDatabaseCount('material_movements', 1);
    }

    public function test_adjustment_validation_and_idempotency_payload_mismatch(): void
    {
        $material = $this->material();
        $url = '/inventory/'.$material->id.'/adjust';
        $this->post($url, $this->adjustment(['quantity' => -1, 'reason' => '', 'request_id' => 'invalid', 'type' => 'opening']))->assertSessionHasErrors(['quantity', 'reason', 'request_id', 'type']);
        $first = $this->adjustment();
        $this->post($url, $first)->assertSessionHasNoErrors();
        $this->post($url, array_replace($first, ['quantity' => 500]))->assertSessionHasErrors('quantity');
        $this->assertSame('1.250000', $material->fresh()->quantity);
        $this->assertDatabaseCount('material_movements', 2);
    }

    public function test_material_validation_and_unit_change_cannot_rewrite_history(): void
    {
        $material = $this->material();
        $this->post('/inventory', $this->data())->assertSessionHasErrors('sku');
        $this->post('/inventory', $this->data(['sku' => 'NEW', 'unit_cost' => -1, 'quantity' => -1, 'minimum_stock' => -1, 'supply_type' => 'invalid']))->assertSessionHasErrors(['unit_cost', 'quantity', 'minimum_stock', 'supply_type']);
        $edit = $this->data(['material_unit_id' => MaterialUnit::where('symbol', 'g')->value('id')]);
        unset($edit['quantity']);
        $this->put('/inventory/'.$material->id, $edit)->assertSessionHasErrors('material_unit_id');
        $this->put('/inventory/'.$material->id, $this->data(['quantity' => 100]))->assertSessionHasErrors('quantity');
        $this->assertSame('1.000000', $material->fresh()->quantity);
    }

    public function test_archive_requires_zero_stock_and_keeps_history_accessible(): void
    {
        $material = $this->material();
        $this->delete('/inventory/'.$material->id)->assertSessionHasErrors('quantity');
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment(['quantity' => 1000, 'type' => 'remove']))->assertSessionHasNoErrors();
        $this->delete('/inventory/'.$material->id)->assertRedirect('/inventory');
        $this->assertSoftDeleted('materials', ['id' => $material->id]);
        $this->assertDatabaseCount('material_movements', 2);
        $this->get('/inventory/'.$material->id)->assertOk()->assertSee('Opening stock')->assertSee('Archived')->assertDontSee('Record adjustment');
        $this->get('/inventory?status=archived')->assertOk()->assertSee('Sawdust');
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment())->assertNotFound();
    }

    public function test_inactive_materials_and_inactive_units_cannot_receive_adjustments(): void
    {
        $material = $this->material(['quantity' => 0, 'is_active' => false]);
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment())->assertSessionHasErrors('quantity');
        $material->update(['is_active' => true]);
        MaterialUnit::where('symbol', 'g')->update(['is_active' => false]);
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment())->assertSessionHasErrors('material_unit_id');
        $this->assertDatabaseCount('material_movements', 0);
    }

    public function test_categories_units_and_historical_unit_factors_are_configurable_safely(): void
    {
        $this->post('/inventory/options/categories', ['name' => 'Retail supplies', 'is_active' => true])->assertSessionHasNoErrors();
        $this->post('/inventory/options/units', ['name' => 'Bundle', 'symbol' => 'bundle', 'dimension' => 'custom', 'factor' => 1, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('material_units', ['symbol' => 'bundle']);
        $material = $this->material();
        $unit = $material->unit;
        $this->put('/inventory/options/units/'.$unit->id, ['name' => 'Kilogram', 'symbol' => 'kg', 'dimension' => 'mass', 'factor' => 2, 'is_active' => true])->assertSessionHasErrors('factor');
        $this->assertSame('1.000000', $unit->fresh()->factor);
        $this->post('/inventory/options/units', ['name' => 'Wrong', 'symbol' => 'wrong', 'dimension' => 'custom', 'factor' => 2, 'is_active' => true])->assertSessionHasErrors('factor');
        $this->put('/inventory/options/categories/'.$this->category->id, ['name' => 'Growing inputs', 'is_active' => false])->assertSessionHasNoErrors();
        $this->post('/inventory', $this->data(['sku' => 'NEW']))->assertSessionHasErrors('material_unit_id');
    }

    public function test_search_supply_filters_pagination_and_low_stock_alerts(): void
    {
        $low = $this->material(['name' => 'Retail Labels', 'sku' => 'LAB-1', 'supply_type' => 'retail', 'quantity' => 0, 'minimum_stock' => 2]);
        $this->material(['quantity' => 10]);
        $this->get('/inventory?search=LAB')->assertOk()->assertSee('Retail Labels')->assertDontSee('SAW-001');
        $this->get('/inventory?supply_type=growing')->assertOk()->assertSee('Sawdust')->assertDontSee('LAB-1');
        $this->get('/inventory?low_stock=1')->assertOk()->assertSee('Retail Labels')->assertDontSee('SAW-001');
        $this->get('/')->assertOk()->assertViewHas('materialAlerts', fn ($rows) => $rows->first()->id === $low->id);
        foreach (range(1, 16) as $i) {
            $this->material(['name' => 'Supply '.$i, 'sku' => 'EXTRA-'.$i, 'quantity' => 0]);
        }
        $this->get('/inventory?supply_type=growing&page=2')->assertOk()->assertViewHas('materials', fn ($page) => $page->total() === 17 && $page->count() === 2)->assertSee('supply_type=growing');
    }

    public function test_backend_permissions_for_manager_worker_and_inactive_role(): void
    {
        $material = $this->material();
        Role::whereIn('name', ['manager', 'worker'])->update(['is_active' => true]);
        $manager = User::factory()->create();
        $manager->forceFill(['role' => 'manager'])->save();
        $this->actingAs($manager)->get('/inventory')->assertOk();
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment())->assertSessionHasNoErrors();
        $this->delete('/inventory/'.$material->id)->assertForbidden();
        $worker = User::factory()->create();
        $this->actingAs($worker)->get('/inventory')->assertForbidden();
        $this->get('/inventory/options')->assertForbidden();
        $this->get('/inventory/'.$material->id)->assertForbidden();
        $this->post('/inventory', $this->data())->assertForbidden();
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment())->assertForbidden();
        Role::where('name', 'manager')->update(['is_active' => false]);
        $this->actingAs($manager)->get('/inventory')->assertRedirect('/login');
    }

    public function test_stock_service_reconciles_cached_quantity_from_authoritative_ledger(): void
    {
        $material = $this->material();
        $material->quantity = '999.000000';
        $material->save();
        app(MaterialStockService::class)->adjust($this->owner, $material, $this->adjustment());
        $this->assertSame('1.250000', $material->fresh()->quantity);
        $this->assertSame(1250000, (int) $material->movements()->sum('quantity_micros'));
    }

    public function test_maximum_stock_boundary_is_exact_and_overflow_rolls_back(): void
    {
        $material = $this->material(['quantity' => '999999999.999999']);
        $this->assertSame('999999999.999999', $material->quantity);
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment(['material_unit_id' => $material->material_unit_id, 'quantity' => '0.000001']))->assertSessionHasErrors('quantity');
        $this->assertSame('999999999.999999', $material->fresh()->quantity);
        $this->assertDatabaseCount('material_movements', 1);
    }

    public function test_movements_cannot_be_edited_or_deleted(): void
    {
        $material = $this->material();
        $this->expectException(\LogicException::class);
        $movement = $material->movements()->firstOrFail();
        $movement->reason = 'Rewrite history';
        $movement->save();
    }

    public function test_stale_material_instances_cannot_overdraw_stock(): void
    {
        $material = $this->material();
        $stale = Material::findOrFail($material->id);
        $service = app(MaterialStockService::class);
        $service->adjust($this->owner, $material, $this->adjustment(['type' => 'remove', 'quantity' => 750]));
        try {
            $service->adjust($this->owner, $stale, $this->adjustment(['type' => 'remove', 'quantity' => 750]));
            $this->fail('An overdraft must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('quantity', $error->errors());
        }
        $this->assertSame('0.250000', $material->fresh()->quantity);
        $this->assertDatabaseCount('material_movements', 2);
    }

    public function test_failed_opening_stock_rolls_back_material_creation(): void
    {
        $this->post('/inventory', $this->data(['is_active' => false]))->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('materials', 0);
        $this->assertDatabaseCount('material_movements', 0);
    }

    public function test_custom_units_are_not_interchangeable_and_movement_units_cannot_be_redefined(): void
    {
        $material = $this->material();
        $this->post('/inventory/'.$material->id.'/adjust', $this->adjustment())->assertSessionHasNoErrors();
        $grams = MaterialUnit::where('symbol', 'g')->firstOrFail();
        $this->put('/inventory/options/units/'.$grams->id, ['name' => 'Gram', 'symbol' => 'g', 'dimension' => 'mass', 'factor' => 1, 'is_active' => true])->assertSessionHasErrors('factor');
        $first = MaterialUnit::create(['name' => 'Roll', 'symbol' => 'roll', 'dimension' => 'custom', 'factor' => 1, 'is_active' => true]);
        $second = MaterialUnit::create(['name' => 'Box', 'symbol' => 'box', 'dimension' => 'custom', 'factor' => 1, 'is_active' => true]);
        $this->assertSame(2000000, MaterialQuantity::convert('2', $first, $first));
        $this->expectException(ValidationException::class);
        MaterialQuantity::convert('2', $first, $second);
    }
}
