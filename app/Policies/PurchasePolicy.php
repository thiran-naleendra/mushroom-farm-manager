<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access-section', 'purchases');
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function receive(User $user, Purchase $purchase): bool
    {
        return $this->viewAny($user);
    }

    public function pay(User $user, Purchase $purchase): bool
    {
        return $user->isOwner();
    }
}
