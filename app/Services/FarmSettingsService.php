<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class FarmSettingsService
{
    public function update(User $actor, array $data): void
    {
        $newPath = null;
        $oldPath = null;
        try {
            if (isset($data['logo'])) {
                $newPath = $data['logo']->store('farm-logos', 'local');
                if (! $newPath) {
                    throw ValidationException::withMessages(['logo' => 'The logo could not be saved. Please try again.']);
                }
            }
            DB::transaction(function () use ($actor, $data, $newPath, &$oldPath) {
                $farm = Farm::lockForUpdate()->firstOrFail();
                abort_unless($actor->fresh()->isOwner(), 403);
                $oldPath = $farm->logo_path;
                $roles = $data['enabled_roles'] ?? null;
                $remove = $data['remove_logo'] ?? false;
                unset($data['logo'], $data['enabled_roles'], $data['remove_logo']);
                if (array_key_exists('mushroom_varieties', $data)) {
                    $varieties = collect(preg_split('/\R/u', $data['mushroom_varieties'] ?? ''))->map(fn ($value) => trim($value))->filter(fn ($value) => $value !== '')->values();
                    if ($varieties->count() > 50 || $varieties->contains(fn ($value) => mb_strlen($value) > 100) || $varieties->map(fn ($value) => mb_strtolower($value))->duplicates()->isNotEmpty()) {
                        throw ValidationException::withMessages(['mushroom_varieties' => 'Enter up to 50 distinct varieties, one per line, with at most 100 characters each.']);
                    }
                    $data['mushroom_varieties'] = $varieties->all();
                }
                if ($newPath || $remove) {
                    $data['logo_path'] = $newPath ?: null;
                }
                $farm->update($data);
                if (! $farm->batch_tracking) {
                    $farm->update(['qr_printing' => false]);
                }
                if ($roles !== null) {
                    foreach (['manager', 'worker'] as $role) {
                        app(UserManagementService::class)->setRoleActive($actor, $role, in_array($role, $roles));
                    }
                }
                if ($oldPath === $farm->logo_path) {
                    $oldPath = null;
                }
            });
        } catch (Throwable $error) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $error;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }
    }
}
