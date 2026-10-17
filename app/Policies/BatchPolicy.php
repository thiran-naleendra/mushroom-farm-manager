<?php

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;

class BatchPolicy
{
    public function view(User $user, Batch $batch): bool
    {
        return $user->can('access-section', 'production');
    }

    public function correct(User $user, Batch $batch): bool
    {
        return $this->view($user, $batch) && in_array($user->role, ['owner', 'manager']);
    }

    public function void(User $user, Batch $batch): bool
    {
        return $user->isOwner();
    }

    public function viewCosts(User $user, Batch $batch): bool
    {
        return $this->correct($user, $batch);
    }
}
