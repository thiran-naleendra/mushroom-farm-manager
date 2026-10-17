<?php

namespace App\Policies;

use App\Models\User;

class ReportPolicy
{
    public function view(User $user, string $report): bool
    {
        if (! $user->canAccessApp()) {
            return false;
        }
        if (in_array($report, ['sales', 'outstanding', 'costs', 'products', 'profits'], true)) {
            return $user->can('view-financials');
        }
        $section = match ($report) {
            'harvests' => 'harvests', 'production', 'damage' => 'production', 'consumption', 'stock' => 'inventory', default => null,
        };

        return $section !== null && $user->can('access-section', $section);
    }
}
