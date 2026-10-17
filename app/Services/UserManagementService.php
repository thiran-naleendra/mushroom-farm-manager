<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function save(User $actor, array $data, ?User $target = null): User
    {
        return DB::transaction(function () use ($actor, $data, $target) {
            Farm::lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()->isOwner(), 403);
            $target = $target ? User::lockForUpdate()->findOrFail($target->id) : new User;
            if ($target->exists && $target->role === 'owner' && $target->is_active
                && ($data['role'] !== 'owner' || ! $data['is_active'])
                && User::where('role', 'owner')->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['is_active' => 'The last active Owner cannot be deactivated or assigned another role.']);
            }
            if (empty($data['password'])) {
                unset($data['password']);
            } else {
                $target->remember_token = Str::random(60);
            }
            if ($data['role'] !== 'worker') {
                $data['can_work_production'] = false;
                $data['can_work_harvest'] = false;
            }
            $target->forceFill($data)->save();

            return $target;
        });
    }

    public function setRoleActive(User $actor, string $name, bool $active): void
    {
        abort_unless(in_array($name, ['manager', 'worker']), 403);
        DB::transaction(function () use ($actor, $name, $active) {
            Farm::lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()->isOwner(), 403);
            $role = Role::where('name', $name)->lockForUpdate()->firstOrFail();
            $role->is_active = $active;
            $role->save();
        });
    }
}
