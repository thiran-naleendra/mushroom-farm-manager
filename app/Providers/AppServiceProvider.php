<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\FarmPolicy;
use App\Policies\ReportPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-farm', fn (User $user) => $user->isOwner());
        Gate::define('access-section', [FarmPolicy::class, 'accessSection']);
        Gate::define('view-financials', [FarmPolicy::class, 'viewFinancials']);
        Gate::define('view-report', [ReportPolicy::class, 'view']);
    }
}
