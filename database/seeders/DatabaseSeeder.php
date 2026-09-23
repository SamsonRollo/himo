<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events must stay enabled here: ServiceCategory/ServiceRequest
     * rely on them for created_by tracking, status-history writes, and the
     * completion business rule, all of which HimoDemoDataSeeder exercises.
     */
    public function run(): void
    {
        $this->call([
            FacilitiesRoleSeeder::class,
            HimoDemoDataSeeder::class,
        ]);

        // Account role synchronization happens in the demo seeder. Reset the
        // shared permission cache only after every seeder has finished so a
        // freshly seeded Super Admin is never served stale role grants.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
