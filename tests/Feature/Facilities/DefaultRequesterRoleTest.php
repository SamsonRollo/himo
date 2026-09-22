<?php

namespace Tests\Feature\Facilities;

use App\Console\Commands\EnsureDefaultRequesterRole;
use App\Filament\Imports\UserImporter;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class DefaultRequesterRoleTest extends FacilitiesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_creating_a_user_through_the_admin_form_also_assigns_requester(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');
        $this->actingAs($admin);
        $supervisorRoleId = Role::where('name', 'service_supervisor')->value('id');

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New Supervisor', 'email' => 'new-supervisor@example.test', 'password' => 'password', 'roles' => [$supervisorRoleId]])
            ->call('create')->assertHasNoFormErrors();

        $created = User::where('email', 'new-supervisor@example.test')->sole();
        $this->assertTrue($created->hasRole('service_supervisor'));
        $this->assertTrue($created->hasRole('requester'));
    }

    public function test_editing_a_user_role_still_leaves_requester_attached(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');
        $staff = User::factory()->create()->assignRole('service_staff');
        $this->actingAs($admin);
        $supervisorRoleId = Role::where('name', 'service_supervisor')->value('id');

        Livewire::test(EditUser::class, ['record' => $staff->getRouteKey()])
            ->fillForm(['name' => $staff->name, 'email' => $staff->email, 'roles' => [$supervisorRoleId]])
            ->call('save')->assertHasNoFormErrors();

        $staff = $staff->fresh();
        $this->assertTrue($staff->hasRole('service_supervisor'));
        $this->assertFalse($staff->hasRole('service_staff'), 'the single-role select should still replace the functional role');
        $this->assertTrue($staff->hasRole('requester'));
    }

    public function test_csv_import_also_assigns_requester(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');
        $import = Import::create([
            'file_name' => 'users.csv', 'file_path' => 'imports/users.csv',
            'importer' => UserImporter::class, 'total_rows' => 1, 'user_id' => $admin->id,
        ]);

        $importer = new UserImporter($import, ['name' => 'name', 'email' => 'email', 'role' => 'role'], ['updateExisting' => true]);
        $importer(['name' => 'Imported Staff', 'email' => 'imported-staff@example.test', 'role' => 'service_staff']);

        $created = User::where('email', 'imported-staff@example.test')->sole();
        $this->assertTrue($created->hasRole('service_staff'));
        $this->assertTrue($created->hasRole('requester'));
    }

    public function test_backfill_command_gives_existing_users_requester_without_touching_anything_else(): void
    {
        $staff = User::factory()->create(['password' => 'unchanged-hash'])->assignRole('service_staff');
        $alreadyHasIt = User::factory()->create()->assignRole(['service_supervisor', 'requester']);
        $originalPassword = $staff->password;
        $originalName = $staff->name;

        $this->artisan(EnsureDefaultRequesterRole::class)->assertSuccessful();

        $staff = $staff->fresh();
        $this->assertTrue($staff->hasRole('service_staff'));
        $this->assertTrue($staff->hasRole('requester'));
        $this->assertSame($originalPassword, $staff->password);
        $this->assertSame($originalName, $staff->name);
        $this->assertTrue($alreadyHasIt->fresh()->hasRole('requester'));

        // Idempotent: a second run does nothing further and does not
        // duplicate the pivot row.
        $this->artisan(EnsureDefaultRequesterRole::class)->assertSuccessful();
        $this->assertSame(1, $staff->roles()->wherePivot('role_id', Role::where('name', 'requester')->value('id'))->count());
    }

    public function test_primary_role_prefers_the_functional_role_over_requester(): void
    {
        $supervisor = User::factory()->create()->assignRole(['service_supervisor', 'requester']);
        $this->assertSame('service_supervisor', $supervisor->primaryRole()?->name);

        $onlyRequester = User::factory()->create()->assignRole('requester');
        $this->assertSame('requester', $onlyRequester->primaryRole()?->name);
    }
}
