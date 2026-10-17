<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access-section', 'production');
    }

    public function view(User $user, Recipe $recipe): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && in_array($user->role, ['owner', 'manager']);
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->create($user);
    }
}
