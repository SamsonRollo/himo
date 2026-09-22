<?php

namespace Tests\Feature\Facilities;

use App\Enums\StaffAvailabilityStatus as Availability;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\ActivityLog;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequestWorkflow;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class StaffAvailabilityTest extends FacilitiesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_new_service_staff_default_to_available(): void
    {
        $staff = User::factory()->create()->assignRole('service_staff');

        $this->assertSame(Availability::Available, $staff->staff_status);
    }

    public function test_supervisor_and_super_admin_can_change_staff_availability_and_it_is_logged(): void
    {
        foreach (['service_supervisor', 'super_admin'] as $role) {
            $actor = User::factory()->create()->assignRole($role);
            $staff = User::factory()->create()->assignRole('service_staff');
            $this->actingAs($actor);

            Livewire::test(ListUsers::class)
                ->callTableAction('updateStaffStatus', $staff, ['staff_status' => Availability::OnLeave->value])
                ->assertHasNoTableActionErrors();

            $this->assertSame(Availability::OnLeave, $staff->fresh()->staff_status);
            $log = ActivityLog::where('subject_type', $staff->getMorphClass())->where('subject_id', $staff->id)->where('action', 'staff_status_changed')->sole();
            $this->assertSame('available', $log->from_value);
            $this->assertSame('on_leave', $log->to_value);
            $this->assertSame($actor->id, $log->changed_by);
        }
    }

    public function test_requester_and_service_staff_cannot_reach_the_user_listing_to_change_availability(): void
    {
        foreach (['requester', 'service_staff'] as $role) {
            $actor = User::factory()->create()->assignRole($role);
            $this->actingAs($actor);

            $this->get(UserResource::getUrl('index'))->assertForbidden();
        }
    }

    public function test_own_availability_status_is_visible_to_the_staff_member_read_only(): void
    {
        $staff = User::factory()->create()->assignRole('service_staff');
        $staff->forceFill(['staff_status' => Availability::Busy])->save();
        $this->actingAs($staff);

        $html = view('filament.partials.user-identity')->render();

        $this->assertStringContainsString('Busy', $html);
    }

    public function test_status_gated_staff_cannot_be_assigned_regardless_of_schedule(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $staff = User::factory()->create()->assignRole('service_staff');
        $staff->forceFill(['staff_status' => Availability::OnLeave])->save();

        $this->actingAs($requester);
        ServiceCategory::factory()->create();
        $request = ServiceRequest::factory()->create();

        $this->actingAs($supervisor);
        try {
            app(ServiceRequestWorkflow::class)->assign($request, $staff->id);
            $this->fail('An On Leave staff member was assigned.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('On Leave', $exception->errors()['assigned_to'][0]);
        }
    }

    public function test_inactive_staff_can_never_be_assigned(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $staff = User::factory()->create()->assignRole('service_staff');
        $staff->forceFill(['staff_status' => Availability::Inactive])->save();

        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();

        $this->actingAs($supervisor);
        $this->expectException(ValidationException::class);
        app(ServiceRequestWorkflow::class)->assign($request, $staff->id);
    }

    public function test_busy_staff_can_still_be_assigned_a_non_overlapping_schedule(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $staff = User::factory()->create()->assignRole('service_staff');
        $staff->forceFill(['staff_status' => Availability::Busy])->save();

        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();

        $this->actingAs($supervisor);
        $request = app(ServiceRequestWorkflow::class)->assign($request, $staff->id);

        $this->assertSame($staff->id, $request->assigned_to);
    }

    public function test_available_staff_is_still_rejected_when_the_schedule_overlaps_an_existing_assignment(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $staff = User::factory()->create()->assignRole('service_staff');
        $this->assertSame(Availability::Available, $staff->staff_status);

        $this->actingAs($requester);
        $category = ServiceCategory::factory()->create();
        $first = ServiceRequest::factory()->create([
            'service_category_id' => $category->id,
            'needed_start_at' => '2026-02-01 09:00:00',
            'needed_end_at' => '2026-02-01 10:00:00',
        ]);
        $second = ServiceRequest::factory()->create([
            'service_category_id' => $category->id,
            'needed_start_at' => '2026-02-01 09:30:00',
            'needed_end_at' => '2026-02-01 10:30:00',
        ]);

        $this->actingAs($supervisor);
        app(ServiceRequestWorkflow::class)->assign($first, $staff->id);
        $this->expectException(ValidationException::class);
        app(ServiceRequestWorkflow::class)->assign($second, $staff->id);
    }

    public function test_manual_status_is_never_overwritten_automatically_by_completing_an_assignment(): void
    {
        $requester = User::factory()->create()->assignRole('requester');
        $supervisor = User::factory()->create()->assignRole('service_supervisor');
        $staff = User::factory()->create()->assignRole('service_staff');
        $staff->forceFill(['staff_status' => Availability::Busy])->save();

        $this->actingAs($requester);
        $request = ServiceRequest::factory()->create();
        $this->actingAs($supervisor);
        $request = app(ServiceRequestWorkflow::class)->assign($request, $staff->id);
        $this->actingAs($staff);
        $request = app(ServiceRequestWorkflow::class)->start($request);
        $request = app(ServiceRequestWorkflow::class)->submitForConfirmation($request, 'Done.');
        $this->actingAs($requester);
        app(ServiceRequestWorkflow::class)->complete($request);

        // No automatic Busy -> Available transition exists in this
        // application; the stored status is preserved as-is.
        $this->assertSame(Availability::Busy, $staff->fresh()->staff_status);
    }
}
