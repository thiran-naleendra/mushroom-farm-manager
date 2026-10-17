<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\User;
use App\Services\MaterialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FarmModesTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $owner;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->farm = Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->actingAs($this->owner);
        $category = MaterialCategory::create(['name' => 'Growing', 'is_active' => true]);
        $material = app(MaterialService::class)->save($this->owner, ['name' => 'Sawdust', 'sku' => 'SAW', 'material_category_id' => $category->id, 'material_unit_id' => MaterialUnit::where('symbol', 'kg')->value('id'), 'supply_type' => 'growing', 'quantity' => 100, 'minimum_stock' => 0, 'unit_cost' => 10, 'is_active' => true]);
        $this->post('/recipes', ['name' => 'Farmer recipe', 'variety' => 'Oyster', 'reference_bags' => 10, 'ingredients' => [['material_id' => $material->id, 'material_unit_id' => $material->material_unit_id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->recipe = Recipe::firstOrFail();
    }

    private function settings(string $mode, array $extra = []): void
    {
        $this->put('/settings', ['name' => 'Farm', 'mode' => $mode, 'currency' => 'LKR'] + $extra)->assertSessionHasNoErrors();
    }

    private function preview(array $extra = []): string
    {
        $this->post('/production/preview', $extra + ['recipe_id' => $this->recipe->id, 'bags' => 10, 'started_on' => today()->toDateString(), 'labour_cost' => '0', 'additional_cost' => '0', 'request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return session('production_quote');
    }

    private function production(array $extra = []): Batch
    {
        $this->post('/production', ['quote' => $this->preview($extra)])->assertSessionHasNoErrors();

        return Batch::latest('id')->firstOrFail();
    }

    public function test_owner_only_simple_farm_uses_shared_production_and_quick_harvest(): void
    {
        $this->assertSame(1, User::count());
        $this->assertSame(0, Role::whereIn('name', ['manager', 'worker'])->where('is_active', true)->count());
        $this->get('/production/create')->assertOk()->assertSee('Quick production')->assertDontSee('name="reference"', false)->assertDontSee('name="location"', false);
        $batch = $this->production(['reference' => 'HIDDEN-INJECTION', 'location' => 'Hidden location']);
        $this->assertNotEmpty($batch->code);
        $this->assertNull($batch->reference);
        $this->assertSame('', $batch->location);
        $this->get('/harvests')->assertOk()->assertDontSee('name="cycle"', false);
        $input = ['variety' => 'Oyster', 'harvested_on' => today()->toDateString(), 'weight' => '2', 'request_id' => (string) Str::uuid()];
        $this->post('/harvests', $input)->assertSessionHasNoErrors();
        $this->post('/harvests', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('harvests', 1);
        $this->assertNull(Harvest::firstOrFail()->batch_id);
        $this->assertSame('2.000', Harvest::firstOrFail()->weight);
        foreach (['/inventory', '/packaging', '/sales/create', '/reports'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/production/'.$batch->id.'/label')->assertForbidden();
        $this->get('/reports/production')->assertForbidden();
    }

    public function test_mode_round_trip_preserves_production_stock_costs_and_unknown_history(): void
    {
        $this->settings('advanced', ['qr_printing' => true, 'batch_tracking' => true]);
        $batch = $this->production(['reference' => 'ROOM-A-01', 'location' => 'Room A']);
        $legacy = Batch::create(['code' => 'LEGACY-INTERNAL', 'variety' => 'Oyster', 'bags' => 5, 'started_on' => today()->toDateString(), 'location' => '']);
        Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->toDateString(), 'weight' => '1']);
        $before = DB::table('batches')->orderBy('id')->get()->toJson();
        $movements = DB::table('material_movements')->orderBy('id')->get()->toJson();
        $this->settings('simple');
        $this->get('/production/'.$batch->id)->assertOk()->assertDontSee('ROOM-A-01')->assertDontSee('Print QR label');
        $this->settings('advanced');
        $this->get('/production/'.$batch->id)->assertOk()->assertSee('ROOM-A-01')->assertSee('Room A');
        $this->get('/reports/production')->assertOk()->assertSee('Not recorded')->assertSee('Unknown')->assertSee('Reference not recorded');
        $this->assertSame($before, DB::table('batches')->orderBy('id')->get()->toJson());
        $this->assertSame($movements, DB::table('material_movements')->orderBy('id')->get()->toJson());
        $this->assertNull($legacy->fresh()->reference);
        $this->assertNull($legacy->fresh()->total_cost);
        $this->assertNull(Harvest::firstOrFail()->batch_id);
        $this->assertSame(1, User::count());
    }

    public function test_mode_change_during_preview_preserves_approved_details_and_retry_deducts_once(): void
    {
        $this->settings('advanced');
        $quote = $this->preview(['reference' => 'APPROVED-REF', 'location' => 'Room B']);
        $this->settings('simple');
        $this->post('/production', ['quote' => $quote])->assertSessionHasNoErrors();
        $this->post('/production', ['quote' => $quote])->assertSessionHasNoErrors();
        $batch = Batch::firstOrFail();
        $this->assertSame('APPROVED-REF', $batch->reference);
        $this->assertSame('Room B', $batch->location);
        $this->assertDatabaseCount('batches', 1);
        $this->assertDatabaseCount('material_movements', 2);
        $this->get('/production')->assertDontSee('APPROVED-REF')->assertDontSee($batch->code);
        $this->settings('advanced');
        $this->get('/production')->assertSee('APPROVED-REF');
    }

    public function test_advanced_locations_batch_harvest_filters_and_cost_report_use_real_records(): void
    {
        $this->settings('advanced');
        $first = $this->production(['reference' => 'FIRST-REF', 'location' => 'Room A']);
        $second = $this->production(['reference' => 'SECOND-REF', 'location' => 'Room B']);
        foreach ([$first, $second] as $batch) {
            $this->post('/harvests', ['batch_id' => $batch->id, 'harvested_on' => today()->toDateString(), 'weight' => '1.5', 'cycle' => 1, 'request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $this->get('/production?location=Room%20A')->assertOk()->assertViewHas('batches', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $first->id);
        $this->get('/production?search=SECOND-REF')->assertViewHas('batches', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $second->id);
        $this->get('/production/create')->assertSee('value="Room A"', false)->assertSee('value="Room B"', false);
        $this->get('/harvests?batch_id='.$first->id)->assertViewHas('harvests', fn ($rows) => $rows->total() === 1 && $rows->first()->batch_id === $first->id);
        $this->get('/harvests?location=Room%20B')->assertViewHas('harvests', fn ($rows) => $rows->total() === 1 && $rows->first()->batch_id === $second->id);
        $this->get('/reports/production?location=Room%20A')->assertOk()->assertViewHas('batches', fn ($rows) => $rows->total() === 1 && $rows->first()->harvests_sum_weight == 1.5 && $rows->first()->total_cost == 10)->assertSee('FIRST-REF')->assertDontSee('SECOND-REF');
        $this->get('/reports/production?from='.today()->toDateString().'&to='.today()->subDay()->toDateString())->assertSessionHasErrors('to');
    }

    public function test_label_flags_and_report_authorization_are_enforced_on_backend(): void
    {
        $this->settings('advanced', ['qr_printing' => false, 'batch_tracking' => true]);
        $batch = $this->production();
        $url = '/production/'.$batch->id.'/label';
        $this->get($url)->assertForbidden();
        $this->settings('advanced', ['qr_printing' => true]);
        $this->get($url)->assertOk()->assertSee('data-qr="'.route('production.show', $batch).'"', false);
        $this->settings('advanced', ['batch_tracking' => false]);
        $this->get($url)->assertForbidden();
        $this->assertFalse($this->farm->fresh()->qr_printing);
        Role::where('name', 'manager')->update(['is_active' => true]);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/reports/production')->assertForbidden();
        $this->get('/production')->assertOk();
        $this->actingAs($this->owner);
        $this->settings('simple');
        $this->assertTrue(Role::find('manager')->is_active);
        $this->assertTrue($manager->fresh()->is_active);
        $this->actingAs($manager)->get('/production')->assertOk();
    }
}
