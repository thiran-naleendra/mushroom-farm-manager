<?php

namespace App\Policies;

use App\Models\User;

class FarmPolicy
{
    public function viewFinancials(User $user): bool
    {
        return $user->isOwner();
    }

    public function accessSection(User $user, string $section): bool
    {
        if (! $user->canAccessApp()) {
            return false;
        }
        if ($user->isOwner()) {
            return true;
        }
        if ($section === 'reports') {
            return $user->role === 'manager' || $user->can_work_production || $user->can_work_harvest;
        }
        if ($user->role === 'manager') {
            return in_array($section, ['dashboard', 'inventory', 'purchases', 'production', 'cultivation', 'harvests', 'packaging', 'products', 'customers', 'sales']);
        }

        return $section === 'dashboard'
            || (in_array($section, ['production', 'cultivation']) && $user->can_work_production)
            || ($section === 'harvests' && $user->can_work_harvest);
    }
}
