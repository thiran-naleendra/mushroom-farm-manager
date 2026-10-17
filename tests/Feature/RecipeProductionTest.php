<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\User;
use App\Services\MaterialService;
use App\Services\MaterialStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecipeProductionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Material $sawdust;

    private Material $bran;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['role' => 'owner'])->save();
        Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $category = MaterialCategory::create(['name' => 'Growing inputs', 'is_active' => true]);
        $base = ['material_category_id' => $category->id, 'material_unit_id' => MaterialUnit::where('symbol', 'kg')->value('id'), 'supply_type' => 'growing', 'minimum_stock' => 0, 'is_active' => true];
        $this->sawdust = app(MaterialService::class)->save($this->owner, $base + ['name' => 'Sawdust', 'sku' => 'SAW', 'quantity' => 20, 'unit_cost' => 10]);
        $this->bran = app(MaterialService::class)->save($this->owner, $base + ['name' => 'Rice bran', 'sku' => 'BRAN', 'quantity' => 10, 'unit_cost' => 20]);
        $this->actingAs($this->owner);
        $this->post('/recipes', $this->recipeInput())->assertSessionHasNoErrors();
        $this->recipe = Recipe::firstOrFail();
    }

    private function recipeInput(array $overrides = []): array
    {
        return array_replace(['name' => 'Farmer recipe', 'variety' => 'Oyster', 'reference_bags' => 100, 'notes' => 'Custom growing mix', 'ingredients' => [
            ['material_id' => $this->sawdust->id, 'material_unit_id' => $this->sawdust->material_unit_id, 'quantity' => 10],
            ['material_id' => $this->bran->id, 'material_unit_id' => $this->bran->material_unit_id, 'quantity' => 2],
        ]], $overrides);
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['recipe_id' => $this->recipe->id, 'started_on' => today()->toDateString(), 'bags' => 50, 'labour_cost' => '30.00', 'additional_cost' => '10.00', 'request_id' => (string) Str::uuid()], $overrides);
    }

    private function preview(array $data = []): string
    {
        $this->post('/production/preview', $this->input($data))->assertRedirect('/production/confirm')->assertSessionHasNoErrors();

        return session('production_quote');
    }

    private function production(array $data = []): Batch
    {
        $quote = $this->preview($data);
        $this->post('/production', ['quote' => $quote])->assertSessionHasNoErrors();

        return Batch::latest('id')->firstOrFail();
    }

    public function test_recipe_crud_and_stock_unchanged_until_production_confirmation(): void
    {
        $this->get('/recipes')->assertOk()->assertSee('Farmer recipe');
        $this->get('/recipes/create')->assertOk();
        $this->get('/recipes/'.$this->recipe->id.'/edit')->assertOk()->assertSee('Reference bag count');
        $this->put('/recipes/'.$this->recipe->id, $this->recipeInput(['name' => 'Updated custom recipe']))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->recipe->fresh()->revision);
        $this->assertSame('20.000000', $this->sawdust->fresh()->quantity);
        $this->assertDatabaseCount('material_movements', 2);
        $this->get('/production')->assertOk()->assertSee('No production yet');
        $this->get('/production/create')->assertOk()->assertDontSee('Batch reference');
    }

    public function test_recipe_scaling_confirmed_costs_and_idempotent_stock_deductions(): void
    {
        $quote = $this->preview();
        $this->assertDatabaseCount('batches', 0);
        $this->assertSame('20.000000', $this->sawdust->fresh()->quantity);
        $this->get('/production/confirm')->assertOk()->assertSee('5.000000')->assertSee('110.000000')->assertSee('Confirm &amp; deduct materials', false);
        $this->post('/production', ['quote' => $quote])->assertSessionHasNoErrors();
        $this->post('/production', ['quote' => $quote])->assertSessionHasNoErrors();
        $batch = Batch::firstOrFail();
        $this->assertSame('15.000000', $this->sawdust->fresh()->quantity);
        $this->assertSame('9.000000', $this->bran->fresh()->quantity);
        $this->assertSame('150.000000', $this->sawdust->fresh()->inventory_value);
        $this->assertSame('70.000000', $batch->material_cost);
        $this->assertSame('110.000000', $batch->total_cost);
        $this->assertSame('2.200000', $batch->cost_per_bag);
        $this->assertDatabaseCount('batches', 1);
        $this->assertDatabaseCount('production_ingredients', 2);
        $this->assertDatabaseCount('material_movements', 4);
        $this->assertDatabaseCount('batch_audits', 1);
        $this->assertDatabaseCount('expenses', 0);
        $this->get('/production/'.$batch->id)->assertOk()->assertSee('Historical ingredients')->assertDontSee($batch->code);
    }

    public function test_fractional_scaling_rounds_up_canonical_stock_precision(): void
    {
        $this->put('/recipes/'.$this->recipe->id, $this->recipeInput(['reference_bags' => 3, 'ingredients' => [
            ['material_id' => $this->sawdust->id, 'material_unit_id' => MaterialUnit::where('symbol', 'g')->value('id'), 'quantity' => 100],
        ]]))->assertSessionHasNoErrors();
        $batch = $this->production(['bags' => 1, 'labour_cost' => 0, 'additional_cost' => 0]);
        $this->assertSame('0.033334', $batch->ingredients()->first()->stock_quantity);
        $this->assertSame('19.966666', $this->sawdust->fresh()->quantity);
    }

    public function test_stale_stock_or_recipe_preview_requires_refresh_without_deduction(): void
    {
        $quote = $this->preview();
        app(MaterialStockService::class)->adjust($this->owner, $this->sawdust, ['type' => 'remove', 'quantity' => 1, 'material_unit_id' => $this->sawdust->material_unit_id, 'reason' => 'Other stock usage', 'request_id' => (string) Str::uuid()]);
        $this->post('/production', ['quote' => $quote])->assertSessionHasErrors('quote');
        $this->assertDatabaseCount('batches', 0);
        $this->assertSame('19.000000', $this->sawdust->fresh()->quantity);
        $quote = $this->preview();
        $this->put('/recipes/'.$this->recipe->id, $this->recipeInput(['name' => 'Revised recipe']))->assertSessionHasNoErrors();
        $this->post('/production', ['quote' => $quote])->assertSessionHasErrors('quote');
        $this->assertDatabaseCount('batches', 0);
    }

    public function test_insufficient_stock_and_invalid_inputs_do_not_create_production(): void
    {
        $this->post('/production/preview', $this->input(['bags' => 1000000]))->assertSessionHasErrors('recipe_id');
        $this->post('/production/preview', $this->input(['bags' => 0, 'labour_cost' => -1]))->assertSessionHasErrors(['bags', 'labour_cost']);
        $this->post('/production', [])->assertSessionHasErrors('quote');
        $this->post('/production', ['quote' => 'tampered'])->assertSessionHasErrors('quote');
        $this->assertDatabaseCount('batches', 0);
        $this->assertDatabaseCount('material_movements', 2);
        $this->assertSame('20.000000', $this->sawdust->fresh()->quantity);
    }

    public function test_bag_corrections_prevent_negative_counts_and_keep_actor_and_reason(): void
    {
        $batch = $this->production();
        $url = '/production/'.$batch->id.'/correct';
        $this->patch($url, ['sold_bags' => 10, 'damaged_bags' => 5, 'location' => null, 'reason' => 'Physical count correction'])->assertSessionHasNoErrors();
        $this->assertSame(35, $batch->fresh()->availableBags());
        $audit = $batch->audits()->where('action', 'corrected')->firstOrFail();
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame(10, $audit->after['sold_bags']);
        $this->patch($url, ['sold_bags' => 49, 'damaged_bags' => 2, 'reason' => 'Invalid count'])->assertSessionHasErrors('reason');
        $this->patch($url, ['sold_bags' => 0, 'damaged_bags' => 0])->assertSessionHasErrors('reason');
        $this->assertSame(35, $batch->fresh()->availableBags());
        $this->patch('/production/'.$batch->id.'/stage', ['stage' => 'fruiting', 'contaminated_bags' => 36, 'reason' => 'Contamination count'])->assertSessionHasErrors('reason');
        $this->get('/')->assertViewHas('metrics', fn ($metrics) => $metrics['active_bags'] == 35);
        $this->assertSame('110.000000', $batch->fresh()->total_cost);
    }

    public function test_unused_production_void_restores_original_quantities_and_costs_once(): void
    {
        $batch = $this->production();
        $this->post('/production/'.$batch->id.'/void', ['reason' => 'Wrong recipe selected'])->assertSessionHasNoErrors();
        $this->post('/production/'.$batch->id.'/void', ['reason' => 'Duplicate click'])->assertSessionHasNoErrors();
        $this->assertSame('20.000000', $this->sawdust->fresh()->quantity);
        $this->assertSame('200.000000', $this->sawdust->fresh()->inventory_value);
        $this->assertSame('10.000000', $this->bran->fresh()->quantity);
        $this->assertSame('voided', $batch->fresh()->production_status);
        $this->assertSame(0, $batch->fresh()->availableBags());
        $this->assertDatabaseCount('material_movements', 6);
        $this->assertDatabaseCount('batch_audits', 2);
        $this->assertDatabaseCount('production_ingredients', 2);
        $this->assertSame('110.000000', $batch->fresh()->total_cost);
        $this->get('/')->assertViewHas('metrics', fn ($metrics) => $metrics['produced_bags'] == 0);
    }

    public function test_production_with_harvests_or_sold_bags_cannot_be_voided(): void
    {
        $batch = $this->production();
        Harvest::create(['batch_id' => $batch->id, 'harvested_on' => today()->toDateString(), 'weight' => 1]);
        $this->post('/production/'.$batch->id.'/void', ['reason' => 'Already used'])->assertSessionHasErrors('reason');
        $this->assertSame('confirmed', $batch->fresh()->production_status);
        $this->assertSame('15.000000', $this->sawdust->fresh()->quantity);
    }

    public function test_simple_and_advanced_visibility_and_historical_recipe_costs(): void
    {
        $farm = Farm::firstOrFail();
        $farm->update(['mode' => 'advanced']);
        $this->get('/production/create')->assertSee('Batch reference')->assertSee('Location');
        $batch = $this->production(['reference' => 'MY-REFERENCE', 'location' => 'Room B']);
        $this->get('/production/'.$batch->id)->assertSee('MY-REFERENCE')->assertSee('Room B');
        $this->put('/recipes/'.$this->recipe->id, $this->recipeInput(['name' => 'New recipe version', 'reference_bags' => 10]))->assertSessionHasNoErrors();
        $this->sawdust->update(['unit_cost' => 99]);
        $this->assertSame('50.000000', $batch->ingredients()->where('material_id', $this->sawdust->id)->first()->total_cost);
        $this->assertSame('Farmer recipe', $batch->recipe_name);
        $farm->update(['mode' => 'simple']);
        $this->get('/production/'.$batch->id)->assertDontSee('MY-REFERENCE')->assertDontSee($batch->code);
        $this->delete('/recipes/'.$this->recipe->id)->assertSessionHasNoErrors();
        $this->get('/production/'.$batch->id)->assertOk()->assertSee('Farmer recipe');
    }

    public function test_authorized_worker_can_produce_but_cannot_manage_recipes_or_correct_costs(): void
    {
        Role::where('name', 'worker')->update(['is_active' => true]);
        $worker = User::factory()->create();
        $worker->forceFill(['can_work_production' => true])->save();
        $this->actingAs($worker)->get('/recipes')->assertOk();
        $this->get('/recipes/create')->assertForbidden();
        $this->post('/recipes', $this->recipeInput())->assertForbidden();
        $batch = $this->production();
        $this->get('/production/'.$batch->id)->assertOk()->assertDontSee('Total production cost')->assertDontSee('Cost per growing bag');
        $this->patch('/production/'.$batch->id.'/correct', ['sold_bags' => 1, 'damaged_bags' => 0, 'reason' => 'Not allowed'])->assertForbidden();
        $this->post('/production/'.$batch->id.'/void', ['reason' => 'Not allowed'])->assertForbidden();
        $this->get('/inventory')->assertForbidden();
        $worker->forceFill(['can_work_production' => false])->save();
        $this->get('/production')->assertForbidden();
    }

    public function test_confirmation_quote_is_actor_bound_and_expires(): void
    {
        $quote = $this->preview();
        $other = User::factory()->create();
        $other->forceFill(['role' => 'owner'])->save();
        $this->actingAs($other)->post('/production', ['quote' => $quote])->assertForbidden();
        $this->actingAs($this->owner);
        $this->travel(21)->minutes();
        $this->post('/production', ['quote' => $quote])->assertSessionHasErrors('quote');
        $this->assertDatabaseCount('batches', 0);
        $this->travelBack();
    }

    public function test_recipe_units_and_material_scope_are_validated(): void
    {
        $input = $this->recipeInput();
        $input['ingredients'][0]['material_unit_id'] = MaterialUnit::where('symbol', 'L')->value('id');
        $this->post('/recipes', $input)->assertSessionHasErrors('material_unit_id');
        $this->sawdust->update(['supply_type' => 'retail']);
        $this->post('/recipes', $this->recipeInput())->assertSessionHasErrors('ingredients');
        $this->assertDatabaseCount('recipes', 1);
    }

    public function test_confirmed_costs_and_quantities_are_immutable(): void
    {
        $batch = $this->production();
        $this->expectException(\LogicException::class);
        $batch->bags = 999;
        $batch->save();
    }

    public function test_void_restores_historical_costs_even_after_reference_prices_change(): void
    {
        $batch = $this->production();
        $this->sawdust->update(['unit_cost' => 99]);
        app(MaterialStockService::class)->adjust($this->owner, $this->sawdust, ['type' => 'add', 'quantity' => 1, 'material_unit_id' => $this->sawdust->material_unit_id, 'reason' => 'Additional material count', 'request_id' => (string) Str::uuid()]);
        $this->post('/production/'.$batch->id.'/void', ['reason' => 'Correct the unused production'])->assertSessionHasNoErrors();
        $this->assertSame('21.000000', $this->sawdust->fresh()->quantity);
        $this->assertSame('299.000000', $this->sawdust->fresh()->inventory_value);
        $this->assertSame('14.238095', $this->sawdust->fresh()->average_cost);
        $this->assertSame('50.000000', $batch->ingredients()->where('material_id', $this->sawdust->id)->first()->total_cost);
    }

    public function test_void_can_restore_archived_zero_stock_material_without_activating_it(): void
    {
        $batch = $this->production(['bags' => 200]);
        $this->assertSame('0.000000', $this->sawdust->fresh()->quantity);
        $this->delete('/inventory/'.$this->sawdust->id)->assertSessionHasNoErrors();
        MaterialUnit::whereKey($this->sawdust->material_unit_id)->update(['is_active' => false]);
        $this->post('/production/'.$batch->id.'/void', ['reason' => 'Wrong bag count'])->assertSessionHasNoErrors();
        $restored = Material::findOrFail($this->sawdust->id);
        $this->assertSame('20.000000', $restored->quantity);
        $this->assertSame('200.000000', $restored->inventory_value);
        $this->assertFalse($restored->is_active);
    }
}
