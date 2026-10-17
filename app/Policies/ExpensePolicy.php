<?php

namespace App\Policies;

use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user): bool
    {
        return $user->isOwner();
    }
}
