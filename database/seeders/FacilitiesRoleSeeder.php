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
        // Service Category permissions are deliberately excluded from $common:
        // Requester and Service Staff must never see or reach that resource.
        $common = ['ViewAny:ServiceRequest', 'View:ServiceRequest', 'View:ServiceAccomplishmentReport', 'View:ServiceRequestMetrics'];
        $categoryManagement = ['ViewAny:ServiceCategory', 'View:ServiceCategory', 'Create:ServiceCategory', 'Update:ServiceCategory', 'Delete:ServiceCategory'];
        // Supervisor/Super Admin operational dashboard widgets only; a
        // requester or staff member never sees institution-wide metrics.
        $dashboardWidgets = [
            'View:RequestStatusOverview', 'View:CategoryVolumeChart', 'View:RequestTrendChart',
            'View:StaffWorkloadWidget', 'View:RecentRequestsWidget', 'View:RecentlyCompletedWidget',
        ];
        // Read-only user listing + narrow availability updates only — a
        // Supervisor must never create, edit, deactivate, or change the
        // role of a user (that stays Create/Update/Delete:User, Super
        // Admin-only below).
        $supervisorUserAccess = ['ViewAny:User', 'View:User', 'Update:StaffStatus'];
        $roles = [
            'requester' => [...$common, 'Create:ServiceRequest', 'Update:ServiceRequest', 'Delete:ServiceRequest', 'Complete:ServiceRequest', 'Return:ServiceRequest'],
            'service_staff' => [...$common, 'Work:ServiceRequest'],
            'service_supervisor' => [...$common, ...$categoryManagement, ...$dashboardWidgets, ...$supervisorUserAccess, 'Assign:ServiceRequest', 'Complete:ServiceRequest', 'Return:ServiceRequest'],
        ];
        // Reserved for Super Admin alone: never merged into a domain role.
        $superAdminOnly = [
            'Create:User', 'Update:User', 'Delete:User',
            'Manage:SystemBackup',
        ];
        $guard = config('auth.defaults.guard', 'web');

        foreach (array_unique([...array_merge(...array_values($roles)), ...$superAdminOnly]) as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        foreach ($roles as $role => $permissions) {
            // Add only domain permissions; retain all pre-existing grants.
            Role::findOrCreate($role, $guard)->givePermissionTo($permissions);
        }

        Role::findOrCreate(config('filament-shield.super_admin.name'), $guard)
            ->givePermissionTo(array_unique([...array_merge(...array_values($roles)), ...$superAdminOnly]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
