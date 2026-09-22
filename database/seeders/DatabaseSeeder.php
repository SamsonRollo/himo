<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

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
    }
}
