<?php

namespace Tests\Feature\Facilities;

use App\Enums\UserStatus;
use App\Filament\Imports\UserImporter;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\ServiceRequestWorkflow;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Case study requirement 4: Super Admin CRUD, non-destructive deactivation
 * (never a hard delete), and CSV import duplicate/role-escalation guards.
 */
class UserManagementTest extends FacilitiesTestCase
{
    public function test_only_super_admin_manages_users(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');
        $requester = User::factory()->create()->assignRole('requester');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', User::class));
        foreach ([$requester, $supervisor] as $other) {
            $this->assertFalse(Gate::forUser($other)->allows('viewAny', User::class));
        }
    }

    public function test_hard_deleting_a_user_is_never_possible(): void
    {
        $user = User::factory()->create()->assignRole('requester');
        $this->expectException(ValidationException::class);
        $user->forceDelete();
    }

    public function test_deactivation_blocks_login_but_preserves_associations(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');
        $staff = User::factory()->create()->assignRole('service_staff');
        $requester = User::factory()->create()->assignRole('requester');
        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();
        $this->actingAs($admin);
        app(ServiceRequestWorkflow::class)->assign($request, $staff->id);

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $staff));
        $staff->forceFill(['status' => UserStatus::Inactive, 'deactivated_at' => now(), 'deactivated_by' => $admin->id])->save();

        $this->assertFalse($staff->fresh()->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame($staff->id, $request->fresh()->assigned_to);
        $this->assertNotNull(User::withTrashed()->find($staff->id));
    }

    public function test_super_admin_cannot_deactivate_self_or_the_last_active_admin(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');

        // Never self-deactivate, even as the only admin.
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $admin));

        $secondAdmin = User::factory()->create()->assignRole('super_admin');
        // Deactivating a fellow active admin is fine while another stays active.
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $secondAdmin));

        $secondAdmin->forceFill(['status' => UserStatus::Inactive])->save();
        // $admin is now the sole active super_admin; the guard covers this
        // even though only self-deactivation could reach zero from here.
        $this->assertFalse((new UserPolicy)->delete($admin, $admin));
    }

    public function test_csv_import_creates_updates_and_blocks_super_admin_role(): void
    {
        $admin = User::factory()->create()->assignRole('super_admin');
        $existing = User::factory()->create(['email' => 'staff@example.test'])->assignRole('service_staff');

        $import = Import::create([
            'file_name' => 'users.csv',
            'file_path' => 'imports/users.csv',
            'importer' => UserImporter::class,
            'total_rows' => 3,
            'user_id' => $admin->id,
        ]);

        $columnMap = ['name' => 'name', 'email' => 'email', 'role' => 'role'];

        // Create a new requester.
        $created = new UserImporter($import, $columnMap, ['updateExisting' => true]);
        $created(['name' => 'New Requester', 'email' => 'new-requester@example.test', 'role' => 'requester']);
        $newUser = User::where('email', 'new-requester@example.test')->sole();
        $this->assertTrue($newUser->hasRole('requester'));
        $this->assertSame(UserStatus::Active, $newUser->status);

        // Update the existing staff member's name via duplicate-by-email match.
        $updated = new UserImporter($import, $columnMap, ['updateExisting' => true]);
        $updated(['name' => 'Renamed Staff', 'email' => 'staff@example.test', 'role' => 'service_staff']);
        $this->assertSame('Renamed Staff', $existing->fresh()->name);

        // A row targeting super_admin's email must be rejected outright.
        $blocked = new UserImporter($import, $columnMap, ['updateExisting' => true]);
        try {
            $blocked(['name' => 'Hijack', 'email' => $admin->email, 'role' => 'service_supervisor']);
            $this->fail('CSV import was allowed to modify a Super Admin account.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
    }
}
