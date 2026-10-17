<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FarmTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $mode = 'simple'): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'owner'])->save();
        Farm::create(['owner_id' => $user->id, 'name' => 'My Farm', 'mode' => $mode, 'currency' => 'LKR']);

        return $user;
    }

    private function batch(): Batch
    {
        return Batch::create(['code' => 'MF-PRIVATE', 'variety' => 'Oyster', 'location' => 'Room A', 'bags' => 50, 'started_on' => today()->subDays(10)->toDateString()]);
    }

    public function test_setup_creates_one_farm_and_authenticates_owner(): void
    {
        $this->withoutVite();
        $this->get('/login')->assertOk()->assertSee('Your farm starts here');
        $this->post('/setup', ['farm_name' => 'My Farm', 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password', 'mode' => 'simple', 'currency' => 'LKR'])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertDatabaseCount('farms', 1);
        $this->post('/logout');
        $this->post('/setup', [])->assertForbidden();
        $this->assertDatabaseCount('farms', 1);
    }

    public function test_guests_and_other_users_cannot_access_farm_records(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->owner();
        $outsider = User::factory()->create();
        $outsider->forceFill(['is_active' => false])->save();
        $this->actingAs($outsider)->get('/')->assertRedirect('/login');
        $this->actingAs($outsider);
        $this->post('/expenses', ['description' => 'Bags', 'spent_on' => today()->toDateString(), 'amount' => 100])->assertRedirect('/login');
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_simple_mode_hides_codes_and_advanced_mode_preserves_records(): void
    {
        $this->withoutVite();
        $this->actingAs($this->owner());
        $this->batch();
        foreach (['/', '/production', '/harvests', '/sales', '/expenses', '/settings'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('MF-PRIVATE');
        }
        $this->put('/settings', ['name' => 'My Farm', 'mode' => 'advanced', 'currency' => 'LKR'])->assertSessionHasNoErrors();
        $this->get('/production')->assertOk()->assertSee('MF-PRIVATE');
        $this->assertDatabaseCount('batches', 1);
    }

    public function test_production_harvest_sale_and_expense_workflow(): void
    {
        $this->actingAs($this->owner());
        $batch = $this->batch();
        $this->post('/harvests', ['batch_id' => $batch->id, 'harvested_on' => today()->toDateString(), 'weight' => 10, 'request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post('/sales', ['customer' => 'Buyer', 'sold_on' => today()->toDateString(), 'weight' => 3, 'price_per_kg' => 500])->assertSessionHasNoErrors();
        $this->post('/expenses', ['description' => 'Substrate', 'spent_on' => today()->toDateString(), 'amount' => 200])->assertSessionHasNoErrors();
        $this->patch('/production/'.$batch->id.'/stage', ['stage' => 'fruiting', 'reason' => 'Ready for fruiting'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('batches', ['id' => $batch->id, 'stage' => 'fruiting']);
        $this->withoutVite()->get('/')->assertOk()->assertSee('1,300.00')->assertSee('7.000');
    }

    public function test_sales_cannot_exceed_stock_or_precede_harvest(): void
    {
        $this->actingAs($this->owner());
        $batch = $this->batch();
        Harvest::create(['batch_id' => $batch->id, 'harvested_on' => today()->toDateString(), 'weight' => 5]);
        $sale = ['customer' => 'Buyer', 'sold_on' => today()->toDateString(), 'weight' => 6, 'price_per_kg' => 500];
        $this->post('/sales', $sale)->assertSessionHasErrors('weight');
        $this->post('/sales', array_replace($sale, ['weight' => 2, 'sold_on' => today()->subDay()->toDateString()]))->assertSessionHasErrors('weight');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_invalid_harvests_and_completed_production_are_rejected(): void
    {
        $this->actingAs($this->owner());
        $batch = $this->batch();
        $data = ['batch_id' => $batch->id, 'harvested_on' => today()->subDays(11)->toDateString(), 'weight' => 1, 'request_id' => (string) Str::uuid()];
        $this->post('/harvests', $data)->assertSessionHasErrors('batch_id');
        $batch->update(['stage' => 'completed']);
        $this->post('/harvests', array_replace($data, ['harvested_on' => today()->toDateString()]))->assertSessionHasErrors('batch_id');
        $this->post('/production', ['bags' => -1])->assertSessionHasErrors('quote');
        $this->assertDatabaseCount('harvests', 0);
    }

    public function test_qr_labels_are_only_available_to_owner_in_advanced_mode(): void
    {
        $this->withoutVite();
        $owner = $this->owner();
        $batch = $this->batch();
        $url = '/production/'.$batch->id.'/label';
        $this->get($url)->assertRedirect('/login');
        $this->actingAs($owner)->get($url)->assertForbidden();
        Farm::first()->update(['mode' => 'advanced']);
        $this->get($url)->assertOk()->assertSee('MF-PRIVATE')->assertSee('data-qr', false);
        $this->actingAs(User::factory()->create())->get($url)->assertRedirect('/login');
    }

    public function test_backdated_sale_cannot_break_existing_stock_balances(): void
    {
        $this->actingAs($this->owner());
        $batch = $this->batch();
        Harvest::create(['batch_id' => $batch->id, 'harvested_on' => today()->subDays(3)->toDateString(), 'weight' => 5]);
        Harvest::create(['batch_id' => $batch->id, 'harvested_on' => today()->toDateString(), 'weight' => 5]);
        $sale = ['customer' => 'Buyer', 'sold_on' => today()->subDay()->toDateString(), 'weight' => 5, 'price_per_kg' => 500];
        $this->post('/sales', $sale)->assertSessionHasNoErrors();
        $this->post('/sales', array_replace($sale, ['sold_on' => today()->subDays(2)->toDateString()]))->assertSessionHasErrors('weight');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_login_and_logout(): void
    {
        $owner = $this->owner();
        $this->post('/login', ['email' => $owner->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
