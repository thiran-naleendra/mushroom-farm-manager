<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FarmSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['role' => 'owner'])->save();
        $this->farm = Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple', 'currency' => 'LKR']);
        $this->actingAs($this->owner);
    }

    private function settings(array $overrides = []): array
    {
        return array_replace(['name' => 'My Farm', 'mode' => 'advanced', 'currency' => 'LKR'], $overrides);
    }

    public function test_settings_are_saved_and_applied_to_forms_without_fake_varieties(): void
    {
        $this->assertNull($this->farm->mushroom_varieties);
        $this->get('/settings')->assertOk()->assertSee('Farm profile')->assertSee('Enabled roles')->assertSee('Regional preferences');
        $this->put('/settings', $this->settings([
            'address' => 'Farm Road', 'phone' => '+94 123456789', 'email' => 'farm@example.com',
            'timezone' => 'Asia/Colombo', 'batch_tracking' => true, 'qr_printing' => true, 'grocery_sales' => true,
            'mushroom_varieties' => " Oyster \n\nShiitake ", 'enabled_roles' => ['owner', 'manager'],
        ]))->assertRedirect('/settings')->assertSessionHasNoErrors();
        $farm = $this->farm->fresh();
        $this->assertSame(['Oyster', 'Shiitake'], $farm->mushroom_varieties);
        $this->assertSame('Farm Road', $farm->address);
        $this->assertSame('farm@example.com', $farm->email);
        $this->assertSame('+94 123456789', $farm->phone);
        $this->assertTrue($farm->grocery_sales);
        $this->assertTrue(Role::find('manager')->is_active);
        $this->assertFalse(Role::find('worker')->is_active);
        $this->assertTrue(Role::find('owner')->is_active);
        $this->get('/recipes/create')->assertOk()->assertSee('value="Shiitake"', false);
        $this->get('/settings')->assertSee('farm@example.com');
    }

    public function test_unauthorized_users_cannot_read_or_change_settings(): void
    {
        Role::where('name', 'manager')->update(['is_active' => true]);
        $manager = User::factory()->create();
        $manager->forceFill(['role' => 'manager'])->save();
        $this->actingAs($manager)->get('/settings')->assertForbidden();
        $this->put('/settings', $this->settings())->assertForbidden();
        $this->assertSame('Farm', $this->farm->fresh()->name);
    }

    public function test_invalid_settings_and_duplicate_varieties_are_rejected_atomically(): void
    {
        $this->put('/settings', $this->settings(['timezone' => 'Fake/Zone', 'email' => 'invalid', 'currency' => 'INVALID', 'enabled_roles' => ['administrator']]))->assertSessionHasErrors(['timezone', 'email', 'currency', 'enabled_roles.0']);
        $this->put('/settings', $this->settings(['mushroom_varieties' => "Oyster\noyster", 'enabled_roles' => ['manager']]))->assertSessionHasErrors('mushroom_varieties');
        $this->assertSame('Farm', $this->farm->fresh()->name);
        $this->assertFalse(Role::find('manager')->is_active);
        $this->put('/settings', $this->settings(['mushroom_varieties' => str_repeat('x', 101)]))->assertSessionHasErrors('mushroom_varieties');
    }

    public function test_logo_upload_replacement_removal_and_authenticated_delivery(): void
    {
        $this->post('/settings', $this->settings(['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('logo.png', 100, 100)]))->assertSessionHasNoErrors();
        $first = $this->farm->fresh()->logo_path;
        Storage::disk('local')->assertExists($first);
        $this->get('/farm/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/settings', $this->settings(['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('new.png')]))->assertSessionHasNoErrors();
        $second = $this->farm->fresh()->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->post('/logout');
        $this->get('/farm/logo')->assertRedirect('/login');
        $this->actingAs($this->owner)->put('/settings', $this->settings(['remove_logo' => true]))->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($second);
        $this->get('/farm/logo')->assertNotFound();
    }

    public function test_unsafe_oversized_logos_are_rejected_and_failed_save_cleans_upload(): void
    {
        foreach ([UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'), UploadedFile::fake()->image('large.png')->size(2049), UploadedFile::fake()->image('wide.png', 2001, 1)] as $logo) {
            $this->post('/settings', $this->settings(['_method' => 'PUT', 'logo' => $logo]))->assertSessionHasErrors('logo');
        }
        $this->post('/settings', $this->settings(['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('ok.png'), 'mushroom_varieties' => "Oyster\nOyster"]))->assertSessionHasErrors('mushroom_varieties');
        $this->assertNull($this->farm->fresh()->logo_path);
        $this->assertSame([], Storage::disk('local')->allFiles('farm-logos'));
    }

    public function test_feature_flags_hide_batch_codes_and_block_qr_routes_without_losing_records(): void
    {
        $batch = Batch::create(['code' => 'PRIVATE-CODE', 'variety' => 'Oyster', 'location' => 'Room A', 'bags' => 10, 'started_on' => today()->toDateString()]);
        $this->put('/settings', $this->settings(['batch_tracking' => false, 'qr_printing' => true]))->assertSessionHasNoErrors();
        $this->get('/production')->assertDontSee('PRIVATE-CODE')->assertDontSee('Print QR label');
        $this->get('/production/'.$batch->id.'/label')->assertForbidden();
        $this->assertFalse($this->farm->fresh()->qr_printing);
        $this->put('/settings', $this->settings(['batch_tracking' => true, 'qr_printing' => true]))->assertSessionHasNoErrors();
        $this->get('/production')->assertSee('PRIVATE-CODE')->assertSee('Print QR label');
        $this->get('/production/'.$batch->id.'/label')->assertOk();
        $this->put('/settings', $this->settings(['mode' => 'simple']))->assertSessionHasNoErrors();
        $this->get('/production')->assertDontSee('PRIVATE-CODE');
        $this->get('/production/'.$batch->id.'/label')->assertForbidden();
        $this->assertDatabaseCount('batches', 1);
    }

    public function test_navigation_is_authorized_and_has_no_dead_module_links(): void
    {
        $response = $this->get('/');
        foreach (config('navigation') as $item) {
            $response->assertSee($item['label']);
        }
        $response->assertSee('href="http://localhost/reports/analytics"', false)->assertSee('href="http://localhost/expenses"', false)->assertSee('href="http://localhost/inventory"', false)->assertSee('Open navigation')->assertSee('Collapse sidebar')->assertSee('User menu');
        Role::where('name', 'worker')->update(['is_active' => true]);
        $worker = User::factory()->create();
        $worker->forceFill(['can_work_harvest' => true])->save();
        $this->actingAs($worker)->get('/')->assertSee('Harvests')->assertDontSee('Manage users')->assertDontSee('href="http://localhost/settings"', false)->assertDontSee('href="http://localhost/sales"', false)->assertDontSee('Customers');
    }

    public function test_timezone_is_used_and_restored_after_request(): void
    {
        $before = date_default_timezone_get();
        $this->farm->update(['timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-10-15 13:00:00', 'UTC'));
        $this->get('/')->assertSee('Fri, 16 Oct 2026');
        $this->assertSame($before, date_default_timezone_get());
        $this->travelBack();
    }
}
