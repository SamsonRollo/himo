<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the roles the panel logs into. Runs after `shield:generate`, so every
 * permission Shield discovered already exists by the time super_admin claims
 * them all.
 */
class ShieldRoleSeeder extends Seeder
{
    /**
     * The three case-study roles. Permissions start empty — assign them in
     * Shield's Roles UI once the resources exist.
     */
    public const DOMAIN_ROLES = ['requester', 'custodian', 'approver'];

    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $superAdmin = Role::findOrCreate(
            config('filament-shield.super_admin.name', 'super_admin'),
            $guard,
        );

        // super_admin holds every permission Shield knows about, so a newly
        // generated permission is picked up on the next seed run too.
        $superAdmin->syncPermissions(Permission::where('guard_name', $guard)->get());

        if (config('filament-shield.panel_user.enabled')) {
            Role::findOrCreate(config('filament-shield.panel_user.name', 'panel_user'), $guard);
        }

        foreach (self::DOMAIN_ROLES as $role) {
            Role::findOrCreate($role, $guard);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Artisan::call('cache:clear');
    }
}
