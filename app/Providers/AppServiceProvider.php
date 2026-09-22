<?php

namespace App\Providers;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
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
        // Registers the Shield-generated policies that sit outside Laravel's
        // policy discovery — RolePolicy for Spatie's vendor Role model, and any
        // future resource whose policy Shield reports as "requires registration".
        //
        // Guarded because this provider boots during `composer install` on a
        // checkout where Shield has not been pulled in yet; without the check
        // that first install dies before it can install the package.
        if (class_exists(FilamentShield::class)) {
            FilamentShield::enforcePolicies();
        }
    }
}
