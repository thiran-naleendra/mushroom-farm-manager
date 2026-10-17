<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Expense;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Asia/Colombo'));
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['role' => 'owner'])->save();
        $this->farm = Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->actingAs($this->owner);
    }

    private function batch(array $data = []): Batch
    {
        return Batch::create(array_replace(['code' => 'CODE-'.Batch::count(), 'variety' => 'Oyster', 'location' => 'Room A', 'bags' => 100, 'started_on' => '2026-09-01', 'stage' => 'incubating'], $data));
    }

    public function test_empty_dashboard_shows_zeroes_and_explicitly_untracked_metrics(): void
    {
        $this->get('/')->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['active_bags'] == 0 && $metrics['revenue'] == 0 && $metrics['fresh_kg'] == 0 && $metrics['packaged_stock'] === 0 && $metrics['outstanding'] == 0)
            ->assertSee('No production recorded yet.')->assertSee('No recent activities recorded.')->assertSee('No sales recorded in the last six months.')
            ->assertSee('Recorded customer accounts')->assertSee('No fresh-stock alert threshold configured.')->assertSee('No expected harvest dates in the next 14 days.');
    }

    public function test_kpis_sum_bags_weights_and_monthly_finances_without_double_counting(): void
    {
        $batch = $this->batch(['contaminated_bags' => 5]);
        $this->batch(['stage' => 'fruiting', 'bags' => 50, 'contaminated_bags' => 10]);
        $this->batch(['stage' => 'completed', 'bags' => 25, 'contaminated_bags' => 3]);
        Harvest::create(['batch_id' => $batch->id, 'harvested_on' => '2026-10-01', 'weight' => '12.345']);
        Harvest::create(['batch_id' => $batch->id, 'harvested_on' => '2026-09-01', 'weight' => '10.000']);
        Sale::create(['customer' => 'Buyer', 'sold_on' => '2026-10-16', 'weight' => '2.345', 'price_per_kg' => '100.00']);
        Sale::create(['customer' => 'Buyer', 'sold_on' => '2026-09-01', 'weight' => '1.000', 'price_per_kg' => '100.00']);
        Expense::create(['description' => 'Substrate', 'spent_on' => '2026-10-16', 'amount' => '34.50']);
        Expense::create(['description' => 'Old cost', 'spent_on' => '2026-09-01', 'amount' => '99.00']);
        $this->get('/')->assertOk()->assertViewHas('metrics', function ($metrics) {
            return $metrics['active_bags'] == 135 && $metrics['produced_bags'] == 175 && $metrics['contaminated_bags'] == 18
                && $metrics['harvested_kg'] == 22.345 && $metrics['fresh_kg'] == 19 && $metrics['revenue'] == 234.5 && $metrics['expenses'] == 34.5 && $metrics['profit'] == 200;
        })->assertSee('Select a month to view its sales')->assertDontSee($batch->code);
    }

    public function test_month_boundaries_and_chart_fill_missing_months_with_real_zeroes(): void
    {
        foreach (['2026-04-30' => 10, '2026-05-01' => 20, '2026-10-01' => 30, '2026-10-31' => 40, '2026-11-01' => 50] as $date => $price) {
            Sale::create(['customer' => 'Buyer', 'sold_on' => $date, 'weight' => 1, 'price_per_kg' => $price]);
        }
        $this->get('/')->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['revenue'] == 70)
            ->assertViewHas('chart', fn ($chart) => count($chart) === 6 && $chart[0]['label'] === 'May 2026' && $chart[0]['total'] == 20 && $chart[1]['total'] == 0 && $chart[5]['total'] == 70);
    }

    public function test_negative_profit_is_shown_and_expenses_are_deducted_once(): void
    {
        Expense::create(['description' => 'Cost', 'spent_on' => '2026-10-16', 'amount' => '125.25']);
        $this->get('/')->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['profit'] == -125.25)->assertSee('-125.25');
    }

    public function test_reminders_only_use_entered_active_dates_and_alert_threshold_is_inclusive(): void
    {
        $due = $this->batch(['expected_harvest_on' => '2026-10-16']);
        $last = $this->batch(['expected_harvest_on' => '2026-10-30']);
        $this->batch(['expected_harvest_on' => null]);
        $this->batch(['expected_harvest_on' => '2026-10-31']);
        $this->batch(['expected_harvest_on' => '2026-10-15']);
        $this->batch(['expected_harvest_on' => '2026-10-20', 'stage' => 'completed']);
        $this->batch(['expected_harvest_on' => '2026-10-20', 'contaminated_bags' => 100]);
        $this->farm->update(['fresh_stock_alert_kg' => '0.000']);
        $this->get('/')->assertOk()->assertViewHas('reminders', fn ($rows) => $rows->pluck('id')->all() === [$due->id, $last->id])->assertViewHas('lowStock', true)->assertSee('Fresh mushroom stock is low');
        Harvest::create(['batch_id' => $due->id, 'harvested_on' => '2026-10-16', 'weight' => 1]);
        $this->get('/')->assertViewHas('lowStock', false)->assertSee('No low-stock alerts.');
    }

    public function test_nonowners_never_receive_financial_data_or_financial_queries(): void
    {
        Expense::create(['description' => 'PRIVATE-EXPENSE', 'spent_on' => '2026-10-16', 'amount' => 9876]);
        foreach (['manager', 'worker'] as $role) {
            Role::where('name', $role)->update(['is_active' => true]);
            $user = User::factory()->create();
            $user->forceFill(['role' => $role, 'can_work_harvest' => true])->save();
            $this->actingAs($user);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get('/');
            $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
            DB::disableQueryLog();
            $response->assertOk()->assertViewHas('financial', false)->assertViewHas('chart', [])
                ->assertViewHas('metrics', fn ($metrics) => ! array_key_exists('revenue', $metrics) && ! array_key_exists('profit', $metrics) && ! array_key_exists('outstanding', $metrics))
                ->assertDontSee('Monthly sales revenue')->assertDontSee('Net profit')->assertDontSee('Customer outstanding balance')->assertDontSee('PRIVATE-EXPENSE')->assertDontSee('Sales chart');
            $this->assertStringNotContainsString('price_per_kg', $queries);
            $this->assertStringNotContainsString('from "expenses"', $queries);
        }
    }

    public function test_dashboard_queries_are_bounded_and_do_not_grow_with_batch_count(): void
    {
        $service = app(DashboardService::class);
        DB::enableQueryLog();
        $service->data($this->owner, $this->farm);
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 25; $i++) {
            $this->batch(['expected_harvest_on' => '2026-10-20']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $service->data($this->owner, $this->farm);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(13, $baseline);
        $this->assertLessThanOrEqual($baseline, $count);
        $this->assertCount(8, $data['activities']);
        $this->assertCount(6, $data['reminders']);
        $this->assertCount(1, $data['summary']);
    }

    public function test_contamination_and_expected_dates_can_be_recorded_with_validation(): void
    {
        $batch = $this->batch();
        $this->patch('/production/'.$batch->id.'/stage', ['stage' => 'fruiting', 'contaminated_bags' => 101])->assertSessionHasErrors('contaminated_bags');
        $this->patch('/production/'.$batch->id.'/stage', ['stage' => 'fruiting', 'expected_harvest_on' => '2026-08-31'])->assertSessionHasErrors('expected_harvest_on');
        $this->patch('/production/'.$batch->id.'/stage', ['stage' => 'fruiting', 'contaminated_bags' => 7, 'expected_harvest_on' => '2026-10-20', 'reason' => 'Recorded contamination and harvest plan'])->assertSessionHasNoErrors();
        $this->get('/')->assertViewHas('metrics', fn ($metrics) => $metrics['active_bags'] == 93 && $metrics['contaminated_bags'] == 7)->assertViewHas('reminders', fn ($rows) => $rows->first()->id === $batch->id);
    }
}
