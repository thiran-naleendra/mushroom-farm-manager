<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = $this->account('owner');
        Farm::create(['name' => 'Farm', 'owner_id' => $this->owner->id, 'mode' => 'simple', 'currency' => 'LKR']);
    }

    private function account(string $role, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $user;
    }

    private function data(User $user, array $overrides = []): array
    {
        return array_replace([
            'name' => $user->name, 'email' => $user->email, 'role' => $user->role,
            'is_active' => $user->is_active, 'can_work_production' => false, 'can_work_harvest' => false,
        ], $overrides);
    }

    public function test_owner_only_farm_works_and_registration_is_closed(): void
    {
        $this->actingAs($this->owner)->get('/')->assertOk();
        $this->get('/users')->assertOk()->assertSee('Create user');
        $this->get('/users/'.$this->owner->id.'/edit')->assertOk()->assertSee('Save changes');
        $this->assertDatabaseCount('users', 1);
        $this->post('/logout');
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
        $this->post('/setup', [])->assertForbidden();
    }

    public function test_owner_can_create_update_and_deactivate_without_deleting_history(): void
    {
        $this->actingAs($this->owner);
        $this->post('/users', [
            'name' => 'Team Member', 'email' => 'member@example.com', 'role' => 'manager',
            'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
            'is_active' => true, 'can_work_production' => false, 'can_work_harvest' => false,
        ])->assertRedirect('/users')->assertSessionHasNoErrors();
        $user = User::where('email', 'member@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-secure-password', $user->password));
        $originalPassword = $user->password;
        $this->put('/users/'.$user->id, $this->data($user, ['name' => 'Updated Member', 'is_active' => false]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Member', 'is_active' => false]);
        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertDatabaseHas('farms', ['owner_id' => $this->owner->id]);
        $this->delete('/users/'.$user->id)->assertStatus(405);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_last_active_owner_cannot_be_disabled_demoted_or_role_disabled(): void
    {
        $this->actingAs($this->owner);
        $this->put('/users/'.$this->owner->id, $this->data($this->owner, ['is_active' => false]))->assertSessionHasErrors('is_active');
        $this->put('/users/'.$this->owner->id, $this->data($this->owner, ['role' => 'manager']))->assertSessionHasErrors('is_active');
        $this->patch('/roles/owner', ['is_active' => false])->assertForbidden();
        $this->assertTrue($this->owner->fresh()->isOwner());
        $this->assertTrue(Role::find('owner')->is_active);
        $inactiveOwner = $this->account('owner', false);
        $this->put('/users/'.$this->owner->id, $this->data($this->owner, ['is_active' => false]))->assertSessionHasErrors('is_active');
        $this->assertFalse($inactiveOwner->is_active);
    }

    public function test_an_owner_can_deactivate_another_owner_when_one_remains(): void
    {
        $second = $this->account('owner');
        $this->actingAs($second)->put('/users/'.$this->owner->id, $this->data($this->owner, ['is_active' => false]))->assertSessionHasNoErrors();
        $this->assertFalse($this->owner->fresh()->is_active);
        $this->assertTrue($second->isOwner());
        $this->get('/users')->assertOk();
        $this->get('/settings')->assertOk();
    }

    public function test_disabled_users_and_roles_cannot_login_or_continue_sessions(): void
    {
        $worker = $this->account('worker');
        $this->post('/login', ['email' => $worker->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($this->owner)->patch('/roles/worker', ['is_active' => true])->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['email' => $worker->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($worker);
        $worker->forceFill(['is_active' => false])->save();
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $worker->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $worker->forceFill(['is_active' => true])->save();
        $this->actingAs($worker);
        Role::where('name', 'worker')->update(['is_active' => false]);
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_manager_permissions_and_owner_routes_are_enforced(): void
    {
        Role::where('name', 'manager')->update(['is_active' => true]);
        $manager = $this->account('manager');
        $this->actingAs($manager)->get('/')->assertOk()->assertDontSee('Farm finances');
        foreach (['production', 'harvests', 'sales'] as $section) {
            $this->get('/'.$section)->assertOk();
        }
        foreach (['users', 'settings', 'expenses'] as $section) {
            $this->get('/'.$section)->assertForbidden();
        }
        $this->post('/users', [])->assertForbidden();
        $this->put('/users/'.$this->owner->id, $this->data($this->owner))->assertForbidden();
        $this->patch('/roles/worker', ['is_active' => true])->assertForbidden();
        $this->put('/settings', [])->assertForbidden();
        $this->post('/expenses', [])->assertForbidden();
        $this->post('/production', [])->assertSessionHasErrors('quote');
    }

    public function test_workers_only_access_assigned_tasks(): void
    {
        Role::where('name', 'worker')->update(['is_active' => true]);
        $worker = $this->account('worker');
        $this->actingAs($worker)->get('/')->assertOk();
        foreach (['production', 'harvests', 'sales', 'users', 'expenses', 'settings'] as $section) {
            $this->get('/'.$section)->assertForbidden();
        }
        $this->post('/production', [])->assertForbidden();
        $this->post('/harvests', [])->assertForbidden();
        $worker->forceFill(['can_work_harvest' => true])->save();
        $this->get('/harvests')->assertOk();
        $this->post('/harvests', [])->assertSessionHasErrors('variety');
        $this->get('/production')->assertForbidden();
        $worker->forceFill(['can_work_production' => true])->save();
        $this->get('/production')->assertOk();
        $this->post('/sales', [])->assertForbidden();
    }

    public function test_user_validation_and_password_changes(): void
    {
        $user = $this->account('worker');
        $this->actingAs($this->owner);
        $this->put('/users/'.$user->id, $this->data($user, ['email' => $this->owner->email]))->assertSessionHasErrors('email');
        $this->put('/users/'.$user->id, $this->data($user, ['role' => 'administrator']))->assertSessionHasErrors('role');
        $this->put('/users/'.$user->id, $this->data($user, ['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->put('/users/'.$user->id, $this->data($user, ['password' => 'another-secure-password', 'password_confirmation' => 'another-secure-password']))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('another-secure-password', $user->fresh()->password));
    }

    public function test_login_is_throttled_and_passwords_are_not_flashed(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $this->owner->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->assertNull(session()->getOldInput('password'));
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertStatus(429);
    }
}
