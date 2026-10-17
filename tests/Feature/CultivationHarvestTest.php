<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Role;
use App\Models\User;
use App\Services\FreshStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CultivationHarvestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['role' => 'owner'])->save();
        Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->batch = Batch::create(['code' => 'HIDDEN-BATCH-001', 'variety' => 'Oyster', 'bags' => 50, 'location' => 'Room A', 'started_on' => today()->subDays(10)->toDateString(), 'stage' => 'prepared']);
        $this->actingAs($this->owner);
    }

    private function harvest(array $overrides = []): array
    {
        return array_replace(['batch_id' => $this->batch->id, 'harvested_on' => today()->toDateString(), 'weight' => '10.000', 'rejected_weight' => '2.000', 'request_id' => (string) Str::uuid()], $overrides);
    }

    private function cultivation(array $overrides = []): array
    {
        return array_replace(['stage' => 'incubating', 'contaminated_bags' => 0, 'discarded_bags' => 0, 'reason' => 'Growth inspection'], $overrides);
    }

    public function test_saleable_harvest_credits_stock_once_and_rejected_weight_never_enters_stock(): void
    {
        $input = $this->harvest();
        $this->post('/harvests', $input)->assertRedirect('/harvests')->assertSessionHasNoErrors();
        $this->post('/harvests', $input)->assertSessionHasNoErrors();
        $harvest = Harvest::firstOrFail();
        $this->assertSame('10.000', $harvest->gross_weight);
        $this->assertSame('2.000', $harvest->rejected_weight);
        $this->assertSame('8.000', $harvest->weight);
        $this->assertSame(8000, $harvest->saleable_grams);
        $this->assertSame(8000, app(FreshStockService::class)->totals()['available']);
        $this->assertDatabaseCount('harvests', 1);
        $this->assertDatabaseCount('batch_audits', 1);
        $this->assertSame('harvesting', $this->batch->fresh()->stage);
        $this->assertSame(50, $this->batch->fresh()->availableBags());
        $this->get('/')->assertViewHas('metrics', fn ($metrics) => $metrics['harvested_kg'] == 10 && $metrics['fresh_kg'] == 8);
        $this->post('/sales', ['customer' => 'Buyer', 'sold_on' => today()->toDateString(), 'weight' => 9, 'price_per_kg' => 100])->assertSessionHasErrors('weight');
        $this->post('/sales', ['customer' => 'Buyer', 'sold_on' => today()->toDateString(), 'weight' => 8, 'price_per_kg' => 100])->assertSessionHasNoErrors();
        $this->assertSame(0, app(FreshStockService::class)->totals()['available']);
    }

    public function test_multiple_cycles_and_pickings_from_same_healthy_bags(): void
    {
        $this->post('/harvests', $this->harvest(['cycle' => 1]))->assertSessionHasNoErrors();
        $this->post('/harvests', $this->harvest(['cycle' => 1, 'weight' => 2, 'rejected_weight' => '0.500']))->assertSessionHasNoErrors();
        $this->post('/harvests', $this->harvest(['weight' => 1, 'rejected_weight' => 0]))->assertSessionHasNoErrors();
        $this->assertSame([1, 1, 2], Harvest::orderBy('id')->pluck('cycle')->all());
        $this->assertSame(10500, app(FreshStockService::class)->totals()['available']);
        $this->assertSame(50, $this->batch->fresh()->availableBags());
        $this->get('/cultivation/'.$this->batch->id)->assertOk()->assertSee('Cycle 2')->assertSee('Harvest cycles');
    }

    public function test_unlinked_quick_harvest_is_visible_and_all_rejected_harvest_adds_zero_stock(): void
    {
        $this->post('/harvests', $this->harvest(['batch_id' => null, 'variety' => 'Shiitake', 'weight' => 3, 'rejected_weight' => 3]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('harvests', ['batch_id' => null, 'variety' => 'Shiitake', 'saleable_grams' => 0, 'cycle' => null]);
        $this->assertSame(0, app(FreshStockService::class)->totals()['available']);
        $this->get('/harvests')->assertOk()->assertSee('Unlinked harvest')->assertSee('Shiitake');
        $this->get('/')->assertSee('Shiitake');
        $this->assertSame('prepared', $this->batch->fresh()->stage);
    }

    public function test_duplicate_reference_with_changed_harvest_payload_is_rejected(): void
    {
        $input = $this->harvest();
        $this->post('/harvests', $input)->assertSessionHasNoErrors();
        $this->post('/harvests', array_replace($input, ['weight' => 11]))->assertSessionHasErrors('weight');
        $this->assertDatabaseCount('harvests', 1);
        $this->assertSame(8000, app(FreshStockService::class)->totals()['available']);
    }

    public function test_invalid_harvest_quantities_variety_and_dates_roll_back(): void
    {
        $this->post('/harvests', $this->harvest(['weight' => -1]))->assertSessionHasErrors('weight');
        $this->post('/harvests', $this->harvest(['rejected_weight' => 11]))->assertSessionHasErrors('rejected_weight');
        $this->post('/harvests', $this->harvest(['variety' => 'Wrong variety']))->assertSessionHasErrors('variety');
        $this->post('/harvests', $this->harvest(['batch_id' => null]))->assertSessionHasErrors('variety');
        $this->post('/harvests', $this->harvest(['harvested_on' => today()->addDay()->toDateString()]))->assertSessionHasErrors('harvested_on');
        $this->post('/harvests', $this->harvest(['harvested_on' => today()->subDays(11)->toDateString()]))->assertSessionHasErrors('batch_id');
        $this->assertDatabaseCount('harvests', 0);
        $this->assertDatabaseCount('batch_audits', 0);
    }

    public function test_healthy_contaminated_and_discarded_counts_are_disjoint_and_audited(): void
    {
        $this->batch->forceFill(['sold_bags' => 5, 'damaged_bags' => 3])->save();
        $url = '/cultivation/'.$this->batch->id;
        $this->patch($url, $this->cultivation(['contaminated_bags' => 7, 'discarded_bags' => 10]))->assertSessionHasNoErrors();
        $this->assertSame(25, $this->batch->fresh()->availableBags());
        $this->patch($url, $this->cultivation(['contaminated_bags' => 0, 'discarded_bags' => 17, 'reason' => 'Discarded the contaminated bags']))->assertSessionHasNoErrors();
        $this->assertSame(25, $this->batch->fresh()->availableBags());
        $this->patch($url, $this->cultivation(['contaminated_bags' => 40, 'discarded_bags' => 10]))->assertSessionHasErrors('reason');
        $this->patch($url, $this->cultivation(['discarded_bags' => -1]))->assertSessionHasErrors('discarded_bags');
        $this->assertDatabaseCount('batch_audits', 2);
        $audit = $this->batch->audits()->latest('id')->first();
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame(7, $audit->before['contaminated_bags']);
        $this->assertSame(17, $audit->after['discarded_bags']);
        $this->get('/')->assertViewHas('metrics', fn ($metrics) => $metrics['active_bags'] == 25);
    }

    public function test_growth_stage_dates_follow_order_and_expected_harvest_can_be_future(): void
    {
        $url = '/cultivation/'.$this->batch->id;
        $this->patch($url, $this->cultivation([
            'prepared_on' => $this->batch->started_on, 'incubating_on' => today()->subDays(8)->toDateString(),
            'expected_harvest_on' => today()->addDays(7)->toDateString(), 'growth_notes' => 'Mycelium spreading',
        ]))->assertSessionHasNoErrors();
        $this->patch($url, $this->cultivation(['stage' => 'fruiting', 'fruiting_on' => today()->subDays(9)->toDateString()]))->assertSessionHasErrors('fruiting_on');
        $this->patch($url, $this->cultivation(['stage' => 'fruiting', 'fruiting_on' => today()->addDay()->toDateString()]))->assertSessionHasErrors('fruiting_on');
        $this->patch($url, $this->cultivation(['stage' => 'fruiting', 'fruiting_on' => today()->subDays(2)->toDateString()]))->assertSessionHasNoErrors();
        $this->post('/harvests', $this->harvest(['harvested_on' => today()->subDays(3)->toDateString()]))->assertSessionHasErrors('harvested_on');
        $this->post('/harvests', $this->harvest())->assertSessionHasNoErrors();
        $this->patch($url, $this->cultivation(['stage' => 'completed', 'completed_on' => today()->subDay()->toDateString()]))->assertSessionHasErrors('completed_on');
        $this->patch($url, $this->cultivation(['stage' => 'completed']))->assertSessionHasNoErrors();
        $this->assertSame(today()->toDateString(), $this->batch->fresh()->completed_on);
        $this->assertSame('Mycelium spreading', $this->batch->fresh()->growth_notes);
    }

    public function test_completed_voided_or_fully_discarded_production_cannot_be_harvested(): void
    {
        $this->batch->update(['stage' => 'completed']);
        $this->post('/harvests', $this->harvest())->assertSessionHasErrors('batch_id');
        $this->batch->forceFill(['stage' => 'fruiting', 'production_status' => 'voided'])->save();
        $this->post('/harvests', $this->harvest())->assertSessionHasErrors('batch_id');
        $this->batch->forceFill(['production_status' => 'legacy', 'discarded_bags' => 50])->save();
        $this->post('/harvests', $this->harvest())->assertSessionHasErrors('batch_id');
        $this->assertDatabaseCount('harvests', 0);
    }

    public function test_simple_mode_hides_codes_and_advanced_mode_displays_them(): void
    {
        $this->post('/harvests', $this->harvest())->assertSessionHasNoErrors();
        foreach (['/cultivation', '/cultivation/'.$this->batch->id, '/harvests'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('HIDDEN-BATCH-001');
        }
        Farm::first()->update(['mode' => 'advanced']);
        $this->get('/cultivation')->assertSee('HIDDEN-BATCH-001');
        $this->get('/harvests')->assertSee('HIDDEN-BATCH-001')->assertSee('Harvest cycle');
    }

    public function test_permissions_allow_only_authorized_cultivation_or_harvest_tasks(): void
    {
        Role::where('name', 'worker')->update(['is_active' => true]);
        $worker = User::factory()->create();
        $worker->forceFill(['can_work_harvest' => true])->save();
        $this->actingAs($worker)->get('/harvests')->assertOk();
        $this->post('/harvests', $this->harvest())->assertSessionHasNoErrors();
        $this->get('/cultivation')->assertForbidden();
        $this->patch('/cultivation/'.$this->batch->id, $this->cultivation())->assertForbidden();
        $worker->forceFill(['can_work_production' => true, 'can_work_harvest' => false])->save();
        $this->get('/cultivation')->assertOk();
        $this->patch('/cultivation/'.$this->batch->id, $this->cultivation(['stage' => 'prepared']))->assertSessionHasErrors('stage');
        $this->post('/harvests', $this->harvest())->assertForbidden();
    }

    public function test_exact_gram_arithmetic_and_waste_are_used_for_backdated_sales(): void
    {
        $this->post('/harvests', $this->harvest(['weight' => '0.111', 'rejected_weight' => 0]))->assertSessionHasNoErrors();
        $this->post('/harvests', $this->harvest(['weight' => '0.333', 'rejected_weight' => '0.111']))->assertSessionHasNoErrors();
        $this->assertSame(333, app(FreshStockService::class)->totals()['available']);
        $this->post('/sales', ['customer' => 'Buyer', 'sold_on' => today()->subDay()->toDateString(), 'weight' => '0.001', 'price_per_kg' => 100])->assertSessionHasErrors('weight');
        $this->post('/sales', ['customer' => 'Buyer', 'sold_on' => today()->toDateString(), 'weight' => '0.333', 'price_per_kg' => 100])->assertSessionHasNoErrors();
        $this->assertSame(0, app(FreshStockService::class)->totals()['available']);
    }

    public function test_history_filters_and_lone_end_date_work(): void
    {
        $this->post('/harvests', $this->harvest(['batch_id' => null, 'variety' => 'Shiitake']))->assertSessionHasNoErrors();
        $this->get('/harvests?variety=Shiitake')->assertOk()->assertViewHas('harvests', fn ($rows) => $rows->total() === 1);
        $this->get('/harvests?variety=Oyster')->assertOk()->assertViewHas('harvests', fn ($rows) => $rows->total() === 0);
        $this->get('/harvests?to='.today()->toDateString())->assertOk();
        $this->get('/harvests?from='.today()->toDateString().'&to='.today()->subDay()->toDateString())->assertSessionHasErrors('to');
    }
}
