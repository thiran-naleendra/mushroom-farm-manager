<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\User;

class MaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access-section', 'inventory');
    }

    public function view(User $user, Material $material): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Material $material): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Material $material): bool
    {
        return $user->isOwner();
    }
}
