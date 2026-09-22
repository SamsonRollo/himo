<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FacilitiesRoleSeeder extends Seeder
{
    public function run(): void
    {
        $common = ['ViewAny:ServiceCategory', 'View:ServiceCategory', 'ViewAny:ServiceRequest', 'View:ServiceRequest', 'View:ServiceAccomplishmentReport', 'View:ServiceRequestMetrics'];
        $roles = [
            'requester' => [...$common, 'Create:ServiceRequest', 'Update:ServiceRequest', 'Delete:ServiceRequest', 'Complete:ServiceRequest', 'Return:ServiceRequest'],
            'service_staff' => [...$common, 'Work:ServiceRequest'],
            'service_supervisor' => [...$common, 'Create:ServiceCategory', 'Update:ServiceCategory', 'Delete:ServiceCategory', 'Assign:ServiceRequest', 'Complete:ServiceRequest', 'Return:ServiceRequest'],
        ];
        $guard = config('auth.defaults.guard', 'web');

        foreach (array_unique(array_merge(...array_values($roles))) as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        foreach ($roles as $role => $permissions) {
            // Add only domain permissions; retain all pre-existing grants.
            Role::findOrCreate($role, $guard)->givePermissionTo($permissions);
        }

        Role::findOrCreate(config('filament-shield.super_admin.name'), $guard)
            ->givePermissionTo(array_unique(array_merge(...array_values($roles))));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
