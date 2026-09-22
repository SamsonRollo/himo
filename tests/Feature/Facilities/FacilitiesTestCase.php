<?php

namespace Tests\Feature\Facilities;

use Database\Seeders\FacilitiesRoleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class FacilitiesTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fresh, isolated in-memory connection; never reset an existing database.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(FacilitiesRoleSeeder::class);
    }
}
