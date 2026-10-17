<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access-section', 'purchases');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->isOwner();
    }
}
