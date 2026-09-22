<?php

namespace Tests\Feature\Facilities;

use App\Enums\ServiceRequestStatus as Status;
use App\Filament\Auth\Pages\Login;
use App\Filament\Resources\ServiceCategories\Pages\CreateServiceCategory;
use App\Filament\Resources\ServiceCategories\Pages\EditServiceCategory;
use App\Filament\Resources\ServiceRequests\Pages\ListServiceRequests;
use App\Filament\Resources\ServiceRequests\Pages\ViewServiceRequest;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole;
use Database\Seeders\FacilitiesDemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class BoundaryTest extends FacilitiesTestCase
{
    public static function incompleteCompletionCases(): array
    {
        return [
            'missing assignment' => [false, 'Work finished.'],
            'missing note' => [true, null],
            'blank note' => [true, " \t\n "],
        ];
    }

    #[DataProvider('incompleteCompletionCases')]
    public function test_completion_requires_both_assignment_and_note(bool $assigned, ?string $note): void
    {
        $owner = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();
        // Simulate inconsistent legacy data in the isolated test database.
        DB::table('service_requests')->where('id', $request->id)->update([
            'status' => Status::ForConfirmation->value,
            'assigned_to' => $assigned ? $staff->id : null,
            'completion_note' => $note,
        ]);
        try {
            app(ServiceRequestWorkflow::class)->complete($request);
            $this->fail('Incomplete completion accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('completion_note', $exception->errors());
        }
        $this->assertSame(Status::ForConfirmation, $request->fresh()->status);
        $this->assertNull($request->fresh()->completed_at);
        $this->assertSame(1, $request->statusHistories()->count());
    }

    public function test_only_assigned_staff_can_work_and_only_owner_or_supervisor_can_review(): void
    {
        $owner = User::factory()->create()->assignRole('requester');
        $otherOwner = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $otherStaff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();
        $workflow = app(ServiceRequestWorkflow::class);
        $this->actingAs($supervisor);
        try {
            $workflow->assign($request, $owner->id);
            $this->fail('Assigned a non-staff user.');
        } catch (ValidationException) {
            $this->assertNull($request->fresh()->assigned_to);
        }
        $request = $workflow->assign($request, $staff->id);
        foreach ([$owner, $otherStaff, $supervisor] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('start', $request));
        }
        $this->actingAs($otherStaff);
        try {
            $workflow->start($request);
            $this->fail('Unassigned staff started work.');
        } catch (AuthorizationException) {
            $this->assertSame(Status::Assigned, $request->fresh()->status);
        }
        $this->actingAs($staff);
        $request = $workflow->start($request);
        $request = $workflow->submitForConfirmation($request, 'Fixed.');
        foreach ([$otherOwner, $staff, $otherStaff] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('complete', $request));
            $this->assertFalse(Gate::forUser($user)->allows('returnForCorrection', $request));
        }
        $this->actingAs($supervisor);
        $request = $workflow->complete($request);
        foreach (['assign', 'start', 'updateWork', 'submitForConfirmation', 'returnForCorrection', 'complete', 'update', 'delete'] as $ability) {
            $this->assertFalse(Gate::forUser($supervisor)->allows($ability, $request));
        }
    }

    public function test_category_crud_is_authorized_and_soft_deleted_through_filament(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $owner = User::factory()->create()->assignRole('requester');
        $this->actingAs($owner);
        Livewire::test(CreateServiceCategory::class)->assertForbidden();
        $this->actingAs($supervisor);
        Livewire::test(CreateServiceCategory::class)->fillForm(['name' => 'Electrical', 'is_active' => true])->call('create')->assertHasNoFormErrors();
        $category = ServiceCategory::sole();
        $this->assertSame($supervisor->id, $category->created_by);
        Livewire::test(EditServiceCategory::class, ['record' => $category->id])
            ->fillForm(['description' => 'Electrical repairs'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($supervisor->id, $category->fresh()->updated_by);
        Livewire::test(EditServiceCategory::class, ['record' => $category->id])->callAction('delete');
        $this->assertNull(ServiceCategory::find($category->id));
        $this->assertTrue(ServiceCategory::withTrashed()->findOrFail($category->id)->trashed());
    }

    public function test_view_page_action_refreshes_status_and_history_is_not_editable(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create()->assignRole('requester'));
        $request = ServiceRequest::factory()->create();
        $staff = User::factory()->create()->assignRole('service_staff');
        $this->actingAs(User::factory()->create()->assignRole('service_supervisor'));
        Livewire::test(ViewServiceRequest::class, ['record' => $request->id])
            ->callAction('assign', ['assigned_to' => $staff->id])->assertHasNoActionErrors()->assertSee('Assigned');
        $history = $request->statusHistories()->first();
        $history->remarks = 'Tampered';
        $this->expectException(ValidationException::class);
        $history->save();
    }

    public function test_inactive_categories_are_rejected_and_ordinary_edits_do_not_duplicate_history(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('requester'));
        $request = ServiceRequest::factory()->create();
        $request->update(['description' => 'Corrected intake description']);
        $request->save();
        $this->assertSame(1, $request->statusHistories()->count());
        $category = ServiceCategory::factory()->create(['is_active' => false]);
        $this->expectException(ValidationException::class);
        app(ServiceRequestWorkflow::class)->submit(['service_category_id' => $category->id, 'location' => 'Room 2', 'description' => 'Issue']);
    }

    public function test_shield_role_editor_preserves_facilities_permissions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['ViewAny:Role', 'View:Role', 'Update:Role'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $admin = User::factory()->create()->assignRole('super_admin');
        $admin->givePermissionTo(['ViewAny:Role', 'View:Role', 'Update:Role']);
        $this->actingAs($admin);
        $role = Role::findByName('requester', 'web');
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        Livewire::test(EditRole::class, ['record' => $role->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($before, $role->fresh()->permissions->pluck('name')->sort()->values()->all());
    }

    /**
     * Requester-only workflow isolation (case study requirement 2): only a
     * requester (or admin) may create requests, and the "Cancel request"
     * action is available solely to the owner while Submitted.
     */
    public function test_create_and_cancel_are_requester_only(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create()->assignRole('requester');
        $staff = User::factory()->create()->assignRole('service_staff');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');

        foreach ([$staff, $supervisor] as $nonRequester) {
            $this->assertFalse(Gate::forUser($nonRequester)->allows('create', ServiceRequest::class));
        }
        $this->assertTrue(Gate::forUser($owner)->allows('create', ServiceRequest::class));

        $this->actingAs($owner);
        $request = ServiceRequest::factory()->create();

        foreach ([$staff, $supervisor] as $nonOwner) {
            $this->assertFalse(Gate::forUser($nonOwner)->allows('delete', $request));
        }
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $request));

        Livewire::test(ListServiceRequests::class)
            ->callTableAction('cancel', $request)->assertHasNoTableActionErrors();
        $this->assertTrue($request->fresh()->trashed());
    }

    public function test_demo_requester_can_log_in_using_the_existing_filament_login(): void
    {
        $this->seed(FacilitiesDemoSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Login::class)
            ->fillForm(['email' => 'facilities-requester@example.test', 'password' => 'facilities-demo-only'])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs(User::where('email', 'facilities-requester@example.test')->sole());
    }
}
