<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Farm;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_retries_and_deleted_retries_never_duplicate_costs_or_audit_entries(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Farm::create(['owner_id' => $owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->actingAs($owner);
        $data = ['request_id' => (string) Str::uuid(), 'description' => 'Electricity', 'spent_on' => today()->toDateString(), 'amount' => '100', 'treatment' => 'operating'];
        $this->post('/expenses', $data)->assertSessionHasNoErrors();
        $this->post('/expenses', array_replace($data, ['amount' => '100.00']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_audits', 1);
        $this->post('/expenses', array_replace($data, ['amount' => '101']))->assertSessionHasErrors('request_id');
        $expense = Expense::firstOrFail();
        $this->delete('/expenses/'.$expense->id)->assertSessionHasNoErrors();
        $this->post('/expenses', $data)->assertSessionHasNoErrors();
        $this->assertSame(0, Expense::count());
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_audits', 2);
    }

    public function test_production_errors_do_not_expose_exception_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_review-error', fn () => throw new \RuntimeException('PRIVATE-DATABASE-DETAIL'));
        $this->get('/_review-error')->assertStatus(500)->assertDontSee('PRIVATE-DATABASE-DETAIL');
    }

    public function test_login_rejects_requests_without_a_csrf_token(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-password'])->assertStatus(419);
    }

    public function test_private_files_have_no_unauthenticated_route_and_mobile_navigation_has_controls(): void
    {
        $this->withoutVite();
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->get('/storage/farm-logos/private.png')->assertNotFound();
        $this->get('/farm/logo')->assertRedirect('/login');
        $owner = User::factory()->create(['role' => 'owner']);
        Farm::create(['owner_id' => $owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->actingAs($owner)->get('/')->assertOk()->assertSee('name="viewport"', false)->assertSee('Open navigation')->assertSee('Close navigation')->assertSee('aria-controls="navigation"', false);
    }

    public function test_component_forms_render_javascript_state_instead_of_literal_blade_directives(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create(['role' => 'owner']);
        Farm::create(['owner_id' => $owner->id, 'name' => 'Farm', 'mode' => 'advanced']);
        Product::create(['name' => 'Retail', 'sku' => 'RETAIL', 'variety' => 'Oyster', 'type' => 'packaged', 'pack_size_grams' => 200, 'is_active' => true]);
        $this->actingAs($owner);
        foreach (['/settings', '/products/create', '/sales/create', '/purchases/create', '/recipes/create', '/harvests'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('@js(')->assertSee('x-data=', false);
        }
    }

    public function test_deployment_check_detects_unsafe_configuration_without_printing_secrets(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://localhost', 'session.secure' => false]);
        $this->artisan('farm:check-deployment')->expectsOutputToContain('APP_DEBUG=false')->expectsOutputToContain('FAIL')->assertFailed();
    }
}
