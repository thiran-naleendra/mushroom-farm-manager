<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access-section', 'sales');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function returnItems(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->isOwner();
    }
}
